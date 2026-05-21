<?php

namespace App\Service;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\User;

/**
 * Interface for admin eDO operations
 * Handles admin unlock functionality without payment requirement
 */
interface EDOAdminServiceInterface
{
    /**
     * Unlock an eDO without requiring payment
     * Only System_Admin users can perform this operation
     *
     * @param ElectronicDeliveryOrder $edo The eDO to unlock
     * @param User $admin The admin user performing the unlock
     * @param string $reason The reason for unlocking without payment
     * @throws \InvalidArgumentException If user is not System_Admin
     * @throws \RuntimeException If unlock operation fails
     */
    public function unlockEDO(ElectronicDeliveryOrder $edo, User $admin, string $reason): void;

    /**
     * Check if a user can unlock eDOs without payment
     * Only System_Admin role has this capability
     *
     * @param User $user The user to check
     * @return bool True if user has System_Admin role
     */
    public function canUnlockWithoutPayment(User $user): bool;
}
