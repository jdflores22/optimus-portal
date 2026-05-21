<?php

namespace App\Service;

use App\Entity\ShippingLine;
use App\Entity\RolePermissionConfiguration;
use App\Entity\ConfigurationHistory;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class RolePermissionConfigurationService
{
    private EntityManagerInterface $entityManager;
    private ValidatorInterface $validator;
    private ActivityLogService $activityLogService;

    // Define available permissions
    private const AVAILABLE_PERMISSIONS = [
        // User Management
        'user.create',
        'user.view',
        'user.edit',
        'user.delete',
        'user.suspend',
        'user.activate',
        
        // Shipping Line Management
        'shipping_line.view',
        'shipping_line.edit',
        'shipping_line.configure',
        
        // Data Access
        'data.view_all',
        'data.view_own',
        'data.export',
        'data.import',
        
        // Reports
        'reports.view',
        'reports.generate',
        'reports.schedule',
        
        // Configuration
        'config.view',
        'config.edit',
        'config.branding',
        
        // Terminal Operations
        'terminal.view',
        'terminal.assign',
        'terminal.manage',
        
        // Container Operations
        'container.view',
        'container.assign',
        'container.track',
        
        // Financial Operations
        'finance.view',
        'finance.process',
        'finance.approve',
        
        // Evaluation Operations
        'evaluation.view',
        'evaluation.process',
        'evaluation.approve',
        
        // API Access
        'api.access',
        'api.admin'
    ];

    public function __construct(
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        ActivityLogService $activityLogService
    ) {
        $this->entityManager = $entityManager;
        $this->validator = $validator;
        $this->activityLogService = $activityLogService;
    }

    /**
     * Creates or updates role permissions for a shipping line
     */
    public function setRolePermissions(
        ShippingLine $shippingLine,
        UserRole $role,
        array $permissions,
        User $user,
        ?array $restrictions = null,
        bool $inheritFromParent = false
    ): RolePermissionConfiguration {
        $this->validateUserPermissions($user, $shippingLine);
        $this->validatePermissions($permissions);
        $this->validateRoleHierarchy($role, $shippingLine);

        $repository = $this->entityManager->getRepository(RolePermissionConfiguration::class);
        $config = $repository->findOneBy([
            'shippingLine' => $shippingLine,
            'role' => $role
        ]);

        $oldPermissions = null;
        $action = 'create';

        if ($config) {
            $oldPermissions = $config->getPermissions();
            $action = 'update';
            $config->setUpdatedBy($user);
        } else {
            $config = new RolePermissionConfiguration();
            $config->setShippingLine($shippingLine);
            $config->setRole($role);
            $config->setCreatedBy($user);
        }

        $config->setPermissions($permissions);
        $config->setRestrictions($restrictions);
        $config->setInheritFromParent($inheritFromParent);

        // Validate the configuration
        $errors = $this->validator->validate($config);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            throw new \InvalidArgumentException('Permission configuration validation failed: ' . implode(', ', $errorMessages));
        }

        try {
            $this->entityManager->persist($config);
            $this->entityManager->flush();

            // Create history record
            $this->createHistoryRecord(
                $shippingLine,
                'role_permission',
                $role->value,
                $oldPermissions,
                $permissions,
                $action,
                $user
            );

            // Log the activity
            $this->activityLogService->logPermissionChange($user, $user, $oldPermissions ?? [], $permissions);

            return $config;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to save role permissions: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Gets role permissions for a shipping line
     */
    public function getRolePermissions(ShippingLine $shippingLine, UserRole $role): ?RolePermissionConfiguration
    {
        $repository = $this->entityManager->getRepository(RolePermissionConfiguration::class);
        return $repository->findOneBy([
            'shippingLine' => $shippingLine,
            'role' => $role,
            'isActive' => true
        ]);
    }

    /**
     * Gets all role permissions for a shipping line
     */
    public function getAllRolePermissions(ShippingLine $shippingLine): array
    {
        $repository = $this->entityManager->getRepository(RolePermissionConfiguration::class);
        return $repository->findBy([
            'shippingLine' => $shippingLine,
            'isActive' => true
        ], ['role' => 'ASC']);
    }

    /**
     * Checks if a user has a specific permission
     */
    public function hasPermission(User $user, string $permission): bool
    {
        $shippingLine = $this->getShippingLineScope($user);
        if (!$shippingLine) {
            // For roles without shipping line scope (CONSIGNEE, BROKER, TRUCKER)
            return $this->hasDefaultPermission($user->getRole(), $permission);
        }

        $roleConfig = $this->getRolePermissions($shippingLine, $user->getRole());
        if (!$roleConfig) {
            // Fall back to default permissions if no custom configuration
            return $this->hasDefaultPermission($user->getRole(), $permission);
        }

        $effectivePermissions = $roleConfig->getEffectivePermissions();
        return in_array($permission, $effectivePermissions, true);
    }

    /**
     * Gets effective permissions for a user including inheritance
     */
    public function getEffectivePermissions(User $user): array
    {
        $shippingLine = $this->getShippingLineScope($user);
        if (!$shippingLine) {
            return $this->getDefaultPermissions($user->getRole());
        }

        $roleConfig = $this->getRolePermissions($shippingLine, $user->getRole());
        if (!$roleConfig) {
            return $this->getDefaultPermissions($user->getRole());
        }

        $permissions = $roleConfig->getPermissions();

        if ($roleConfig->isInheritFromParent()) {
            $parentPermissions = $this->getParentRolePermissions($shippingLine, $user->getRole());
            $permissions = array_unique(array_merge($permissions, $parentPermissions));
        }

        return $permissions;
    }

    /**
     * Validates user permissions against restrictions
     */
    public function validateUserAccess(User $user, string $resource, array $context = []): bool
    {
        $shippingLine = $this->getShippingLineScope($user);
        if (!$shippingLine) {
            return true; // No restrictions for non-scoped roles
        }

        $roleConfig = $this->getRolePermissions($shippingLine, $user->getRole());
        if (!$roleConfig || !$roleConfig->getRestrictions()) {
            return true; // No restrictions configured
        }

        $restrictions = $roleConfig->getRestrictions();

        // Check time-based restrictions
        if (isset($restrictions['time_restrictions'])) {
            if (!$this->validateTimeRestrictions($restrictions['time_restrictions'])) {
                return false;
            }
        }

        // Check IP-based restrictions
        if (isset($restrictions['ip_restrictions']) && isset($context['ip_address'])) {
            if (!$this->validateIpRestrictions($restrictions['ip_restrictions'], $context['ip_address'])) {
                return false;
            }
        }

        // Check resource-specific restrictions
        if (isset($restrictions['resource_restrictions'][$resource])) {
            return $this->validateResourceRestrictions($restrictions['resource_restrictions'][$resource], $context);
        }

        return true;
    }

    /**
     * Deletes role permissions configuration
     */
    public function deleteRolePermissions(ShippingLine $shippingLine, UserRole $role, User $user): void
    {
        $this->validateUserPermissions($user, $shippingLine);

        $config = $this->getRolePermissions($shippingLine, $role);
        if (!$config) {
            throw new \InvalidArgumentException('Role permissions configuration not found');
        }

        $oldPermissions = $config->getPermissions();

        try {
            $this->entityManager->remove($config);
            $this->entityManager->flush();

            // Create history record
            $this->createHistoryRecord(
                $shippingLine,
                'role_permission',
                $role->value,
                $oldPermissions,
                [],
                'delete',
                $user
            );

            // Log the activity
            $this->activityLogService->logPermissionChange($user, $user, $oldPermissions, []);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to delete role permissions: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Gets available permissions list
     */
    public function getAvailablePermissions(): array
    {
        return self::AVAILABLE_PERMISSIONS;
    }

    /**
     * Gets default permissions for a role
     */
    public function getDefaultPermissions(UserRole $role): array
    {
        $defaultPermissions = [
            'SYSTEM_ADMIN' => [
                'user.create', 'user.view', 'user.edit', 'user.delete', 'user.suspend', 'user.activate',
                'shipping_line.view', 'shipping_line.edit', 'shipping_line.configure',
                'data.view_all', 'data.export', 'data.import',
                'reports.view', 'reports.generate', 'reports.schedule',
                'config.view', 'config.edit', 'config.branding',
                'api.access', 'api.admin'
            ],
            'SHIPPING_LINES_ADMIN' => [
                'user.create', 'user.view', 'user.edit', 'user.suspend', 'user.activate',
                'shipping_line.view', 'shipping_line.edit', 'shipping_line.configure',
                'data.view_all', 'data.export',
                'reports.view', 'reports.generate', 'reports.schedule',
                'config.view', 'config.edit', 'config.branding',
                'api.access'
            ],
            'SL_STAFF' => [
                'user.view',
                'shipping_line.view',
                'data.view_own', 'data.export',
                'reports.view'
            ],
            'EVALUATOR' => [
                'user.view',
                'shipping_line.view',
                'data.view_own',
                'reports.view',
                'evaluation.view', 'evaluation.process', 'evaluation.approve'
            ],
            'ACCOUNTING' => [
                'user.view',
                'shipping_line.view',
                'data.view_own', 'data.export',
                'reports.view', 'reports.generate',
                'finance.view', 'finance.process', 'finance.approve'
            ],
            'TERMINAL_TEAM' => [
                'user.view',
                'shipping_line.view',
                'data.view_own',
                'terminal.view', 'terminal.assign', 'terminal.manage',
                'container.view', 'container.assign', 'container.track'
            ],
            'CONSIGNEE' => [
                'data.view_own',
                'reports.view'
            ],
            'BROKER' => [
                'data.view_own',
                'reports.view'
            ],
            'TRUCKER' => [
                'data.view_own'
            ]
        ];

        return $defaultPermissions[$role->value] ?? [];
    }

    /**
     * Gets shipping line scope for a user
     */
    private function getShippingLineScope(User $user): ?ShippingLine
    {
        if ($user->getManagedShippingLine()) {
            return $user->getManagedShippingLine();
        }

        if ($user->getShippingLineAdmin()) {
            return $user->getShippingLineAdmin()->getManagedShippingLine();
        }

        return null;
    }

    /**
     * Checks if a role has default permission
     */
    private function hasDefaultPermission(UserRole $role, string $permission): bool
    {
        $defaultPermissions = $this->getDefaultPermissions($role);
        return in_array($permission, $defaultPermissions, true);
    }

    /**
     * Gets parent role permissions
     */
    private function getParentRolePermissions(ShippingLine $shippingLine, UserRole $role): array
    {
        $roleHierarchy = [
            UserRole::SL_STAFF => UserRole::SHIPPING_LINES_ADMIN,
            UserRole::EVALUATOR => UserRole::SHIPPING_LINES_ADMIN,
            UserRole::ACCOUNTING => UserRole::SHIPPING_LINES_ADMIN,
            UserRole::TERMINAL_TEAM => UserRole::SHIPPING_LINES_ADMIN,
        ];

        if (!isset($roleHierarchy[$role])) {
            return [];
        }

        $parentRole = $roleHierarchy[$role];
        $parentConfig = $this->getRolePermissions($shippingLine, $parentRole);
        
        if ($parentConfig) {
            return $parentConfig->getPermissions();
        }

        return $this->getDefaultPermissions($parentRole);
    }

    /**
     * Validates permissions array
     */
    private function validatePermissions(array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (!in_array($permission, self::AVAILABLE_PERMISSIONS, true)) {
                throw new \InvalidArgumentException("Invalid permission: {$permission}");
            }
        }
    }

    /**
     * Validates role hierarchy constraints
     */
    private function validateRoleHierarchy(UserRole $role, ShippingLine $shippingLine): void
    {
        $hierarchicalRoles = [
            UserRole::SHIPPING_LINES_ADMIN,
            UserRole::SL_STAFF,
            UserRole::EVALUATOR,
            UserRole::ACCOUNTING,
            UserRole::TERMINAL_TEAM
        ];

        if (!in_array($role, $hierarchicalRoles, true)) {
            throw new \InvalidArgumentException("Role {$role->value} cannot have shipping line-specific permissions");
        }
    }

    /**
     * Validates user permissions for configuration operations
     */
    private function validateUserPermissions(User $user, ShippingLine $shippingLine): void
    {
        if ($user->getRole() === UserRole::SYSTEM_ADMIN) {
            return;
        }

        if ($user->getRole() === UserRole::SHIPPING_LINES_ADMIN && 
            $user->getManagedShippingLine() === $shippingLine) {
            return;
        }

        throw new \InvalidArgumentException('Insufficient permissions to modify role permissions');
    }

    /**
     * Validates time-based restrictions
     */
    private function validateTimeRestrictions(array $timeRestrictions): bool
    {
        $now = new \DateTime();
        $currentTime = $now->format('H:i');
        $currentDay = strtolower($now->format('l'));

        if (isset($timeRestrictions['allowed_hours'])) {
            $allowedHours = $timeRestrictions['allowed_hours'];
            if ($currentTime < $allowedHours['start'] || $currentTime > $allowedHours['end']) {
                return false;
            }
        }

        if (isset($timeRestrictions['allowed_days'])) {
            $allowedDays = array_map('strtolower', $timeRestrictions['allowed_days']);
            if (!in_array($currentDay, $allowedDays, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validates IP-based restrictions
     */
    private function validateIpRestrictions(array $ipRestrictions, string $userIp): bool
    {
        if (isset($ipRestrictions['allowed_ips'])) {
            return in_array($userIp, $ipRestrictions['allowed_ips'], true);
        }

        if (isset($ipRestrictions['blocked_ips'])) {
            return !in_array($userIp, $ipRestrictions['blocked_ips'], true);
        }

        return true;
    }

    /**
     * Validates resource-specific restrictions
     */
    private function validateResourceRestrictions(array $resourceRestrictions, array $context): bool
    {
        // Implement specific resource validation logic based on context
        return true;
    }

    /**
     * Creates a history record for permission changes
     */
    private function createHistoryRecord(
        ShippingLine $shippingLine,
        string $configType,
        string $configKey,
        ?array $oldValue,
        array $newValue,
        string $action,
        User $user
    ): void {
        $history = ConfigurationHistory::createForConfigChange(
            $shippingLine,
            $configType,
            $configKey,
            $oldValue,
            $newValue,
            $action,
            $user
        );

        $this->entityManager->persist($history);
        $this->entityManager->flush();
    }
}