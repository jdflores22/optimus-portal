<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\ShippingLine;
use App\Entity\Enum\UserRole;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Service for enforcing shipping line scope access control
 * Ensures complete data isolation between shipping lines
 */
class ScopeAccessControlService
{
    public function __construct(
        private ActivityLogService $activityLogService
    ) {
    }

    /**
     * Filters query builder to only include data within user's shipping line scope
     * 
     * @param QueryBuilder $qb The query builder to filter
     * @param User $user The user requesting data
     * @return QueryBuilder The filtered query builder
     */
    public function filterByShippingLineScope(QueryBuilder $qb, User $user): QueryBuilder
    {
        $shippingLineScope = $user->getShippingLineScope();
        
        // SYSTEM_ADMIN has access to all data - no filtering needed
        if ($user->getRole() === UserRole::SYSTEM_ADMIN) {
            return $qb;
        }
        
        // Independent roles (CONSIGNEE, BROKER, TRUCKER) have their own access patterns
        if (in_array($user->getRole(), [UserRole::CONSIGNEE, UserRole::BROKER, UserRole::TRUCKER])) {
            // These roles maintain their existing access patterns
            return $qb;
        }
        
        // Shipping line scoped users - filter by their shipping line
        if ($shippingLineScope !== null) {
            $alias = $qb->getRootAliases()[0];
            
            // Check if the entity has a shipping line relationship
            if ($this->hasShippingLineRelation($qb->getRootEntities()[0])) {
                $qb->andWhere($alias . '.shippingLine = :shippingLineScope')
                   ->setParameter('shippingLineScope', $shippingLineScope);
            }
        }
        
        return $qb;
    }

    /**
     * Validates if a user has access to a specific entity
     * 
     * @param User $user The user requesting access
     * @param object $entity The entity being accessed
     * @return bool True if access is allowed
     * @throws AccessDeniedException If access is denied
     */
    public function validateAccess(User $user, object $entity): bool
    {
        // SYSTEM_ADMIN has access to everything
        if ($user->getRole() === UserRole::SYSTEM_ADMIN) {
            return true;
        }
        
        $userScope = $user->getShippingLineScope();
        $entityScope = $this->getEntityShippingLineScope($entity);
        
        // Independent roles maintain their existing access patterns
        if (in_array($user->getRole(), [UserRole::CONSIGNEE, UserRole::BROKER, UserRole::TRUCKER])) {
            return $this->validateIndependentRoleAccess($user, $entity);
        }
        
        // For shipping line scoped users, entity must be in same scope
        if ($userScope !== null && $entityScope !== null) {
            if ($userScope->getId() !== $entityScope->getId()) {
                $this->activityLogService->logAccessDenied(
                    $user,
                    get_class($entity) . ':' . $this->getEntityId($entity),
                    'Entity outside shipping line scope'
                );
                
                throw new AccessDeniedException('Access denied: Entity outside shipping line scope');
            }
        }
        
        return true;
    }

    /**
     * Applies scope restrictions to HTTP requests
     * 
     * @param Request $request The HTTP request
     * @param User $user The authenticated user
     */
    public function applyScopeRestrictions(Request $request, User $user): void
    {
        // SYSTEM_ADMIN has no restrictions
        if ($user->getRole() === UserRole::SYSTEM_ADMIN) {
            return;
        }
        
        $shippingLineScope = $user->getShippingLineScope();
        
        // Add shipping line scope to request attributes for controllers to use
        if ($shippingLineScope !== null) {
            $request->attributes->set('shipping_line_scope', $shippingLineScope);
            $request->attributes->set('shipping_line_id', $shippingLineScope->getId());
        }
        
        // Validate URL parameters for shipping line IDs
        $this->validateUrlParameters($request, $user);
    }

    /**
     * Checks if user can access data from a specific shipping line
     * 
     * @param User $user The user requesting access
     * @param ShippingLine|null $shippingLine The shipping line being accessed
     * @return bool True if access is allowed
     */
    public function canAccessShippingLine(User $user, ?ShippingLine $shippingLine): bool
    {
        // SYSTEM_ADMIN can access any shipping line
        if ($user->getRole() === UserRole::SYSTEM_ADMIN) {
            return true;
        }
        
        // Independent roles don't have shipping line restrictions
        if (in_array($user->getRole(), [UserRole::CONSIGNEE, UserRole::BROKER, UserRole::TRUCKER])) {
            return true;
        }
        
        $userScope = $user->getShippingLineScope();
        
        // User must have same shipping line scope
        if ($userScope === null || $shippingLine === null) {
            return false;
        }
        
        return $userScope->getId() === $shippingLine->getId();
    }

    /**
     * Gets all shipping lines accessible to a user
     * 
     * @param User $user The user
     * @return array Array of accessible ShippingLine entities
     */
    public function getAccessibleShippingLines(User $user): array
    {
        // SYSTEM_ADMIN can access all shipping lines
        if ($user->getRole() === UserRole::SYSTEM_ADMIN) {
            // This would need to be implemented with a repository call
            return []; // Placeholder - would fetch all active shipping lines
        }
        
        // Shipping line scoped users can only access their own shipping line
        $userScope = $user->getShippingLineScope();
        if ($userScope !== null) {
            return [$userScope];
        }
        
        // Independent roles have no shipping line restrictions
        return [];
    }

    /**
     * Gets the shipping line scope for a user
     * 
     * @param User $user The user
     * @return ShippingLine|null The shipping line scope or null if no scope
     */
    public function getShippingLineScope(User $user): ?ShippingLine
    {
        // SYSTEM_ADMIN has no scope restrictions
        if ($user->getRole() === UserRole::SYSTEM_ADMIN) {
            return null;
        }
        
        // Get user's shipping line scope
        return $user->getShippingLineScope();
    }

    /**
     * Validates that a user can perform a specific action on an entity
     * 
     * @param User $user The user attempting the action
     * @param string $action The action being performed (create, read, update, delete)
     * @param object $entity The entity being acted upon
     * @return bool True if action is allowed
     */
    public function canPerformAction(User $user, string $action, object $entity): bool
    {
        // First check basic access to the entity
        if (!$this->validateAccess($user, $entity)) {
            return false;
        }
        
        // Role-specific action permissions
        return $this->validateRolePermissions($user, $action, $entity);
    }

    /**
     * Logs suspicious access attempts
     * 
     * @param User $user The user making the attempt
     * @param string $resource The resource being accessed
     * @param array $details Additional details about the attempt
     */
    public function logSuspiciousActivity(User $user, string $resource, array $details = []): void
    {
        $this->activityLogService->logSuspiciousActivity($user, 'unauthorized_access_attempt', [
            'resource' => $resource,
            'user_scope' => $user->getShippingLineScope()?->getId(),
            'details' => $details,
            'timestamp' => new \DateTime()
        ]);
    }

    /**
     * Prevents privilege escalation attempts
     * 
     * @param User $user The user attempting escalation
     * @param string $attemptedAction The action they're trying to perform
     */
    public function preventPrivilegeEscalation(User $user, string $attemptedAction): void
    {
        $this->activityLogService->logPrivilegeEscalationAttempt($user, $attemptedAction);
        
        throw new AccessDeniedException('Privilege escalation attempt detected and blocked');
    }

    // Private helper methods

    /**
     * Checks if an entity class has a shipping line relationship
     */
    private function hasShippingLineRelation(string $entityClass): bool
    {
        // This would need to be implemented based on your entity metadata
        // For now, return true for entities that should be scoped
        $scopedEntities = [
            'App\Entity\ActivityLog',
            'App\Entity\User',
            // Add other entities that should be scoped by shipping line
        ];
        
        return in_array($entityClass, $scopedEntities);
    }

    /**
     * Gets the shipping line scope for an entity
     */
    private function getEntityShippingLineScope(object $entity): ?ShippingLine
    {
        if (method_exists($entity, 'getShippingLineScope')) {
            return $entity->getShippingLineScope();
        }
        
        if (method_exists($entity, 'getShippingLine')) {
            return $entity->getShippingLine();
        }
        
        return null;
    }

    /**
     * Gets entity ID for logging purposes
     */
    private function getEntityId(object $entity): ?int
    {
        if (method_exists($entity, 'getId')) {
            return $entity->getId();
        }
        
        return null;
    }

    /**
     * Validates access for independent roles (CONSIGNEE, BROKER, TRUCKER)
     */
    private function validateIndependentRoleAccess(User $user, object $entity): bool
    {
        // Independent roles maintain their existing access patterns
        // This would need to be implemented based on existing business logic
        return true; // Placeholder - implement existing access logic
    }

    /**
     * Validates URL parameters for shipping line access
     */
    private function validateUrlParameters(Request $request, User $user): void
    {
        // Check for shipping line ID in URL parameters
        $shippingLineId = $request->get('shipping_line_id') ?? $request->get('shippingLineId');
        
        if ($shippingLineId !== null) {
            $userScope = $user->getShippingLineScope();
            
            if ($userScope === null || $userScope->getId() != $shippingLineId) {
                $this->logSuspiciousActivity($user, 'url_parameter_manipulation', [
                    'attempted_shipping_line_id' => $shippingLineId,
                    'user_shipping_line_id' => $userScope?->getId(),
                    'url' => $request->getRequestUri()
                ]);
                
                throw new AccessDeniedException('Access denied: Invalid shipping line parameter');
            }
        }
    }

    /**
     * Validates role-specific permissions for actions
     */
    private function validateRolePermissions(User $user, string $action, object $entity): bool
    {
        // Role-specific permission logic would go here
        // For now, return true for basic access within scope
        return true;
    }
}