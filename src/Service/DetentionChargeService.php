<?php

namespace App\Service;

use App\Entity\EDORenewalRequest;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Billing;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for calculating and managing detention charges for expired eDOs
 * 
 * This service handles:
 * - Calculation of overdue days based on eDO expiration
 * - Calculation of detention charges using configurable rate schedules
 * - Generation of billing records for consignees and brokers
 * - Audit logging of all detention charge operations
 */
class DetentionChargeService implements DetentionChargeServiceInterface
{
    /**
     * Default detention rate per day in PHP
     * This can be overridden by shipping line specific configuration
     */
    private const DEFAULT_DETENTION_RATE_PER_DAY = 500.00;

    /**
     * Rate multipliers based on container size
     */
    private const CONTAINER_SIZE_MULTIPLIERS = [
        '20' => 1.0,
        '40' => 1.5,
        '45' => 1.75,
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditService $auditService,
        private ActivityLogService $activityLogService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function calculateOverdueDays(ElectronicDeliveryOrder $edo): int
    {
        $expirationDate = $edo->getExpiresAt();
        
        if ($expirationDate === null) {
            $this->logger->warning('eDO has no expiration date set', [
                'edo_id' => $edo->getId(),
                'edo_number' => $edo->getEdoNumber()
            ]);
            return 0;
        }

        $currentDate = new \DateTime();
        
        // If not yet expired, return 0
        if ($currentDate <= $expirationDate) {
            return 0;
        }

        // Calculate difference in days
        $interval = $currentDate->diff($expirationDate);
        $overdueDays = $interval->days;

        $this->logger->info('Calculated overdue days for eDO', [
            'edo_id' => $edo->getId(),
            'edo_number' => $edo->getEdoNumber(),
            'expiration_date' => $expirationDate->format('Y-m-d H:i:s'),
            'current_date' => $currentDate->format('Y-m-d H:i:s'),
            'overdue_days' => $overdueDays
        ]);

        return $overdueDays;
    }

    /**
     * {@inheritdoc}
     */
    public function calculateDetentionCharge(int $overdueDays, ElectronicDeliveryOrder $edo): float
    {
        if ($overdueDays <= 0) {
            return 0.0;
        }

        // Get base rate (could be configured per shipping line in the future)
        $baseRate = $this->getDetentionRate($edo);

        // Get container size multiplier
        $multiplier = $this->getContainerSizeMultiplier($edo);

        // Calculate total charge
        $detentionCharge = $overdueDays * $baseRate * $multiplier;

        $this->logger->info('Calculated detention charge', [
            'edo_id' => $edo->getId(),
            'edo_number' => $edo->getEdoNumber(),
            'overdue_days' => $overdueDays,
            'base_rate' => $baseRate,
            'multiplier' => $multiplier,
            'detention_charge' => $detentionCharge
        ]);

        return round($detentionCharge, 2);
    }

    /**
     * {@inheritdoc}
     */
    public function generateDetentionBilling(EDORenewalRequest $request): Billing
    {
        $expiredEdo = $request->getExpiredEdo();
        $manifest = $expiredEdo->getManifest();
        
        // Create billing record
        $billing = new Billing();
        // For detention billing, we don't set manifest_id to avoid unique constraint violation
        // since multiple detention billings can exist for the same manifest
        // $billing->setManifest($manifest);
        $billing->setBillingType('detention');
        $billing->setEdoRenewalRequest($request);
        $billing->setDetentionDays($request->getOverdueDays());
        $billing->setDetentionRate($this->getDetentionRate($expiredEdo));
        
        // Set charges - for detention billing, we put the charge in freight charges
        // and set THC to 0 since this is not a manifest billing
        $billing->setFreightCharges($request->getDetentionChargeAmount());
        $billing->setThcCharges(0.0);
        $billing->setAdditionalCharges(null);
        $billing->computeTotal();
        
        // Set the user who generated the billing (the broker who requested renewal)
        $billing->setGeneratedBy($request->getRequestedBy());
        
        // Persist the billing
        $this->entityManager->persist($billing);
        $this->entityManager->flush();

        // Log billing generation via AuditService
        $this->auditService->logAction(
            $request->getRequestedBy(),
            'detention_billing_generated',
            'Billing',
            $billing->getId(),
            [
                'billing_id' => $billing->getId(),
                'billing_type' => 'detention',
                'renewal_request_id' => $request->getId(),
                'expired_edo_id' => $expiredEdo->getId(),
                'expired_edo_number' => $expiredEdo->getEdoNumber(),
                'detention_days' => $request->getOverdueDays(),
                'detention_rate' => $billing->getDetentionRate(),
                'total_amount' => $billing->getTotalAmount(),
                'manifest_id' => $manifest->getId(),
                'manifest_number' => $manifest->getManifestNumber()
            ]
        );

        // Log activity
        $this->activityLogService->logActivity(
            $request->getRequestedBy(),
            'detention_billing_generated',
            'Billing',
            $billing->getId(),
            null,
            [
                'billing_id' => $billing->getId(),
                'amount' => $billing->getTotalAmount(),
                'detention_days' => $request->getOverdueDays()
            ],
            [
                'renewal_request_id' => $request->getId(),
                'expired_edo_number' => $expiredEdo->getEdoNumber()
            ]
        );

        $this->logger->info('Generated detention billing', [
            'billing_id' => $billing->getId(),
            'renewal_request_id' => $request->getId(),
            'expired_edo_id' => $expiredEdo->getId(),
            'detention_days' => $request->getOverdueDays(),
            'total_amount' => $billing->getTotalAmount()
        ]);

        return $billing;
    }

    /**
     * {@inheritdoc}
     */
    public function requiresDetentionCharges(EDORenewalRequest $request): bool
    {
        return $request->getOverdueDays() > 0;
    }

    /**
     * Get the detention rate for a specific eDO
     * 
     * This method can be extended to support shipping line specific rates
     * by querying configuration tables or parameters.
     * 
     * @param ElectronicDeliveryOrder $edo The eDO to get the rate for
     * @return float The detention rate per day
     */
    private function getDetentionRate(ElectronicDeliveryOrder $edo): float
    {
        // TODO: In the future, this could query a configuration table
        // to get shipping line specific detention rates
        // For now, we use the default rate
        
        return self::DEFAULT_DETENTION_RATE_PER_DAY;
    }

    /**
     * Get the container size multiplier for detention charge calculation
     * 
     * @param ElectronicDeliveryOrder $edo The eDO to get the multiplier for
     * @return float The multiplier based on container size
     */
    private function getContainerSizeMultiplier(ElectronicDeliveryOrder $edo): float
    {
        $container = $edo->getContainer();
        
        if ($container === null) {
            $this->logger->warning('eDO has no container assigned, using default multiplier', [
                'edo_id' => $edo->getId(),
                'edo_number' => $edo->getEdoNumber()
            ]);
            return 1.0;
        }

        $containerSize = $container->getContainerSize();
        
        if ($containerSize === null) {
            $this->logger->warning('Container has no size set, using default multiplier', [
                'edo_id' => $edo->getId(),
                'container_id' => $container->getId(),
                'container_number' => $container->getContainerNumber()
            ]);
            return 1.0;
        }

        // Extract numeric size from container size enum value
        $sizeValue = $containerSize->value;
        preg_match('/(\d+)/', $sizeValue, $matches);
        
        if (empty($matches)) {
            $this->logger->warning('Could not parse container size, using default multiplier', [
                'edo_id' => $edo->getId(),
                'container_size' => $sizeValue
            ]);
            return 1.0;
        }

        $sizeNumber = $matches[1];
        
        return self::CONTAINER_SIZE_MULTIPLIERS[$sizeNumber] ?? 1.0;
    }
}
