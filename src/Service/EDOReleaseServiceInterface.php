<?php

namespace App\Service;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\User;

interface EDOReleaseServiceInterface
{
    /**
     * Get all pending eDO releases with pagination
     * 
     * @param int $page Page number (1-indexed)
     * @param int $perPage Items per page
     * @return array Array containing 'items' and 'total' keys
     */
    public function getPendingEDOReleases(int $page = 1, int $perPage = 20): array;
    
    /**
     * Release an eDO
     * 
     * @param int $edoId The eDO ID to release
     * @param User $admin The SYSTEM_ADMIN performing the release
     * @throws \InvalidArgumentException If eDO not found or not in pending_release status
     */
    public function releaseEDO(int $edoId, User $admin): void;
    
    /**
     * Reject an eDO release
     * 
     * @param int $edoId The eDO ID to reject
     * @param string $reason The rejection reason
     * @param User $admin The SYSTEM_ADMIN performing the rejection
     * @throws \InvalidArgumentException If eDO not found, not in pending_release status, or reason is empty
     */
    public function rejectEDO(int $edoId, string $reason, User $admin): void;
    
    /**
     * Get eDO release history
     * 
     * @param int $edoId The eDO ID
     * @return array Array of EDOReleaseHistory entities
     */
    public function getEDOReleaseHistory(int $edoId): array;
    
    /**
     * Check if user can access eDO
     * 
     * @param ElectronicDeliveryOrder $edo The eDO to check
     * @param User $user The user requesting access
     * @return bool True if user can access the eDO
     */
    public function canAccessEDO(ElectronicDeliveryOrder $edo, User $user): bool;
}
