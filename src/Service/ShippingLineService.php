<?php

namespace App\Service;

use App\Entity\ShippingLine;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Exception\ValidationException;
use App\Repository\ShippingLineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Core service for shipping line management operations
 * Handles creation, admin assignment, hierarchy validation, and scope management
 */
class ShippingLineService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ShippingLineRepository $shippingLineRepository,
        private ActivityLogService $activityLogService,
        private RequestStack $requestStack,
        private ValidationService $validationService,
        private ErrorRecoveryService $errorRecoveryService
    ) {
    }

    /**
     * Create a new shipping line with validation and logging
     * 
     * @param array $data Array containing 'brandName' and optional 'portalConfig'
     * @param User $creator The user creating the shipping line (must be SYSTEM_ADMIN)
     * @return ShippingLine The created shipping line
     * @throws \InvalidArgumentException If validation fails
     * @throws \RuntimeException If creation fails
     */
    public function createShippingLine(array $data, User $creator): ShippingLine
    {
        // Validate creator permissions
        if ($creator->getRole() !== UserRole::SYSTEM_ADMIN) {
            throw new \InvalidArgumentException('Only SYSTEM_ADMIN can create shipping lines');
        }

        // Comprehensive input validation
        $validationErrors = $this->validationService->validateShippingLineCreation($data);
        if (!empty($validationErrors)) {
            throw new ValidationException($validationErrors, 'Shipping line creation validation failed');
        }

        try {
            // Create shipping line entity
            $shippingLine = new ShippingLine();
            $shippingLine->setBrandName(trim($data['brandName']));
            
            if (isset($data['portalConfig']) && is_array($data['portalConfig'])) {
                $shippingLine->setPortalConfig($data['portalConfig']);
            } else {
                // Set default portal configuration
                $shippingLine->setPortalConfig([
                    'features' => [
                        'enableNotifications' => true,
                        'enableReports' => true,
                        'enableAdvancedSearch' => true,
                        'enableBulkOperations' => false
                    ]
                ]);
            }

            $this->entityManager->persist($shippingLine);
            $this->entityManager->flush();

            // Log the creation activity
            $this->activityLogService->logShippingLineCreation($creator, $shippingLine);

            return $shippingLine;

        } catch (ValidationException $e) {
            // Re-throw validation exceptions
            throw $e;
        } catch (\Exception $e) {
            // Handle database constraint violations gracefully
            if (str_contains($e->getMessage(), 'constraint') || str_contains($e->getMessage(), 'duplicate')) {
                $constraintErrors = $this->validationService->handleConstraintViolation($e);
                throw new ValidationException(['database' => $constraintErrors], 'Database constraint violation');
            }

            // Attempt error recovery for other failures
            $recoveryResult = $this->errorRecoveryService->handleShippingLineCreationFailure($data, $e, $creator);
            
            if ($recoveryResult['degraded_mode']) {
                // If degraded mode was successful, log and continue with limited functionality
                $this->activityLogService->logActivity(
                    $creator,
                    'shipping_line_creation_degraded',
                    'shipping_line',
                    null,
                    null,
                    ['recovery_result' => $recoveryResult]
                );
                
                throw new \RuntimeException($recoveryResult['error_message'] . ' Recovery actions: ' . implode(', ', $recoveryResult['recovery_actions']));
            }

            // Log detailed error for debugging
            $this->activityLogService->logActivity(
                $creator,
                'shipping_line_creation_failed',
                'shipping_line',
                null,
                null,
                [
                    'error' => $e->getMessage(),
                    'data' => $data,
                    'recovery_result' => $recoveryResult
                ]
            );

            throw new \RuntimeException('Failed to create shipping line: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Assign an admin to a shipping line
     * 
     * @param ShippingLine $shippingLine The shipping line to assign admin to
     * @param User $admin The user to assign as admin (must be SHIPPING_LINES_ADMIN)
     * @param User $assignor The user performing the assignment (must be SYSTEM_ADMIN)
     * @throws \InvalidArgumentException If validation fails
     */
    public function assignAdmin(ShippingLine $shippingLine, User $admin, User $assignor): void
    {
        // Validate assignor permissions
        if ($assignor->getRole() !== UserRole::SYSTEM_ADMIN) {
            throw new \InvalidArgumentException('Only SYSTEM_ADMIN can assign shipping line admins');
        }

        // Validate admin role
        if ($admin->getRole() !== UserRole::SHIPPING_LINES_ADMIN) {
            throw new \InvalidArgumentException('User must have SHIPPING_LINES_ADMIN role');
        }

        // Check if admin is already managing another shipping line
        if ($admin->getManagedShippingLine() !== null) {
            throw new \InvalidArgumentException('Admin is already managing another shipping line');
        }

        // Validate shipping line can accept this admin
        if (!$shippingLine->canAssignAdmin($admin)) {
            throw new \InvalidArgumentException('Cannot assign this admin to the shipping line');
        }

        try {
            // Assign the admin
            $admin->setManagedShippingLine($shippingLine);
            $shippingLine->addShippingLineAdmin($admin);

            $this->entityManager->flush();

            // Log the assignment
            $this->activityLogService->logAdminAssignment($assignor, $admin, $shippingLine);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to assign admin: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate user hierarchy relationships
     * 
     * @param User $parent The parent user in the hierarchy
     * @param User $child The child user in the hierarchy
     * @return bool True if hierarchy is valid
     */
    public function validateHierarchy(User $parent, User $child): bool
    {
        // SYSTEM_ADMIN can supervise SHIPPING_LINES_ADMIN
        if ($parent->getRole() === UserRole::SYSTEM_ADMIN && 
            $child->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
            return true;
        }

        // SHIPPING_LINES_ADMIN can supervise subordinate roles
        if ($parent->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
            $validSubordinateRoles = [
                UserRole::SL_STAFF,
                UserRole::EVALUATOR,
                UserRole::ACCOUNTING,
                UserRole::TERMINAL_TEAM
            ];
            return in_array($child->getRole(), $validSubordinateRoles);
        }

        return false;
    }

    /**
     * Get the shipping line scope for a user
     * 
     * @param User $user The user to get scope for
     * @return ShippingLine|null The shipping line scope, null for SYSTEM_ADMIN or independent roles
     */
    public function getShippingLineScope(User $user): ?ShippingLine
    {
        return $user->getShippingLineScope();
    }

    /**
     * Deactivate a shipping line and handle dependent relationships
     * 
     * @param ShippingLine $shippingLine The shipping line to deactivate
     * @param User $deactivator The user performing the deactivation (must be SYSTEM_ADMIN)
     * @throws \InvalidArgumentException If validation fails
     */
    public function deactivateShippingLine(ShippingLine $shippingLine, User $deactivator): void
    {
        // Validate deactivator permissions
        if ($deactivator->getRole() !== UserRole::SYSTEM_ADMIN) {
            throw new \InvalidArgumentException('Only SYSTEM_ADMIN can deactivate shipping lines');
        }

        // Check if shipping line has active users
        $scopedUsers = $shippingLine->getScopedUsers();
        if (!empty($scopedUsers)) {
            throw new \InvalidArgumentException(
                'Cannot deactivate shipping line with active users. Please handle user relationships first.'
            );
        }

        try {
            $shippingLine->deactivate();
            $this->entityManager->flush();

            // Log the deactivation
            $this->activityLogService->logShippingLineDeactivation($deactivator, $shippingLine);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to deactivate shipping line: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Find shipping line by brand name
     * 
     * @param string $brandName The brand name to search for
     * @return ShippingLine|null The shipping line if found
     */
    public function findByBrandName(string $brandName): ?ShippingLine
    {
        return $this->shippingLineRepository->findByBrandName($brandName);
    }

    /**
     * Get all active shipping lines
     * 
     * @return ShippingLine[] Array of active shipping lines
     */
    public function getActiveShippingLines(): array
    {
        return $this->shippingLineRepository->findActive();
    }

    /**
     * Get shipping lines without admins
     * 
     * @return ShippingLine[] Array of shipping lines without admins
     */
    public function getShippingLinesWithoutAdmins(): array
    {
        return $this->shippingLineRepository->findWithoutAdmins();
    }

    /**
     * Update shipping line configuration
     * 
     * @param ShippingLine $shippingLine The shipping line to update
     * @param array $config New configuration data
     * @param User $updater The user performing the update
     * @throws \InvalidArgumentException If validation fails
     */
    public function updatePortalConfig(ShippingLine $shippingLine, array $config, User $updater): void
    {
        // Validate updater permissions
        $userScope = $this->getShippingLineScope($updater);
        
        if ($updater->getRole() === UserRole::SYSTEM_ADMIN) {
            // SYSTEM_ADMIN can update any shipping line
        } elseif ($updater->getRole() === UserRole::SHIPPING_LINES_ADMIN && 
                  $updater->getManagedShippingLine() === $shippingLine) {
            // SHIPPING_LINES_ADMIN can update their own shipping line
        } else {
            throw new \InvalidArgumentException('Insufficient permissions to update shipping line configuration');
        }

        try {
            $oldConfig = $shippingLine->getPortalConfig();
            $shippingLine->setPortalConfig($config);
            $this->entityManager->flush();

            // Log the configuration change
            $this->activityLogService->logShippingLineUpdate($updater, $shippingLine, [
                'portal_config' => ['old' => $oldConfig, 'new' => $config]
            ]);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to update portal configuration: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get shipping line statistics
     * 
     * @return array Statistics about shipping lines
     */
    public function getStatistics(): array
    {
        return $this->shippingLineRepository->getStatistics();
    }

    /**
     * Validate shipping line data
     * 
     * @param array $data The data to validate
     * @return array Array of validation errors (empty if valid)
     */
    public function validateShippingLineData(array $data): array
    {
        $errors = [];

        if (empty($data['brandName'])) {
            $errors[] = 'Brand name is required';
        } elseif (strlen($data['brandName']) < 2) {
            $errors[] = 'Brand name must be at least 2 characters long';
        } elseif (strlen($data['brandName']) > 255) {
            $errors[] = 'Brand name cannot be longer than 255 characters';
        }

        // Check for duplicate brand name if creating new
        if (!empty($data['brandName'])) {
            $existing = $this->shippingLineRepository->findByBrandName($data['brandName']);
            if ($existing !== null) {
                $errors[] = 'Shipping line with this brand name already exists';
            }
        }

        return $errors;
    }
}