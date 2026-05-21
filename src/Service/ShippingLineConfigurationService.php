<?php

namespace App\Service;

use App\Entity\ShippingLine;
use App\Entity\ShippingLineConfiguration;
use App\Entity\ConfigurationHistory;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ShippingLineConfigurationService
{
    private EntityManagerInterface $entityManager;
    private ValidatorInterface $validator;
    private ActivityLogService $activityLogService;

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
     * Creates or updates a shipping line configuration
     */
    public function setConfiguration(
        ShippingLine $shippingLine,
        string $configKey,
        array $configValue,
        User $user,
        ?string $description = null
    ): ShippingLineConfiguration {
        $this->validateUserPermissions($user, $shippingLine);

        $repository = $this->entityManager->getRepository(ShippingLineConfiguration::class);
        $config = $repository->findOneBy([
            'shippingLine' => $shippingLine,
            'configKey' => $configKey
        ]);

        $oldValue = null;
        $action = 'create';

        if ($config) {
            $oldValue = $config->getConfigValue();
            $action = 'update';
            $config->setUpdatedBy($user);
        } else {
            $config = new ShippingLineConfiguration();
            $config->setShippingLine($shippingLine);
            $config->setConfigKey($configKey);
            $config->setCreatedBy($user);
        }

        $config->setConfigValue($configValue);
        if ($description !== null) {
            $config->setDescription($description);
        }

        // Validate the configuration
        $errors = $this->validator->validate($config);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            throw new \InvalidArgumentException('Configuration validation failed: ' . implode(', ', $errorMessages));
        }

        try {
            $this->entityManager->persist($config);
            $this->entityManager->flush();

            // Create history record
            $this->createHistoryRecord(
                $shippingLine,
                'shipping_line_config',
                $configKey,
                $oldValue,
                $configValue,
                $action,
                $user
            );

            // Log the activity
            $this->activityLogService->logConfigChange($user, $configKey, $oldValue, $configValue);

            return $config;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to save configuration: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Gets a configuration value for a shipping line
     */
    public function getConfiguration(ShippingLine $shippingLine, string $configKey): ?ShippingLineConfiguration
    {
        $repository = $this->entityManager->getRepository(ShippingLineConfiguration::class);
        return $repository->findOneBy([
            'shippingLine' => $shippingLine,
            'configKey' => $configKey,
            'isActive' => true
        ]);
    }

    /**
     * Gets all configurations for a shipping line
     */
    public function getAllConfigurations(ShippingLine $shippingLine): array
    {
        $repository = $this->entityManager->getRepository(ShippingLineConfiguration::class);
        return $repository->findBy([
            'shippingLine' => $shippingLine,
            'isActive' => true
        ], ['configKey' => 'ASC']);
    }

    /**
     * Gets a configuration value with fallback to default
     */
    public function getConfigurationValue(ShippingLine $shippingLine, string $configKey, $default = null)
    {
        $config = $this->getConfiguration($shippingLine, $configKey);
        if ($config) {
            return $config->getConfigValue();
        }

        // Try to get default configuration
        $defaultConfig = $this->getDefaultConfiguration($configKey);
        return $defaultConfig ?? $default;
    }

    /**
     * Deletes a configuration
     */
    public function deleteConfiguration(ShippingLine $shippingLine, string $configKey, User $user): void
    {
        $this->validateUserPermissions($user, $shippingLine);

        $config = $this->getConfiguration($shippingLine, $configKey);
        if (!$config) {
            throw new \InvalidArgumentException('Configuration not found');
        }

        $oldValue = $config->getConfigValue();

        try {
            $this->entityManager->remove($config);
            $this->entityManager->flush();

            // Create history record
            $this->createHistoryRecord(
                $shippingLine,
                'shipping_line_config',
                $configKey,
                $oldValue,
                [],
                'delete',
                $user
            );

            // Log the activity
            $this->activityLogService->logConfigChange($user, $configKey, $oldValue, null);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to delete configuration: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Updates branding configuration
     */
    public function updateBrandingConfiguration(
        ShippingLine $shippingLine,
        array $brandingConfig,
        User $user
    ): void {
        $this->validateUserPermissions($user, $shippingLine);

        // Validate branding configuration structure
        $this->validateBrandingConfiguration($brandingConfig);

        $oldConfig = $shippingLine->getPortalConfig();
        $newConfig = array_merge($oldConfig ?? [], ['branding' => $brandingConfig]);

        try {
            $shippingLine->setPortalConfig($newConfig);
            $this->entityManager->flush();

            // Create history record
            $this->createHistoryRecord(
                $shippingLine,
                'branding',
                'portal_branding',
                $oldConfig['branding'] ?? null,
                $brandingConfig,
                'update',
                $user
            );

            // Log the activity
            $this->activityLogService->logBrandingChange($user, $shippingLine, [
                'branding' => ['old' => $oldConfig['branding'] ?? null, 'new' => $brandingConfig]
            ]);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to update branding configuration: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Gets configuration history for a shipping line
     */
    public function getConfigurationHistory(
        ShippingLine $shippingLine,
        ?string $configType = null,
        ?string $configKey = null,
        int $limit = 50
    ): array {
        $repository = $this->entityManager->getRepository(ConfigurationHistory::class);
        $criteria = ['shippingLine' => $shippingLine];

        if ($configType) {
            $criteria['configType'] = $configType;
        }

        if ($configKey) {
            $criteria['configKey'] = $configKey;
        }

        return $repository->findBy($criteria, ['createdAt' => 'DESC'], $limit);
    }

    /**
     * Gets default configuration for a key
     */
    private function getDefaultConfiguration(string $configKey)
    {
        $defaultConfigurations = [
            'portal_theme' => [
                'primaryColor' => '#007bff',
                'secondaryColor' => '#6c757d',
                'logoUrl' => null,
                'faviconUrl' => null
            ],
            'notification_settings' => [
                'emailNotifications' => true,
                'smsNotifications' => false,
                'pushNotifications' => true
            ],
            'security_settings' => [
                'sessionTimeout' => 1800,
                'maxLoginAttempts' => 5,
                'passwordExpiry' => 90
            ],
            'feature_flags' => [
                'enableAdvancedReporting' => true,
                'enableBulkOperations' => true,
                'enableApiAccess' => false
            ]
        ];

        return $defaultConfigurations[$configKey] ?? null;
    }

    /**
     * Validates branding configuration structure
     */
    private function validateBrandingConfiguration(array $brandingConfig): void
    {
        $allowedKeys = ['primaryColor', 'secondaryColor', 'logoUrl', 'faviconUrl', 'companyName', 'tagline'];
        
        foreach ($brandingConfig as $key => $value) {
            if (!in_array($key, $allowedKeys)) {
                throw new \InvalidArgumentException("Invalid branding configuration key: {$key}");
            }
        }

        // Validate color formats if provided
        if (isset($brandingConfig['primaryColor']) && !$this->isValidColor($brandingConfig['primaryColor'])) {
            throw new \InvalidArgumentException('Invalid primary color format');
        }

        if (isset($brandingConfig['secondaryColor']) && !$this->isValidColor($brandingConfig['secondaryColor'])) {
            throw new \InvalidArgumentException('Invalid secondary color format');
        }
    }

    /**
     * Validates color format (hex)
     */
    private function isValidColor(string $color): bool
    {
        return preg_match('/^#[a-fA-F0-9]{6}$/', $color) === 1;
    }

    /**
     * Validates user permissions for configuration operations
     */
    private function validateUserPermissions(User $user, ShippingLine $shippingLine): void
    {
        if ($user->getRole() === UserRole::SYSTEM_ADMIN) {
            return; // SYSTEM_ADMIN can configure any shipping line
        }

        if ($user->getRole() === UserRole::SHIPPING_LINES_ADMIN && 
            $user->getManagedShippingLine() === $shippingLine) {
            return; // SHIPPING_LINES_ADMIN can configure their own shipping line
        }

        throw new \InvalidArgumentException('Insufficient permissions to modify shipping line configuration');
    }

    /**
     * Creates a history record for configuration changes
     */
    private function createHistoryRecord(
        ShippingLine $shippingLine,
        string $configType,
        string $configKey,
        ?array $oldValue,
        array $newValue,
        string $action,
        User $user,
        ?string $reason = null
    ): void {
        $history = ConfigurationHistory::createForConfigChange(
            $shippingLine,
            $configType,
            $configKey,
            $oldValue,
            $newValue,
            $action,
            $user,
            $reason
        );

        $this->entityManager->persist($history);
        $this->entityManager->flush();
    }
}