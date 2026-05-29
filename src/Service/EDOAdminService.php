<?php

namespace App\Service;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\EDOStatus;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for admin eDO operations
 * Handles admin unlock functionality without payment requirement
 */
class EDOAdminService implements EDOAdminServiceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function unlockEDO(ElectronicDeliveryOrder $edo, User $admin, string $reason): void
    {
        // Validate admin role
        if (!$this->canUnlockWithoutPayment($admin)) {
            throw new \InvalidArgumentException('Only System_Admin users can unlock eDOs without payment');
        }

        // Validate reason is provided
        if (empty(trim($reason))) {
            throw new \InvalidArgumentException('Reason is required for admin unlock');
        }

        // Determine the appropriate unlock status based on current status
        $currentStatus = $edo->getStatus();
        
        // For LOCKED or EXPIRED eDOs, unlock to ACTIVE
        if ($currentStatus === EDOStatus::LOCKED || $currentStatus === EDOStatus::EXPIRED) {
            $edo->setStatus(EDOStatus::ACTIVE);
            
            // For LOCKED (non-expired) eDOs, preserve the eDO number (no regeneration)
            // For EXPIRED eDOs, also preserve number but reset expiration tracking
            if ($currentStatus === EDOStatus::EXPIRED) {
                // Reset expired days since we're unlocking
                $edo->setExpiredDays(null);
            }
        } else {
            throw new \InvalidArgumentException(
                sprintf('Cannot unlock eDO with status %s. Only LOCKED or EXPIRED eDOs can be unlocked.', 
                    $currentStatus->value)
            );
        }

        // Persist changes
        $this->entityManager->flush();

        // TODO: Re-implement audit logging with general AuditService
        // Log admin unlock action
    }

    /**
     * {@inheritdoc}
     */
    public function canUnlockWithoutPayment(User $user): bool
    {
        return $user->getRole() === UserRole::SYSTEM_ADMIN;
    }
}
