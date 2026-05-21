<?php

declare(strict_types=1);

namespace App\Service\Avatar;

use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Cache\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Configuration reloader service for avatar colors system.
 * 
 * This service handles runtime configuration reloading without requiring
 * application restart, validates changes, and updates dependent services.
 */
class ConfigurationReloaderService
{
    private const CONFIG_CACHE_KEY = 'avatar_colors_config';
    private const CONFIG_FILE_PATH = 'config/packages/avatar_colors.yaml';

    public function __construct(
        private readonly ConfigurationValidatorServiceInterface $validator,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
        private ?AvatarColorServiceInterface $avatarColorService = null
    ) {
    }

    /**
     * Reload configuration from file with validation.
     */
    public function reloadConfiguration(): array
    {
        $configPath = $this->projectDir . '/' . self::CONFIG_FILE_PATH;
        
        try {
            // Validate the configuration file
            if (!$this->validator->validateConfigurationFile($configPath)) {
                $errors = $this->validator->getFormattedErrors();
                $this->logger->error('Avatar colors configuration validation failed', [
                    'errors' => $errors,
                    'file' => $configPath
                ]);
                throw new \InvalidArgumentException($errors);
            }

            // Load the validated configuration
            $config = Yaml::parseFile($configPath);
            $avatarConfig = $this->extractAvatarConfig($config);

            // Update cached configuration
            $this->updateCachedConfiguration($avatarConfig);

            // Clear avatar color cache to force regeneration with new config
            $this->clearAvatarColorCache();

            $this->logger->info('Avatar colors configuration reloaded successfully', [
                'file' => $configPath,
                'colors_count' => count($avatarConfig['colors'] ?? [])
            ]);

            return $avatarConfig;

        } catch (\Exception $e) {
            $this->logger->error('Failed to reload avatar colors configuration', [
                'error' => $e->getMessage(),
                'file' => $configPath
            ]);
            throw $e;
        }
    }

    /**
     * Get current configuration (from cache or file).
     */
    public function getCurrentConfiguration(): array
    {
        // Try to get from cache first
        $cached = $this->cache->get(self::CONFIG_CACHE_KEY, function() { return null; });
        if ($cached !== null) {
            return $cached;
        }

        // Load from file if not cached
        return $this->reloadConfiguration();
    }

    /**
     * Validate configuration without reloading.
     */
    public function validateCurrentConfiguration(): bool
    {
        $configPath = $this->projectDir . '/' . self::CONFIG_FILE_PATH;
        return $this->validator->validateConfigurationFile($configPath);
    }

    /**
     * Get configuration validation errors.
     */
    public function getValidationErrors(): array
    {
        return $this->validator->getValidationErrors();
    }

    /**
     * Update configuration with new values and reload.
     */
    public function updateConfiguration(array $newConfig): array
    {
        $configPath = $this->projectDir . '/' . self::CONFIG_FILE_PATH;

        // Validate new configuration
        if (!$this->validator->validateConfiguration($newConfig)) {
            $errors = $this->validator->getFormattedErrors();
            throw new \InvalidArgumentException($errors);
        }

        try {
            // Backup current configuration
            $backupPath = $configPath . '.backup.' . date('Y-m-d-H-i-s');
            copy($configPath, $backupPath);

            // Write new configuration
            $yamlContent = Yaml::dump(['parameters' => ['avatar_colors' => $newConfig]], 4, 2);
            file_put_contents($configPath, $yamlContent);

            // Reload and return new configuration
            $reloadedConfig = $this->reloadConfiguration();

            $this->logger->info('Avatar colors configuration updated successfully', [
                'backup_file' => $backupPath,
                'config_file' => $configPath
            ]);

            return $reloadedConfig;

        } catch (\Exception $e) {
            // Restore backup if update failed
            if (isset($backupPath) && file_exists($backupPath)) {
                copy($backupPath, $configPath);
                unlink($backupPath);
            }

            $this->logger->error('Failed to update avatar colors configuration', [
                'error' => $e->getMessage(),
                'config_file' => $configPath
            ]);

            throw $e;
        }
    }

    /**
     * Check if configuration file has been modified.
     */
    public function isConfigurationModified(): bool
    {
        $configPath = $this->projectDir . '/' . self::CONFIG_FILE_PATH;
        
        if (!file_exists($configPath)) {
            return false;
        }

        $fileModTime = filemtime($configPath);
        $cachedModTime = $this->cache->get(self::CONFIG_CACHE_KEY . '_mtime', function() { return 0; });

        return $fileModTime > $cachedModTime;
    }

    /**
     * Extract avatar colors configuration from full config array.
     */
    private function extractAvatarConfig(array $config): array
    {
        // Handle different configuration structures
        if (isset($config['parameters']['avatar_colors'])) {
            return $config['parameters']['avatar_colors'];
        }

        if (isset($config['avatar_colors'])) {
            return $config['avatar_colors'];
        }

        // Assume direct structure
        return $config;
    }

    /**
     * Update cached configuration.
     */
    private function updateCachedConfiguration(array $config): void
    {
        $configPath = $this->projectDir . '/' . self::CONFIG_FILE_PATH;
        $fileModTime = file_exists($configPath) ? filemtime($configPath) : time();

        // Cache configuration with 1 hour TTL
        $this->cache->delete(self::CONFIG_CACHE_KEY);
        $this->cache->get(self::CONFIG_CACHE_KEY, function() use ($config) {
            return $config;
        }, 3600);

        // Cache modification time
        $this->cache->delete(self::CONFIG_CACHE_KEY . '_mtime');
        $this->cache->get(self::CONFIG_CACHE_KEY . '_mtime', function() use ($fileModTime) {
            return $fileModTime;
        }, 3600);
    }

    /**
     * Clear avatar color cache to force regeneration.
     */
    private function clearAvatarColorCache(): void
    {
        try {
            // Clear avatar color cache if service is available
            if ($this->avatarColorService !== null) {
                $this->avatarColorService->invalidateCacheOnConfigChange();
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to clear avatar color cache after configuration reload', [
                'error' => $e->getMessage()
            ]);
        }
    }
}