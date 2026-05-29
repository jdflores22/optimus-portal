<?php

namespace App\Service;

use App\Entity\ElectronicDeliveryOrder;

/**
 * Interface for eDO expiration detection and management
 * 
 * Requirements: 4.1, 4.2, 4.3, 4.4, 4.5
 */
interface EDOExpirationServiceInterface
{
    /**
     * Check if an eDO has expired
     * 
     * @param ElectronicDeliveryOrder $edo
     * @return bool True if eDO is expired, false otherwise
     */
    public function checkExpiration(ElectronicDeliveryOrder $edo): bool;

    /**
     * Calculate the number of days an eDO has been expired
     * 
     * @param ElectronicDeliveryOrder $edo
     * @return int Number of expired days (0 if not expired)
     */
    public function calculateExpiredDays(ElectronicDeliveryOrder $edo): int;

    /**
     * Mark an eDO as expired and record the expiration timestamp
     * 
     * @param ElectronicDeliveryOrder $edo
     * @return void
     */
    public function markAsExpired(ElectronicDeliveryOrder $edo): void;

    /**
     * Process all active eDOs to detect and mark expired ones
     * Uses batch processing with pagination for performance
     * 
     * @param int $batchSize Number of eDOs to process per batch
     * @return int Number of eDOs marked as expired
     */
    public function processExpiredEDOs(int $batchSize = 100): int;

    /**
     * Detect expired eDOs that need notification
     * 
     * @return array<ElectronicDeliveryOrder> Array of expired eDOs
     */
    public function detectExpiredEDOs(): array;

    /**
     * Send expiration notifications to brokers and consignees
     * 
     * @param ElectronicDeliveryOrder $edo
     * @return void
     */
    public function sendExpirationNotifications(ElectronicDeliveryOrder $edo): void;
}
