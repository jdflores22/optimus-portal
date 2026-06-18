<?php

namespace App\Service;

use App\Entity\Billing;
use App\Entity\Manifest;
use App\Entity\User;
use App\Entity\Enum\WorkflowState;
use Doctrine\ORM\EntityManagerInterface;

class BillingService implements BillingServiceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BillingDocumentGenerator $billingDocumentGenerator,
        private AuditService $auditService,
        private WorkflowOrchestrator $workflowOrchestrator,
        private ActivityLogService $activityLogService,
        private ManifestNotificationService $notificationService
    ) {
    }

    public function generateBilling(int $manifestId, array $chargeData, User $slStaff): Billing
    {
        $manifest = $this->entityManager->getRepository(Manifest::class)->find($manifestId);
        if (!$manifest) {
            throw new \InvalidArgumentException('Manifest not found');
        }

        // Validate workflow state
        if ($manifest->getWorkflowState() !== WorkflowState::BL_UPLOADED) {
            throw new \InvalidArgumentException('Manifest must be in bl_uploaded state to generate billing');
        }

        // Check if billing already exists
        if ($manifest->getBilling()) {
            throw new \InvalidArgumentException('Billing already exists for this manifest');
        }

        $billing = new Billing();
        $billing->setManifest($manifest);
        $billing->setFreightCharges($chargeData['freightCharges']);
        $billing->setThcCharges($chargeData['thcCharges']);
        $billing->setAdditionalCharges($chargeData['additionalCharges'] ?? null);
        $billing->setGeneratedBy($slStaff);

        // Set currency information
        $billing->setOriginalCurrency($chargeData['currency'] ?? 'PHP');
        
        if (isset($chargeData['exchangeRate'])) {
            $billing->setExchangeRate($chargeData['exchangeRate']);
        }
        
        if (isset($chargeData['freightChargesUsd'])) {
            $billing->setFreightChargesUsd($chargeData['freightChargesUsd']);
        }
        
        if (isset($chargeData['thcChargesUsd'])) {
            $billing->setThcChargesUsd($chargeData['thcChargesUsd']);
        }
        
        if (isset($chargeData['totalAmountUsd'])) {
            $billing->setTotalAmountUsd($chargeData['totalAmountUsd']);
        }

        // Compute total
        $billing->computeTotal();

        $this->entityManager->persist($billing);
        
        // Update manifest state using WorkflowOrchestrator
        $this->workflowOrchestrator->transitionState(
            $manifest,
            WorkflowState::BILLING_GENERATED,
            $slStaff,
            'Billing document generated'
        );
        
        $this->entityManager->flush();

        // Generate PDF via document template (falls back to legacy TCPDF when no active template)
        $pdfPath = $this->billingDocumentGenerator->generatePDF($billing);
        $billing->setPdfPath($pdfPath);
        $this->entityManager->flush();

        // Log billing generation
        $logData = [
            'manifest_id' => $manifestId,
            'manifest_number' => $manifest->getManifestNumber(),
            'total_amount' => $billing->getTotalAmount(),
            'currency' => $billing->getOriginalCurrency()
        ];
        
        if ($billing->getOriginalCurrency() === 'USD') {
            $logData['exchange_rate'] = $billing->getExchangeRate();
            $logData['total_amount_usd'] = $billing->getTotalAmountUsd();
        }
        
        $this->auditService->logAction(
            $slStaff,
            'billing_generation',
            'Billing',
            $billing->getId(),
            $logData
        );

        // Log to activity log for notifications
        $this->activityLogService->logBillingGeneration($slStaff, $billing, $manifest);

        // Notify broker and consignee about billing generation
        $this->notificationService->notifyBillingGenerated($manifest, $billing);

        return $billing;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function normalizeChargeData(array $input): array
    {
        $chargeData = [
            'freightCharges' => (float) ($input['freightCharges'] ?? 0),
            'thcCharges' => (float) ($input['thcCharges'] ?? 0),
            'additionalCharges' => $input['additionalCharges'] ?? null,
            'currency' => $input['currency'] ?? 'PHP',
            'exchangeRate' => isset($input['exchangeRate']) ? (float) $input['exchangeRate'] : null,
        ];

        if ($chargeData['currency'] === 'USD' && $chargeData['exchangeRate']) {
            $chargeData['freightChargesUsd'] = $chargeData['freightCharges'] / $chargeData['exchangeRate'];
            $chargeData['thcChargesUsd'] = $chargeData['thcCharges'] / $chargeData['exchangeRate'];

            $additionalUsd = 0.0;
            if (is_array($chargeData['additionalCharges'])) {
                foreach ($chargeData['additionalCharges'] as $charge) {
                    $additionalUsd += ((float) ($charge['amount'] ?? 0)) / $chargeData['exchangeRate'];
                }
            }

            $chargeData['totalAmountUsd'] = $chargeData['freightChargesUsd']
                + $chargeData['thcChargesUsd']
                + $additionalUsd;
        }

        return $chargeData;
    }

    public function computeFreightCharges(Manifest $manifest): float
    {
        // This is a simplified implementation
        // In a real system, this would be based on actual cargo data, weight, volume, etc.
        // For now, return a base rate
        return 10000.00;
    }

    public function computeTHC(string $containerSize, string $containerType): float
    {
        // THC rates based on container size and type
        $rates = [
            '20ft' => [
                'standard' => 2000.00,
                'refrigerated' => 3000.00,
                'open_top' => 2500.00,
            ],
            '40ft' => [
                'standard' => 3500.00,
                'refrigerated' => 5000.00,
                'open_top' => 4000.00,
            ],
            '40ft_hc' => [
                'standard' => 4000.00,
                'refrigerated' => 5500.00,
                'open_top' => 4500.00,
            ],
        ];

        return $rates[$containerSize][$containerType] ?? 2000.00;
    }

    public function getBillingByManifest(int $manifestId): ?Billing
    {
        return $this->entityManager->getRepository(Billing::class)
            ->findOneBy(['manifest' => $manifestId]);
    }

    public function getBillingById(int $billingId): ?Billing
    {
        return $this->entityManager->getRepository(Billing::class)->find($billingId);
    }

    public function regenerateBillingPdf(int $billingId, bool $markAsPaid = false): Billing
    {
        $billing = $this->getBillingById($billingId);
        
        if (!$billing) {
            throw new \InvalidArgumentException('Billing not found');
        }

        $pdfPath = $this->billingDocumentGenerator->generatePDF(
            $billing,
            $markAsPaid ? true : null
        );
        $billing->setPdfPath($pdfPath);
        $this->entityManager->flush();

        return $billing;
    }

    public function ensureBillingPdfIsCurrent(Billing $billing): Billing
    {
        $billingId = $billing->getId();
        if ($billingId === null) {
            return $billing;
        }

        $activeTemplate = $this->billingDocumentGenerator->getActiveBillingTemplate();
        if ($activeTemplate === null) {
            return $billing;
        }

        $currentHash = BillingDocumentGenerator::computeTemplateHash($activeTemplate);
        if ($billing->getPdfPath() === null || $billing->getPdfTemplateHash() !== $currentHash) {
            return $this->regenerateBillingPdf($billingId);
        }

        return $billing;
    }
}
