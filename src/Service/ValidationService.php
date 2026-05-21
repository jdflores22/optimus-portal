<?php

namespace App\Service;

use App\Entity\ShippingLine;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Exception\ValidationException;
use App\Repository\ShippingLineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Psr\Log\LoggerInterface;

/**
 * Comprehensive validation service for shipping line management system
 * Handles all input validation with descriptive error messages and business rule validation
 */
class ValidationService
{
    public function __construct(
        private ShippingLineRepository $shippingLineRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Validate shipping line creation data
     * Requirements: 10.1, 10.2, 10.3
     */
    public function validateShippingLineCreation(array $data): array
    {
        $errors = [];

        // Validate brand name
        $brandNameErrors = $this->validateBrandName($data['brandName'] ?? null);
        if (!empty($brandNameErrors)) {
            $errors['brandName'] = $brandNameErrors;
        }

        // Validate portal configuration
        $portalConfigErrors = $this->validatePortalConfiguration($data['portalConfig'] ?? null);
        if (!empty($portalConfigErrors)) {
            $errors['portalConfig'] = $portalConfigErrors;
        }

        // Validate initial admin if provided
        if (isset($data['initialAdminId'])) {
            $adminErrors = $this->validateInitialAdmin($data['initialAdminId']);
            if (!empty($adminErrors)) {
                $errors['initialAdminId'] = $adminErrors;
            }
        }

        return $errors;
    }

    /**
     * Validate shipping line update data
     * Requirements: 10.1, 10.2, 10.3
     */
    public function validateShippingLineUpdate(array $data, ShippingLine $existingShippingLine): array
    {
        $errors = [];

        // Validate brand name if being updated
        if (isset($data['brandName'])) {
            $brandNameErrors = $this->validateBrandName($data['brandName'], $existingShippingLine->getId());
            if (!empty($brandNameErrors)) {
                $errors['brandName'] = $brandNameErrors;
            }
        }

        // Validate portal configuration if being updated
        if (isset($data['portalConfig'])) {
            $portalConfigErrors = $this->validatePortalConfiguration($data['portalConfig']);
            if (!empty($portalConfigErrors)) {
                $errors['portalConfig'] = $portalConfigErrors;
            }
        }

        return $errors;
    }

    /**
     * Validate user hierarchy creation data
     * Requirements: 10.1, 10.2, 10.3
     */
    public function validateUserHierarchyCreation(array $data): array
    {
        $errors = [];

        // Validate user data
        $userErrors = $this->validateUserData($data);
        if (!empty($userErrors)) {
            $errors = array_merge($errors, $userErrors);
        }

        // Validate hierarchy relationships
        $hierarchyErrors = $this->validateHierarchyRelationships($data);
        if (!empty($hierarchyErrors)) {
            $errors['hierarchy'] = $hierarchyErrors;
        }

        return $errors;
    }

    /**
     * Validate brand name with business rules
     * Requirements: 10.1, 10.3
     */
    private function validateBrandName(?string $brandName, ?int $excludeId = null): array
    {
        $errors = [];

        if (empty($brandName)) {
            $errors[] = 'Brand name is required and cannot be empty';
            return $errors;
        }

        if (!is_string($brandName)) {
            $errors[] = 'Brand name must be a string';
            return $errors;
        }

        $trimmedName = trim($brandName);
        if (empty($trimmedName)) {
            $errors[] = 'Brand name cannot contain only whitespace';
            return $errors;
        }

        if (strlen($trimmedName) < 2) {
            $errors[] = 'Brand name must be at least 2 characters long';
        }

        if (strlen($trimmedName) > 255) {
            $errors[] = 'Brand name cannot exceed 255 characters';
        }

        // Check for invalid characters
        if (!preg_match('/^[a-zA-Z0-9\s\-_&.()]+$/', $trimmedName)) {
            $errors[] = 'Brand name contains invalid characters. Only letters, numbers, spaces, hyphens, underscores, ampersands, periods, and parentheses are allowed';
        }

        // Check for uniqueness
        $existing = $this->shippingLineRepository->findByBrandName($trimmedName);
        if ($existing && ($excludeId === null || $existing->getId() !== $excludeId)) {
            $errors[] = 'A shipping line with this brand name already exists. Brand names must be unique across the system';
        }

        return $errors;
    }

    /**
     * Validate portal configuration structure and values
     * Requirements: 10.1, 10.3
     */
    private function validatePortalConfiguration($portalConfig): array
    {
        $errors = [];

        if ($portalConfig === null) {
            $errors[] = 'Portal configuration is required';
            return $errors;
        }

        if (!is_array($portalConfig)) {
            $errors[] = 'Portal configuration must be an array';
            return $errors;
        }

        // Validate branding configuration if present
        if (isset($portalConfig['branding'])) {
            $brandingErrors = $this->validateBrandingConfiguration($portalConfig['branding']);
            if (!empty($brandingErrors)) {
                $errors['branding'] = $brandingErrors;
            }
        }

        // Validate feature flags if present
        if (isset($portalConfig['features'])) {
            $featureErrors = $this->validateFeatureConfiguration($portalConfig['features']);
            if (!empty($featureErrors)) {
                $errors['features'] = $featureErrors;
            }
        }

        return $errors;
    }

    /**
     * Validate branding configuration
     * Requirements: 10.1, 10.3
     */
    private function validateBrandingConfiguration(array $branding): array
    {
        $errors = [];
        $allowedKeys = ['primaryColor', 'secondaryColor', 'logoUrl', 'faviconUrl', 'companyName', 'tagline'];

        foreach ($branding as $key => $value) {
            if (!in_array($key, $allowedKeys)) {
                $errors[] = "Invalid branding configuration key: '{$key}'. Allowed keys are: " . implode(', ', $allowedKeys);
            }
        }

        // Validate color formats
        if (isset($branding['primaryColor'])) {
            if (!$this->isValidColor($branding['primaryColor'])) {
                $errors[] = 'Primary color must be a valid hex color (e.g., #FF0000) or CSS color name';
            }
        }

        if (isset($branding['secondaryColor'])) {
            if (!$this->isValidColor($branding['secondaryColor'])) {
                $errors[] = 'Secondary color must be a valid hex color (e.g., #FF0000) or CSS color name';
            }
        }

        // Validate URLs
        if (isset($branding['logoUrl']) && !empty($branding['logoUrl'])) {
            if (!filter_var($branding['logoUrl'], FILTER_VALIDATE_URL)) {
                $errors[] = 'Logo URL must be a valid URL';
            }
        }

        if (isset($branding['faviconUrl']) && !empty($branding['faviconUrl'])) {
            if (!filter_var($branding['faviconUrl'], FILTER_VALIDATE_URL)) {
                $errors[] = 'Favicon URL must be a valid URL';
            }
        }

        // Validate text fields
        if (isset($branding['companyName'])) {
            if (!is_string($branding['companyName']) || strlen(trim($branding['companyName'])) === 0) {
                $errors[] = 'Company name must be a non-empty string';
            } elseif (strlen($branding['companyName']) > 255) {
                $errors[] = 'Company name cannot exceed 255 characters';
            }
        }

        if (isset($branding['tagline'])) {
            if (!is_string($branding['tagline'])) {
                $errors[] = 'Tagline must be a string';
            } elseif (strlen($branding['tagline']) > 500) {
                $errors[] = 'Tagline cannot exceed 500 characters';
            }
        }

        return $errors;
    }

    /**
     * Validate feature configuration
     * Requirements: 10.1, 10.3
     */
    private function validateFeatureConfiguration(array $features): array
    {
        $errors = [];
        $allowedFeatures = ['enableNotifications', 'enableReports', 'enableAdvancedSearch', 'enableBulkOperations'];

        foreach ($features as $feature => $enabled) {
            if (!in_array($feature, $allowedFeatures)) {
                $errors[] = "Invalid feature flag: '{$feature}'. Allowed features are: " . implode(', ', $allowedFeatures);
            }

            if (!is_bool($enabled)) {
                $errors[] = "Feature flag '{$feature}' must be a boolean value (true or false)";
            }
        }

        return $errors;
    }

    /**
     * Validate user data for hierarchy creation
     * Requirements: 10.1, 10.2, 10.3
     */
    private function validateUserData(array $data): array
    {
        $errors = [];

        // Validate email
        if (empty($data['email'])) {
            $errors['email'] = ['Email is required'];
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = ['Email must be a valid email address'];
        } else {
            // Check for duplicate email
            $userRepository = $this->entityManager->getRepository(User::class);
            $existing = $userRepository->findOneBy(['email' => $data['email']]);
            if ($existing) {
                $errors['email'] = ['A user with this email address already exists'];
            }
        }

        // Validate role
        if (empty($data['role'])) {
            $errors['role'] = ['Role is required'];
        } else {
            try {
                $role = UserRole::from($data['role']);
                // Additional role-specific validation can be added here
            } catch (\ValueError $e) {
                $validRoles = array_map(fn($role) => $role->value, UserRole::cases());
                $errors['role'] = ['Invalid role. Valid roles are: ' . implode(', ', $validRoles)];
            }
        }

        // Validate name fields
        if (empty($data['firstName'])) {
            $errors['firstName'] = ['First name is required'];
        } elseif (strlen(trim($data['firstName'])) < 2) {
            $errors['firstName'] = ['First name must be at least 2 characters long'];
        } elseif (strlen($data['firstName']) > 100) {
            $errors['firstName'] = ['First name cannot exceed 100 characters'];
        }

        if (empty($data['lastName'])) {
            $errors['lastName'] = ['Last name is required'];
        } elseif (strlen(trim($data['lastName'])) < 2) {
            $errors['lastName'] = ['Last name must be at least 2 characters long'];
        } elseif (strlen($data['lastName']) > 100) {
            $errors['lastName'] = ['Last name cannot exceed 100 characters'];
        }

        return $errors;
    }

    /**
     * Validate hierarchy relationships
     * Requirements: 10.2, 10.3
     */
    private function validateHierarchyRelationships(array $data): array
    {
        $errors = [];

        if (empty($data['role'])) {
            return $errors; // Role validation handled elsewhere
        }

        try {
            $role = UserRole::from($data['role']);
        } catch (\ValueError $e) {
            return $errors; // Role validation handled elsewhere
        }

        // Validate hierarchical role requirements
        $hierarchicalRoles = [UserRole::SL_STAFF, UserRole::EVALUATOR, UserRole::ACCOUNTING, UserRole::TERMINAL_TEAM];
        
        if (in_array($role, $hierarchicalRoles)) {
            if (empty($data['shippingLineAdminId'])) {
                $errors[] = "Role '{$role->value}' requires a shipping line admin to be specified";
            } else {
                // Validate the admin exists and is active
                $userRepository = $this->entityManager->getRepository(User::class);
                $admin = $userRepository->find($data['shippingLineAdminId']);
                if (!$admin) {
                    $errors[] = 'Specified shipping line admin does not exist';
                } elseif ($admin->getRole() !== UserRole::SHIPPING_LINES_ADMIN) {
                    $errors[] = 'Specified user is not a shipping line admin';
                } elseif (!$admin->isActive()) {
                    $errors[] = 'Specified shipping line admin is not active';
                }
            }
        }

        // Validate SHIPPING_LINES_ADMIN requirements
        if ($role === UserRole::SHIPPING_LINES_ADMIN) {
            if (empty($data['managedShippingLineId'])) {
                $errors[] = 'SHIPPING_LINES_ADMIN role requires a managed shipping line to be specified';
            } else {
                // Validate the shipping line exists and is active
                $shippingLine = $this->shippingLineRepository->find($data['managedShippingLineId']);
                if (!$shippingLine) {
                    $errors[] = 'Specified shipping line does not exist';
                } elseif (!$shippingLine->isActive()) {
                    $errors[] = 'Specified shipping line is not active';
                }
            }
        }

        return $errors;
    }

    /**
     * Validate initial admin for shipping line creation
     * Requirements: 10.1, 10.2, 10.3
     */
    private function validateInitialAdmin(int $adminId): array
    {
        $errors = [];

        $userRepository = $this->entityManager->getRepository(User::class);
        $admin = $userRepository->find($adminId);
        if (!$admin) {
            $errors[] = 'Specified initial admin does not exist';
            return $errors;
        }

        if ($admin->getRole() !== UserRole::SHIPPING_LINES_ADMIN) {
            $errors[] = 'Initial admin must have SHIPPING_LINES_ADMIN role';
        }

        if (!$admin->isActive()) {
            $errors[] = 'Initial admin must be active';
        }

        if ($admin->getManagedShippingLine() !== null) {
            $errors[] = 'Initial admin is already managing another shipping line';
        }

        return $errors;
    }

    /**
     * Validate color format (hex or CSS color name)
     * Requirements: 10.1, 10.3
     */
    private function isValidColor(string $color): bool
    {
        // Check hex color format
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            return true;
        }

        // Check CSS color names (basic set)
        $cssColors = [
            'red', 'green', 'blue', 'yellow', 'orange', 'purple', 'pink', 'brown',
            'black', 'white', 'gray', 'grey', 'navy', 'teal', 'lime', 'aqua',
            'maroon', 'olive', 'silver', 'fuchsia'
        ];

        return in_array(strtolower($color), $cssColors);
    }

    /**
     * Validate constraint violations and provide user-friendly messages
     * Requirements: 10.4, 10.5
     */
    public function handleConstraintViolation(\Exception $exception): array
    {
        $errors = [];
        $message = $exception->getMessage();

        // Handle foreign key constraint violations
        if (str_contains($message, 'foreign key constraint')) {
            if (str_contains($message, 'shipping_line_admin_id')) {
                $errors[] = 'Cannot create user: The specified shipping line admin does not exist or has been deleted';
            } elseif (str_contains($message, 'managed_shipping_line_id')) {
                $errors[] = 'Cannot create admin: The specified shipping line does not exist or has been deleted';
            } else {
                $errors[] = 'Cannot complete operation: Referenced data does not exist or has been deleted';
            }
        }

        // Handle unique constraint violations
        if (str_contains($message, 'unique constraint') || str_contains($message, 'duplicate entry')) {
            if (str_contains($message, 'brand_name')) {
                $errors[] = 'A shipping line with this brand name already exists. Please choose a different name';
            } elseif (str_contains($message, 'email')) {
                $errors[] = 'A user with this email address already exists. Please use a different email';
            } else {
                $errors[] = 'This data already exists in the system. Please check for duplicates';
            }
        }

        // Handle check constraint violations
        if (str_contains($message, 'check constraint')) {
            $errors[] = 'Data validation failed: One or more values do not meet the required constraints';
        }

        // Generic database error
        if (empty($errors)) {
            $this->logger->error('Unhandled database constraint violation', [
                'exception' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);
            $errors[] = 'A database constraint was violated. Please check your data and try again';
        }

        return $errors;
    }
}