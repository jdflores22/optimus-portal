<?php

namespace App\Service;

use App\Entity\ConfigurationHistory;
use App\Entity\SystemConfiguration;
use App\Entity\User;
use App\Repository\ConfigurationHistoryRepository;
use App\Repository\SystemConfigurationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Configuration Service
 * 
 * Manages system configuration with caching and validation
 * Task 20.2: Create configuration service
 */
class ConfigurationService
{
    private const CACHE_KEY_PREFIX = 'system_config_';
    private const CACHE_TTL = 3600; // 1 hour

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SystemConfigurationRepository $configRepository,
        private ConfigurationHistoryRepository $historyRepository,
        private CacheInterface $cache,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Get configuration value by key with caching
     */
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            return $this->cache->get(
                self::CACHE_KEY_PREFIX . $key,
                function (ItemInterface $item) use ($key, $default) {
                    $item->expiresAfter(self::CACHE_TTL);
                    
                    $config = $this->configRepository->findActiveByKey($key);
                    
                    if (!$config) {
                        $this->logger->warning('Configuration key not found', [
                            'key' => $key,
                            'using_default' => $default
                        ]);
                        return $default;
                    }
                    
                    return $config->getTypedValue();
                }
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve configuration', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return $default;
        }
    }

    /**
     * Get eDO validity period in days
     */
    public function getEDOValidityPeriod(): int
    {
        return (int) $this->get('edo.validity_period_days', 30);
    }

    /**
     * Get per-day rate for expired eDOs
     */
    public function getEDOExpiredPerDayRate(): float
    {
        return (float) $this->get('edo.expired_per_day_rate', 50.00);
    }

    /**
     * Get eDO fee amount
     */
    public function getEDOFee(): float
    {
        return (float) $this->get('edo.fee_amount', 500.00);
    }

    /**
     * Get CY locations with capacities
     * 
     * @return array<string, float> Array of CY location => TEU capacity
     */
    public function getCYLocations(): array
    {
        $locations = $this->get('cy.locations', []);
        
        if (!is_array($locations)) {
            $this->logger->error('CY locations configuration is not an array');
            return [];
        }
        
        return $locations;
    }

    /**
     * Get available capacity for a specific CY location
     */
    public function getCYCapacity(string $cyLocation): float
    {
        $locations = $this->getCYLocations();
        return (float) ($locations[$cyLocation] ?? 0.0);
    }

    /**
     * Set configuration value with validation and history tracking
     */
    public function set(string $key, mixed $value, string $valueType, User $user, ?string $reason = null): void
    {
        $this->entityManager->beginTransaction();
        
        try {
            // Validate the value
            $this->validateConfigValue($key, $value, $valueType);
            
            // Get existing configuration or create new
            $config = $this->configRepository->findActiveByKey($key);
            $isNew = false;
            
            if (!$config) {
                $config = new SystemConfiguration();
                $config->setConfigKey($key);
                $isNew = true;
            }
            
            // Store old value for history
            $oldValue = $isNew ? null : $config->getConfigValue();
            
            // Update configuration
            $config->setTypedValue($value, $valueType);
            $config->setUpdatedBy($user);
            
            if ($isNew) {
                $this->entityManager->persist($config);
            }
            
            // Create history entry if value changed
            if (!$isNew && $oldValue !== $config->getConfigValue()) {
                $history = new ConfigurationHistory();
                $history->setConfigKey($key);
                $history->setOldValue($oldValue);
                $history->setNewValue($config->getConfigValue());
                $history->setChangedBy($user);
                $history->setChangeReason($reason);
                
                $this->entityManager->persist($history);
            }
            
            $this->entityManager->flush();
            $this->entityManager->commit();
            
            // Invalidate cache
            $this->cache->delete(self::CACHE_KEY_PREFIX . $key);
            
            $this->logger->info('Configuration updated', [
                'key' => $key,
                'value' => $value,
                'user' => $user->getId(),
                'reason' => $reason
            ]);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to update configuration', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Validate configuration value
     */
    private function validateConfigValue(string $key, mixed $value, string $valueType): void
    {
        // Type validation
        match ($valueType) {
            'integer' => is_numeric($value) ?: throw new \InvalidArgumentException('Value must be numeric for integer type'),
            'float' => is_numeric($value) ?: throw new \InvalidArgumentException('Value must be numeric for float type'),
            'boolean' => is_bool($value) || in_array($value, ['0', '1', 'true', 'false']) ?: throw new \InvalidArgumentException('Value must be boolean'),
            'json' => is_array($value) || is_object($value) ?: throw new \InvalidArgumentException('Value must be array or object for json type'),
            'string' => is_string($value) || is_numeric($value) ?: throw new \InvalidArgumentException('Value must be string'),
            default => throw new \InvalidArgumentException('Invalid value type: ' . $valueType)
        };

        // Business rule validation
        if ($key === 'edo.validity_period_days') {
            $days = (int) $value;
            if ($days < 1 || $days > 365) {
                throw new \InvalidArgumentException('eDO validity period must be between 1 and 365 days');
            }
        }

        if ($key === 'edo.expired_per_day_rate') {
            $rate = (float) $value;
            if ($rate < 0) {
                throw new \InvalidArgumentException('Per-day rate cannot be negative');
            }
        }

        if ($key === 'edo.fee_amount') {
            $fee = (float) $value;
            if ($fee < 0) {
                throw new \InvalidArgumentException('eDO fee amount cannot be negative');
            }
        }

        if ($key === 'cy.locations') {
            if (!is_array($value)) {
                throw new \InvalidArgumentException('CY locations must be an array');
            }
            
            foreach ($value as $location => $capacity) {
                if (!is_string($location) || empty($location)) {
                    throw new \InvalidArgumentException('CY location must be a non-empty string');
                }
                if (!is_numeric($capacity) || $capacity < 0) {
                    throw new \InvalidArgumentException('CY capacity must be a non-negative number');
                }
            }
        }
    }

    /**
     * Get configuration history for a key
     */
    public function getHistory(string $key, int $limit = 50): array
    {
        return $this->historyRepository->findByConfigKey($key, $limit);
    }

    /**
     * Get all active configurations
     */
    public function getAllActive(): array
    {
        return $this->configRepository->findAllActive();
    }

    /**
     * Clear all configuration cache
     */
    public function clearCache(): void
    {
        try {
            $configs = $this->configRepository->findAllActive();
            foreach ($configs as $config) {
                $this->cache->delete(self::CACHE_KEY_PREFIX . $config->getConfigKey());
            }
            
            $this->logger->info('Configuration cache cleared');
        } catch (\Exception $e) {
            $this->logger->error('Failed to clear configuration cache', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
