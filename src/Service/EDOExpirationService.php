<?php

namespace App\Service;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\EDOStatus;
use App\Repository\ElectronicDeliveryOrderRepository;
use App\Utility\ExpirationCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for detecting and managing eDO expiration
 * 
 * Requirements: 4.1, 4.2, 4.3, 4.4, 4.5
 */
class EDOExpirationService implements EDOExpirationServiceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ElectronicDeliveryOrderRepository $edoRepository,
        private ExpirationCalculator $expirationCalculator,
        private EDONotificationServiceInterface $notificationService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Check if an eDO has expired
     * 
     * @param ElectronicDeliveryOrder $edo
     * @return bool True if eDO is expired, false otherwise
     */
    public function checkExpiration(ElectronicDeliveryOrder $edo): bool
    {
        // If no expiration date is set, it cannot be expired
        if ($edo->getExpiresAt() === null) {
            return false;
        }

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        
        // Check if current date is after expiration date
        return $now > $edo->getExpiresAt();
    }

    /**
     * Calculate the number of days an eDO has been expired
     * 
     * @param ElectronicDeliveryOrder $edo
     * @return int Number of expired days (0 if not expired)
     */
    public function calculateExpiredDays(ElectronicDeliveryOrder $edo): int
    {
        // If no expiration date is set, return 0
        if ($edo->getExpiresAt() === null) {
            return 0;
        }

        return $this->expirationCalculator->calculateExpiredDays($edo->getExpiresAt());
    }

    /**
     * Mark an eDO as expired and record the expiration timestamp
     * 
     * @param ElectronicDeliveryOrder $edo
     * @return void
     */
    public function markAsExpired(ElectronicDeliveryOrder $edo): void
    {
        try {
            // Check if already expired - don't send duplicate notifications
            $wasAlreadyExpired = $edo->getStatus() === EDOStatus::EXPIRED;
            
            // Update status to EXPIRED
            $edo->setStatus(EDOStatus::EXPIRED);
            
            // Calculate and set expired days
            $expiredDays = $this->calculateExpiredDays($edo);
            $edo->setExpiredDays($expiredDays);
            
            // Persist changes
            $this->entityManager->flush();
            
            // Only send notifications if status actually changed
            if (!$wasAlreadyExpired) {
                // TODO: Re-implement audit logging with general AuditService
                // Log expiration event
                
                // Send expiration notifications
                $this->notificationService->notifyExpiration($edo);
                
                $this->logger->info('eDO marked as expired', [
                    'edoId' => $edo->getId(),
                    'edoNumber' => $edo->getEdoNumber(),
                    'expiredDays' => $expiredDays,
                    'containerNumber' => $edo->getContainer()->getContainerNumber()
                ]);
            } else {
                $this->logger->debug('eDO already expired, skipping notification', [
                    'edoId' => $edo->getId(),
                    'edoNumber' => $edo->getEdoNumber()
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to mark eDO as expired', [
                'edoId' => $edo->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Process all active eDOs to detect and mark expired ones
     * Uses batch processing with pagination for performance
     * 
     * @param int $batchSize Number of eDOs to process per batch
     * @return int Number of eDOs marked as expired
     */
    public function processExpiredEDOs(int $batchSize = 100): int
    {
        $expiredCount = 0;
        $offset = 0;
        $totalProcessed = 0;

        try {
            $this->logger->info('Starting eDO expiration processing with batch size', [
                'batchSize' => $batchSize
            ]);

            do {
                // Use database-level date comparison for efficiency
                $expiredEDOs = $this->edoRepository->findExpiredEDOs($batchSize, $offset);
                $batchCount = count($expiredEDOs);
                
                if ($batchCount === 0) {
                    break;
                }

                $this->logger->debug('Processing batch', [
                    'offset' => $offset,
                    'batchSize' => $batchCount
                ]);

                foreach ($expiredEDOs as $edo) {
                    try {
                        $this->markAsExpired($edo);
                        $expiredCount++;
                    } catch (\Exception $e) {
                        // Log error but continue processing other eDOs
                        $this->logger->error('Failed to mark individual eDO as expired', [
                            'edoId' => $edo->getId(),
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                $totalProcessed += $batchCount;
                
                // Clear entity manager to free memory
                $this->entityManager->clear();
                
                // Move to next batch
                $offset += $batchSize;

            } while ($batchCount === $batchSize);

            $this->logger->info('Completed eDO expiration processing', [
                'totalProcessed' => $totalProcessed,
                'totalExpired' => $expiredCount
            ]);

            return $expiredCount;
        } catch (\Exception $e) {
            $this->logger->error('Failed to process expired eDOs', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Detect expired eDOs that need notification
     * 
     * @return array<ElectronicDeliveryOrder> Array of expired eDOs
     */
    public function detectExpiredEDOs(): array
    {
        try {
            $this->logger->info('Detecting expired eDOs');

            // Find all eDOs that are past their expiration date
            $expiredEDOs = $this->edoRepository->findExpiredEDOs();

            $this->logger->info('Expired eDOs detected', [
                'count' => count($expiredEDOs)
            ]);

            return $expiredEDOs;
        } catch (\Exception $e) {
            $this->logger->error('Failed to detect expired eDOs', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Send expiration notifications to brokers and consignees
     * 
     * @param ElectronicDeliveryOrder $edo
     * @return void
     */
    public function sendExpirationNotifications(ElectronicDeliveryOrder $edo): void
    {
        try {
            $this->logger->info('Sending expiration notifications', [
                'edoId' => $edo->getId(),
                'edoNumber' => $edo->getEdoNumber()
            ]);

            // Delegate to notification service
            $this->notificationService->notifyExpiration($edo);

            $this->logger->info('Expiration notifications sent successfully', [
                'edoId' => $edo->getId()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send expiration notifications', [
                'edoId' => $edo->getId(),
                'error' => $e->getMessage()
            ]);
            // Don't throw - notification failure shouldn't break the workflow
        }
    }
}
