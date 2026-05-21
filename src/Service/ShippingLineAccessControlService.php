<?php

namespace App\Service;

use App\Entity\ShippingLine;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Handles access control for shipping line data
 * Determines which shipping lines a user can access
 */
class ShippingLineAccessControlService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Check if user can access a specific shipping line's data
     */
    public function canAccessShippingLine(User $user, ShippingLine $shippingLine): bool
    {
        $role = $user->getRole();

        // System admins, accounting, and evaluators can access all shipping lines
        if ($role === UserRole::SYSTEM_ADMIN || $role === UserRole::ACCOUNTING || $role === UserRole::EVALUATOR) {
            return true;
        }

        // Shipping line staff and admins can only access their scoped shipping line
        if ($role === UserRole::SL_STAFF || $role === UserRole::SHIPPING_LINES_ADMIN) {
            $scopedShippingLine = $user->getShippingLineScope();
            return $scopedShippingLine && $scopedShippingLine->getId() === $shippingLine->getId();
        }

        // Brokers and consignees need approved accreditation
        if ($role === UserRole::BROKER || $role === UserRole::CONSIGNEE) {
            return $this->hasApprovedAccreditation($user, $shippingLine);
        }

        return false;
    }

    /**
     * Get all shipping lines the user has access to
     */
    public function getAccessibleShippingLines(User $user): array
    {
        $role = $user->getRole();

        // System admins, accounting, and evaluators see all active shipping lines
        if ($role === UserRole::SYSTEM_ADMIN || $role === UserRole::ACCOUNTING || $role === UserRole::EVALUATOR) {
            return $this->entityManager->getRepository(ShippingLine::class)
                ->findBy(['isActive' => true], ['brandName' => 'ASC']);
        }

        // Shipping line staff and admins see only their scoped shipping line
        if ($role === UserRole::SL_STAFF || $role === UserRole::SHIPPING_LINES_ADMIN) {
            $shippingLine = $user->getShippingLineScope();
            return $shippingLine ? [$shippingLine] : [];
        }

        // Brokers and consignees see shipping lines they have approved accreditation for
        if ($role === UserRole::BROKER || $role === UserRole::CONSIGNEE) {
            return $this->getApprovedShippingLines($user);
        }

        return [];
    }

    /**
     * Check if user has approved accreditation for a shipping line
     */
    public function hasApprovedAccreditation(User $user, ShippingLine $shippingLine): bool
    {
        // Query accreditation_submissions for approved status
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(a.id)')
            ->from('App\Entity\AccreditationSubmission', 'a')
            ->where('a.applicant = :user')
            ->andWhere('a.shippingLine = :shippingLine')
            ->andWhere('a.status = :status')
            ->setParameter('user', $user)
            ->setParameter('shippingLine', $shippingLine)
            ->setParameter('status', 'APPROVED');

        $count = $qb->getQuery()->getSingleScalarResult();
        return $count > 0;
    }

    /**
     * Apply shipping line filter to a query builder
     */
    public function filterQueryByShippingLine(QueryBuilder $qb, User $user, string $alias = 'e'): QueryBuilder
    {
        $role = $user->getRole();

        // System admins, accounting, and evaluators see all data (no filter)
        if ($role === UserRole::SYSTEM_ADMIN || $role === UserRole::ACCOUNTING || $role === UserRole::EVALUATOR) {
            return $qb;
        }

        // Shipping line staff and admins see only their scoped shipping line
        if ($role === UserRole::SL_STAFF || $role === UserRole::SHIPPING_LINES_ADMIN) {
            $shippingLine = $user->getShippingLineScope();
            if ($shippingLine) {
                $qb->andWhere("{$alias}.shippingLine = :shippingLine")
                   ->setParameter('shippingLine', $shippingLine);
            } else {
                // No shipping line scope = no access
                $qb->andWhere('1 = 0'); // Always false
            }
            return $qb;
        }

        // Brokers and consignees see only approved shipping lines
        if ($role === UserRole::BROKER || $role === UserRole::CONSIGNEE) {
            $approvedShippingLines = $this->getApprovedShippingLines($user);
            if (!empty($approvedShippingLines)) {
                $qb->andWhere("{$alias}.shippingLine IN (:shippingLines)")
                   ->setParameter('shippingLines', $approvedShippingLines);
            } else {
                // No approved shipping lines = no access
                $qb->andWhere('1 = 0'); // Always false
            }
            return $qb;
        }

        // Default: no access
        $qb->andWhere('1 = 0');
        return $qb;
    }

    /**
     * Check if user has access to the system (legacy method for backward compatibility)
     * Checks if user's managed shipping line is active or if they have any approved accreditations
     * 
     * NOTE: Brokers and Consignees can login without accreditation, but they won't be able to
     * access shipping line specific data until they have approved accreditation.
     */
    public function hasAccess(User $user): bool
    {
        $role = $user->getRole();

        // System admins, accounting, evaluators, terminal team, and truckers always have access
        if (in_array($role, [
            UserRole::SYSTEM_ADMIN,
            UserRole::ACCOUNTING,
            UserRole::EVALUATOR,
            UserRole::TERMINAL_TEAM,
            UserRole::TRUCKER
        ])) {
            return true;
        }

        // Shipping line staff and admins need an active shipping line scope
        if ($role === UserRole::SL_STAFF || $role === UserRole::SHIPPING_LINES_ADMIN) {
            $shippingLine = $user->getShippingLineScope();
            return $shippingLine && $shippingLine->isActive();
        }

        // Brokers and consignees can login without accreditation
        // They just won't have access to shipping line data until approved
        if ($role === UserRole::BROKER || $role === UserRole::CONSIGNEE) {
            return true;
        }

        return false;
    }

    /**
     * Get reason why user access is denied
     * 
     * NOTE: This is used by the access listener to block login.
     * Brokers and Consignees should NOT be blocked from login, even without accreditation.
     */
    public function getAccessDenialReason(User $user): ?string
    {
        $role = $user->getRole();

        // System admins, accounting, evaluators, terminal team, and truckers always have access
        if (in_array($role, [
            UserRole::SYSTEM_ADMIN,
            UserRole::ACCOUNTING,
            UserRole::EVALUATOR,
            UserRole::TERMINAL_TEAM,
            UserRole::TRUCKER
        ])) {
            return null;
        }

        // Shipping line staff and admins
        if ($role === UserRole::SL_STAFF || $role === UserRole::SHIPPING_LINES_ADMIN) {
            $shippingLine = $user->getShippingLineScope();
            if (!$shippingLine) {
                return 'Your account is not associated with a shipping line.';
            }
            if (!$shippingLine->isActive()) {
                return 'Your shipping line has been deactivated. Please contact support.';
            }
            return null;
        }

        // Brokers and consignees can login without accreditation
        // They will see a message on their dashboard to apply for accreditation
        if ($role === UserRole::BROKER || $role === UserRole::CONSIGNEE) {
            return null;
        }

        return 'Access denied.';
    }

    /**
     * Get the shipping line associated with a user
     * For shipping line staff/admins: returns their scoped shipping line
     * For brokers/consignees: returns the first approved shipping line
     */
    public function getUserShippingLine(User $user): ?ShippingLine
    {
        $role = $user->getRole();

        // Shipping line staff and admins have a scoped shipping line
        if ($role === UserRole::SL_STAFF || $role === UserRole::SHIPPING_LINES_ADMIN) {
            return $user->getShippingLineScope();
        }

        // Brokers and consignees: return first approved shipping line
        if ($role === UserRole::BROKER || $role === UserRole::CONSIGNEE) {
            $approvedShippingLines = $this->getApprovedShippingLines($user);
            return !empty($approvedShippingLines) ? $approvedShippingLines[0] : null;
        }

        return null;
    }

    /**
     * Get shipping lines user has approved accreditation for
     */
    private function getApprovedShippingLines(User $user): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('sl')
            ->from('App\Entity\ShippingLine', 'sl')
            ->innerJoin('App\Entity\AccreditationSubmission', 'a', 'WITH', 'a.shippingLine = sl.id')
            ->where('a.applicant = :user')
            ->andWhere('a.status = :status')
            ->andWhere('sl.isActive = :active')
            ->setParameter('user', $user)
            ->setParameter('status', 'APPROVED')
            ->setParameter('active', true)
            ->orderBy('sl.brandName', 'ASC');

        return $qb->getQuery()->getResult();
    }
}
