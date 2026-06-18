<?php

namespace App\Service;

use App\Entity\Billing;
use App\Entity\Broker;
use App\Entity\AccreditationSubmission;
use App\Entity\Consignee;
use App\Entity\Container;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Manifest;
use App\Entity\NOA;
use App\Entity\Payment;
use App\Entity\User;
use App\Entity\StaffUser;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\PaymentType;
use App\Entity\Enum\WorkflowState;
use App\Repository\AccreditationSubmissionRepository;
use Doctrine\ORM\EntityManagerInterface;

class DocumentTemplateContextBuilder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function buildNoaContext(NOA $noa): array
    {
        $consignee = $noa->getConsignee();
        $createdBy = $noa->getCreatedBy();

        $containerRows = [];
        foreach ($noa->getContainers() as $container) {
            $containerRows[] = [
                $container->getContainerNumber(),
                $container->getContainerType()->getName(),
                $container->getContainerSize()->getName(),
                number_format($container->getContainerSize()->getTeuValue(), 1),
            ];
        }

        return [
            'noa' => [
                'number' => $noa->getNoaNumber(),
                'bl_number' => $noa->getBlNumber(),
                'vessel_number' => $noa->getVesselNumber(),
                'eta' => $noa->getEta()->format('Y-m-d H:i'),
                'port_location' => $noa->getDischargeLocation(),
                'container_count' => (string) $noa->getContainers()->count(),
            ],
            'consignee' => [
                'name' => $this->resolveConsigneeName($consignee),
                'email' => $consignee->getEmail(),
            ],
            'generated' => [
                'date' => date('Y-m-d H:i:s'),
                'by' => $this->resolveUserDisplayName($createdBy),
            ],
            'company' => [
                'name' => $this->resolveCompanyName($noa),
            ],
            'containers' => [
                'table' => $containerRows,
            ],
        ];
    }

    public function buildManifestBlContext(NOA $noa, string $manifestNumber, ?Manifest $manifest = null): array
    {
        $context = $this->buildNoaContext($noa);

        $arrivalDate = $manifest?->getArrivalDate() ?? $noa->getEta();

        $context['manifest'] = [
            'number' => $manifest?->getManifestNumber() ?? $manifestNumber,
            'bl_number' => $manifest?->getBlNumber() ?? $noa->getBlNumber(),
            'vessel_name' => $manifest?->getVesselName() ?? $noa->getVesselNumber(),
            'voyage_number' => $manifest?->getVoyageNumber() ?? '',
            'arrival_date' => $arrivalDate->format('Y-m-d H:i'),
            'issue_date' => date('Y-m-d'),
            'container_count' => $context['noa']['container_count'] ?? (string) $noa->getContainers()->count(),
        ];

        return $context;
    }

    public function buildBillingContext(Billing $billing, ?bool $isPaid = null): array
    {
        $manifest = $billing->getManifest();
        if ($manifest === null) {
            throw new \InvalidArgumentException('Billing is not linked to a manifest.');
        }

        if ($isPaid === null) {
            $isPaid = $this->isManifestBillingPaid($manifest);
        }

        $shippingLine = null;
        try {
            $shippingLine = $manifest->getShippingLine();
        } catch (\Error) {
            // Manifest may not have shipping line initialized in partial fixtures.
        }

        $portalConfig = $shippingLine?->getPortalConfig() ?? [];
        $companyName = $portalConfig['branding']['companyName']
            ?? ($shippingLine?->getBrandName() ?? 'OPTIMUS Shipping Lines');
        $companyAddress = $portalConfig['contact']['address'] ?? 'Port of Manila, South Harbor, Manila, Philippines';
        $companyPhone = $portalConfig['contact']['phone'] ?? '';
        $companyEmail = $portalConfig['contact']['email'] ?? '';

        $broker = $manifest->getBroker();
        $brokerName = $broker instanceof Broker ? $broker->getFullName() : '';
        $brokerEmail = $broker?->getEmail() ?? '';

        $consignee = $manifest->getConsignee();
        $consigneeName = $consignee instanceof Consignee
            ? $this->resolveConsigneeName($consignee)
            : 'N/A';
        $consigneeEmail = $consignee?->getEmail() ?? '';
        $consigneeAddress = $consignee instanceof Consignee
            ? $this->resolveApplicantAddress($consignee, $manifest)
            : '';

        $createdAt = $billing->getCreatedAt();
        $dueDate = (clone $createdAt)->modify('+30 days');
        $isUsd = $billing->getOriginalCurrency() === 'USD';

        $freightPhp = (float) $billing->getFreightCharges();
        $thcPhp = (float) $billing->getThcCharges();
        $totalPhp = (float) $billing->getTotalAmount();
        $freightUsd = $billing->getFreightChargesUsd() !== null ? (float) $billing->getFreightChargesUsd() : null;
        $thcUsd = $billing->getThcChargesUsd() !== null ? (float) $billing->getThcChargesUsd() : null;
        $totalUsd = $billing->getTotalAmountUsd() !== null ? (float) $billing->getTotalAmountUsd() : null;

        $chargesTable = [];
        $additionalChargesTable = [];
        $additionalChargesTotalPhp = 0.0;
        $additionalChargesTotalUsd = 0.0;
        $itemNum = 1;
        $chargesTable[] = $this->buildChargeRow(
            $itemNum++,
            'Freight Charges',
            $freightPhp,
            $isUsd ? $freightUsd : null,
        );
        $chargesTable[] = $this->buildChargeRow(
            $itemNum++,
            'Terminal Handling Charges (THC)',
            $thcPhp,
            $isUsd ? $thcUsd : null,
        );

        if ($billing->getAdditionalCharges()) {
            foreach ($billing->getAdditionalCharges() as $charge) {
                $amountPhp = (float) ($charge['amount'] ?? 0);
                $amountUsd = $isUsd && $billing->getExchangeRate()
                    ? $amountPhp / (float) $billing->getExchangeRate()
                    : null;
                $additionalChargesTotalPhp += $amountPhp;
                if ($amountUsd !== null) {
                    $additionalChargesTotalUsd += $amountUsd;
                }

                $row = $this->buildChargeRow(
                    $itemNum++,
                    (string) ($charge['description'] ?? 'Additional Charge'),
                    $amountPhp,
                    $amountUsd,
                );
                $chargesTable[] = $row;
                $additionalChargesTable[] = [
                    (string) count($additionalChargesTable) + 1,
                    $row[1],
                    $row[2],
                    $row[3],
                ];
            }
        }

        $exchangeRateDisplay = '';
        if ($isUsd && $billing->getExchangeRate()) {
            $exchangeRateDisplay = '1 USD = P' . number_format((float) $billing->getExchangeRate(), 4);
        }

        $note = 'Payment due within 30 days.';
        if ($isUsd && $billing->getExchangeRate()) {
            $note .= ' Original charges in USD converted to PHP at rate: ' . $exchangeRateDisplay;
        }

        $amountPrimary = $isUsd && $totalUsd !== null
            ? '$' . number_format($totalUsd, 2)
            : 'P' . number_format($totalPhp, 2);
        $amountSecondary = $isUsd
            ? '(P' . number_format($totalPhp, 2) . ')'
            : '';

        $generatedBy = $billing->getGeneratedBy();

        return [
            'billing' => [
                'id' => (string) $billing->getId(),
                'invoice_number' => str_pad((string) $billing->getId(), 5, '0', STR_PAD_LEFT),
                'invoice_date' => $createdAt->format('M d, Y'),
                'due_date' => $dueDate->format('M d, Y'),
                'status' => $isPaid ? 'PAID' : 'UNPAID',
                'currency' => $billing->getOriginalCurrency(),
                'exchange_rate' => $billing->getExchangeRate() ?? '',
                'exchange_rate_display' => $exchangeRateDisplay,
                'freight_charges' => 'P' . number_format($freightPhp, 2),
                'freight_charges_usd' => $freightUsd !== null ? '$' . number_format($freightUsd, 2) : '',
                'thc_charges' => 'P' . number_format($thcPhp, 2),
                'thc_charges_usd' => $thcUsd !== null ? '$' . number_format($thcUsd, 2) : '',
                'additional_charges_total' => 'P' . number_format($additionalChargesTotalPhp, 2),
                'additional_charges_total_usd' => $isUsd
                    ? '$' . number_format($additionalChargesTotalUsd, 2)
                    : '',
                'additional_charges_count' => (string) count($additionalChargesTable),
                'total_amount' => 'P' . number_format($totalPhp, 2),
                'total_amount_usd' => $totalUsd !== null
                    ? '(Original: $' . number_format($totalUsd, 2) . ')'
                    : '',
                'total_amount_display' => 'P' . number_format($totalPhp, 2),
                'amount_primary' => $amountPrimary,
                'amount_secondary' => $amountSecondary,
                'amount_header' => trim($amountPrimary . ' ' . $amountSecondary),
                'note' => $note,
            ],
            'manifest' => [
                'number' => $manifest->getManifestNumber(),
                'bl_number' => $manifest->getBlNumber() ?? 'N/A',
            ],
            'consignee' => [
                'name' => $consigneeName,
                'email' => $consigneeEmail,
                'address' => $consigneeAddress,
            ],
            'broker' => [
                'name' => $brokerName,
                'email' => $brokerEmail,
            ],
            'company' => [
                'name' => $companyName,
                'address' => $companyAddress,
                'phone' => $companyPhone,
                'email' => $companyEmail,
            ],
            'generated' => [
                'date' => $createdAt->format('F d, Y h:i A'),
                'by' => $this->resolveBillingGeneratedByName($generatedBy),
            ],
            'charges' => [
                'table' => $chargesTable,
                'additional_table' => $additionalChargesTable,
            ],
        ];
    }

    public function buildOfficialReceiptContext(Payment $payment): array
    {
        $manifest = $payment->getManifest();
        $billing = $manifest->getBilling();
        if ($billing === null) {
            throw new \InvalidArgumentException('Billing not found for this payment manifest.');
        }

        $context = $this->buildBillingContext($billing, true);
        $paymentId = (int) $payment->getId();
        $receiptNumber = 'OR-' . str_pad((string) $paymentId, 8, '0', STR_PAD_LEFT);
        $validatedAt = $payment->getValidatedAt() ?? new \DateTime();
        $currency = $payment->getCurrency() ?? $billing->getOriginalCurrency();
        $amount = (float) $payment->getAmount();
        $amountFormatted = $currency === 'USD'
            ? '$' . number_format($amount, 2)
            : 'P' . number_format($amount, 2);

        $context['billing']['invoice_number'] = str_pad((string) $paymentId, 8, '0', STR_PAD_LEFT);
        $context['billing']['invoice_date'] = $billing->getCreatedAt()->format('M d, Y');
        $context['billing']['due_date'] = $validatedAt->format('M d, Y');
        $context['billing']['status'] = 'PAID';
        $context['billing']['note'] = sprintf(
            'Official receipt for final payment on billing #%s.',
            str_pad((string) $billing->getId(), 5, '0', STR_PAD_LEFT)
        );
        $context['receipt'] = [
            'number' => $receiptNumber,
            'date' => $validatedAt->format('M d, Y'),
            'amount' => $amountFormatted,
            'currency' => $currency,
            'payment_id' => (string) $paymentId,
        ];
        $context['generated'] = [
            'date' => $validatedAt->format('F d, Y h:i A'),
            'by' => $payment->getValidatedBy()
                ? $this->resolveBillingGeneratedByName($payment->getValidatedBy())
                : ($context['generated']['by'] ?? 'Accounting'),
        ];

        return $context;
    }

    /**
     * @param array<int, ElectronicDeliveryOrder> $edos
     * @return array<string, mixed>
     */
    public function buildEdoBulkContext(array $edos, ?User $generatedBy = null): array
    {
        if ($edos === []) {
            throw new \InvalidArgumentException('Cannot build eDO context for empty array');
        }

        $firstEdo = $edos[0];
        $manifest = $firstEdo->getManifest();
        $noa = $manifest->getNoa();
        $shippingLine = $firstEdo->getShippingLine();
        $consignee = $manifest->getConsignee();
        $broker = $manifest->getBroker();

        $portalConfig = $shippingLine->getPortalConfig() ?? [];
        $companyName = $portalConfig['branding']['companyName']
            ?? ($shippingLine->getBrandName() ?? 'OPTIMUS Shipping Line');

        $vesselName = $noa?->getVesselNumber() ?? $manifest->getVesselName() ?? 'N/A';
        $voyageNumber = $manifest->getVoyageNumber() ?? 'N/A';
        $vesselDisplay = trim($vesselName . ' / ' . $voyageNumber);

        $containerRows = [];
        $rowNum = 1;
        foreach ($edos as $edo) {
            $containerRows[] = $this->buildEdoContainerRow($edo, $rowNum++);
        }

        $printDate = new \DateTime();
        $generatorName = $this->resolveEdoGeneratorName($generatedBy, $firstEdo->getGeneratedByName());

        return [
            'platform' => [
                'brand' => 'OPTIMUS',
                'tagline' => 'MARITIME LOGISTICS PLATFORM',
            ],
            'generated' => [
                'date' => $printDate->format('d-M-Y H:i'),
                'datetime' => $printDate->format('Y-m-d H:i:s'),
                'by' => $generatorName,
            ],
            'staff' => [
                'name' => $generatorName,
                'role' => $generatedBy instanceof StaffUser ? 'SL Staff' : 'System',
            ],
            'edo' => array_merge([
                'status' => 'ELECTRONIC RELEASE',
                'document_title' => 'ELECTRONIC DELIVERY ORDER',
                'document_subtitle' => 'CONTAINER RELEASE ORDER',
                'port_directive' => 'Please release the above cargo container(s) to the Consignee/Broker/Hauler. '
                    . 'Free Demurrage time is valid until 2400H of the specified validity date. '
                    . 'Pre-advise notice is mandatory for container returns to MICT/ATI to prevent shut out fees.',
            ], [
                'generated_by' => $generatorName,
            ]),
            'signatures' => [
                'authorized_title' => 'AUTHORIZED REPRESENTATIVE',
                'authorized_company' => $companyName,
                'prepared_title' => 'PREPARED BY',
                'prepared_by' => $generatorName,
                'received_title' => 'DATE/TIME RECEIVED',
            ],
            'manifest' => [
                'number' => $manifest->getManifestNumber(),
                'bl_number' => $noa?->getBlNumber() ?? $manifest->getBlNumber() ?? 'N/A',
            ],
            'noa' => [
                'number' => $noa?->getNoaNumber() ?? 'N/A',
            ],
            'consignee' => [
                'name' => $consignee instanceof Consignee
                    ? strtoupper($this->resolveConsigneeName($consignee))
                    : 'N/A',
            ],
            'broker' => [
                'name' => $broker instanceof Broker ? $broker->getFullName() : 'N/A',
            ],
            'company' => [
                'name' => $companyName,
            ],
            'vessel' => [
                'name' => $vesselName,
                'voyage' => $voyageNumber,
                'display' => $vesselDisplay,
            ],
            'shipping' => [
                'line' => $companyName,
            ],
            'containers' => [
                'table' => $containerRows,
            ],
        ];
    }

    private function resolveEdoGeneratorName(?User $generatedBy, ?string $storedName): string
    {
        if ($generatedBy !== null) {
            return $this->resolveBillingGeneratedByName($generatedBy);
        }

        $storedName = trim((string) $storedName);
        if ($storedName !== '') {
            return $storedName;
        }

        return 'OPTIMUS SYSTEM';
    }

    /**
     * @return array<int, string>
     */
    private function buildEdoContainerRow(ElectronicDeliveryOrder $edo, int $rowNum): array
    {
        $container = $edo->getContainer();
        $returnLocation = $edo->getCyLocation() ?? 'N/A';

        if ($container instanceof Container) {
            $cyAllocation = $container->getCyAllocation();
            if ($cyAllocation !== null && $cyAllocation->getTerminal() !== null) {
                $returnLocation = $cyAllocation->getTerminal()->getName();
            }

            $containerNumber = $container->getContainerNumber();
            $containerSize = $container->getContainerSize()?->getName() ?? 'N/A';
            $containerType = $container->getContainerType()?->getCode() ?? 'N/A';
        } else {
            $containerNumber = 'N/A';
            $containerSize = 'N/A';
            $containerType = 'N/A';
        }

        $demurrageValidity = $edo->getExpiresAt() !== null
            ? $edo->getExpiresAt()->format('d-M-Y')
            : 'N/A';

        return [
            (string) $rowNum,
            $containerNumber,
            $containerSize,
            $containerType,
            '—',
            '—',
            '—',
            $edo->getEdoNumber(),
            $demurrageValidity,
            $returnLocation,
        ];
    }

    private function isManifestBillingPaid(Manifest $manifest): bool
    {
        $paidStates = [
            WorkflowState::PAYMENT_VERIFIED,
            WorkflowState::EDO_GENERATED,
            WorkflowState::EDO_RELEASED,
        ];

        if (in_array($manifest->getWorkflowState(), $paidStates, true)) {
            return true;
        }

        $verifiedPayment = $this->entityManager->getRepository(Payment::class)
            ->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.manifest = :manifest')
            ->andWhere('p.paymentType = :type')
            ->andWhere('p.status = :status')
            ->setParameter('manifest', $manifest)
            ->setParameter('type', PaymentType::FINAL_PAYMENT)
            ->setParameter('status', PaymentStatus::VERIFIED)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $verifiedPayment > 0;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    private function buildChargeRow(int $itemNum, string $description, float $amountPhp, ?float $amountUsd): array
    {
        $amountCell = $amountUsd !== null
            ? '$' . number_format($amountUsd, 2) . "\n" . 'P' . number_format($amountPhp, 2)
            : 'P' . number_format($amountPhp, 2);

        return [
            (string) $itemNum,
            $description,
            $amountCell,
            '0%',
        ];
    }

    private function resolveBillingGeneratedByName(User $user): string
    {
        if ($user instanceof StaffUser) {
            return $user->getFullName();
        }

        if ($user instanceof Consignee) {
            return $user->getBusinessName();
        }

        return $user->getEmail();
    }

    private function resolveApplicantAddress(User $applicant, Manifest $manifest): string
    {
        if ($applicant instanceof Broker) {
            $storedAddress = trim((string) ($applicant->getBusinessAddress() ?? ''));
            if ($storedAddress !== '') {
                return $storedAddress;
            }
        }

        /** @var AccreditationSubmissionRepository $repository */
        $repository = $this->entityManager->getRepository(AccreditationSubmission::class);

        $shippingLineId = null;
        try {
            $shippingLineId = $manifest->getShippingLine()?->getId();
        } catch (\Error) {
            // Manifest may not have shipping line initialized in partial fixtures.
        }

        if ($shippingLineId !== null) {
            $submission = $repository->findByApplicantAndShippingLine($applicant, $shippingLineId);
            $address = $this->extractAddressFromAccreditation($submission);
            if ($address !== '') {
                return $address;
            }
        }

        foreach ($repository->findByApplicant($applicant, $shippingLineId) ?: [] as $submission) {
            $address = $this->extractAddressFromAccreditation($submission);
            if ($address !== '') {
                return $address;
            }
        }

        return '';
    }

    private function extractAddressFromAccreditation(?AccreditationSubmission $submission): string
    {
        if ($submission === null) {
            return '';
        }

        $data = $submission->getSubmittedData();
        foreach (['business_address', 'address', 'office_address', 'registered_address'] as $key) {
            if (!empty($data[$key]) && is_string($data[$key])) {
                return trim($data[$key]);
            }
        }

        foreach ($data as $key => $value) {
            if (!is_string($key) || str_starts_with($key, '_')) {
                continue;
            }

            if (is_string($value) && str_contains(strtolower($key), 'address') && trim($value) !== '') {
                return trim($value);
            }

            if (is_array($value)) {
                $formatted = $this->formatStructuredAddress($value);
                if ($formatted !== '') {
                    return $formatted;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $addr
     */
    private function formatStructuredAddress(array $addr): string
    {
        if (
            !isset($addr['region_id'])
            && !isset($addr['city_id'])
            && !isset($addr['province_id'])
            && !isset($addr['barangay_id'])
            && !isset($addr['barangay'])
        ) {
            return '';
        }

        $street = trim((string) ($addr['street'] ?? ''));
        $parts = array_values(array_filter([
            trim((string) ($addr['barangay_name'] ?? $addr['barangay'] ?? '')),
            trim((string) ($addr['city_name'] ?? '')),
            trim((string) ($addr['province_name'] ?? $addr['province'] ?? '')),
            trim((string) ($addr['region_name'] ?? '')),
        ], static fn (string $part): bool => $part !== ''));

        if ($street === '' && $parts === []) {
            return '';
        }

        $location = implode(', ', $parts);

        if ($street !== '' && $location !== '') {
            return $street . ', ' . $location;
        }

        return $street !== '' ? $street : $location;
    }

    private function resolveConsigneeName(User $consignee): string
    {
        if ($consignee instanceof Consignee) {
            return $consignee->getBusinessName();
        }

        return $consignee->getEmail();
    }

    private function resolveUserDisplayName(User $user): string
    {
        if ($user instanceof Consignee) {
            return $user->getBusinessName();
        }

        return $user->getEmail();
    }

    private function resolveCompanyName(NOA $noa): string
    {
        $manifest = $this->entityManager->getRepository(Manifest::class)
            ->findPrimaryForNoa($noa);

        if ($manifest?->getShippingLine()) {
            return $manifest->getShippingLine()->getBrandName();
        }

        return 'OPTIMUS Shipping Lines';
    }
}
