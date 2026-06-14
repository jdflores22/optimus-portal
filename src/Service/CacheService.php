<?php

namespace App\Service;

use App\Entity\FormConfiguration;
use App\Entity\User;
use App\Entity\Enum\FormType;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing application-level caching
 * Handles form configurations, user permissions, and dashboard metrics caching
 */
class CacheService
{
    public function __construct(
        private CacheInterface $formConfigurationsCache,
        private CacheInterface $userPermissionsCache,
        private CacheInterface $dashboardMetricsCache,
        private CacheInterface $fileMetadataCache,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Get cached form configuration by type
     */
    public function getActiveFormConfiguration(FormType $type): ?FormConfiguration
    {
        $cacheKey = sprintf('active_form_%s', $type->value);
        
        try {
            return $this->formConfigurationsCache->get($cacheKey, function (ItemInterface $item) use ($type) {
                $this->logger->info('Cache miss for form configuration', ['type' => $type->value]);
                // This will be populated by the FormBuilderService when called
                return null;
            });
        } catch (\Exception $e) {
            $this->logger->error('Failed to get form configuration from cache', [
                'type' => $type->value,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Cache active form configuration
     */
    public function cacheActiveFormConfiguration(FormType $type, FormConfiguration $formConfig): void
    {
        $cacheKey = sprintf('active_form_%s', $type->value);
        
        try {
            $this->formConfigurationsCache->delete($cacheKey);
            $this->formConfigurationsCache->get($cacheKey, function (ItemInterface $item) use ($formConfig) {
                $item->expiresAfter(3600); // 1 hour
                return $formConfig;
            });
            
            $this->logger->info('Cached form configuration', [
                'type' => $type->value,
                'form_id' => $formConfig->getId()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to cache form configuration', [
                'type' => $type->value,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Invalidate form configuration cache when forms are published
     */
    public function invalidateFormConfiguration(FormType $type): void
    {
        $cacheKey = sprintf('active_form_%s', $type->value);
        
        try {
            $this->formConfigurationsCache->delete($cacheKey);
            $this->logger->info('Invalidated form configuration cache', ['type' => $type->value]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to invalidate form configuration cache', [
                'type' => $type->value,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get cached user permissions
     */
    public function getUserPermissions(User $user): array
    {
        $cacheKey = sprintf('user_permissions_%d', $user->getId());
        
        try {
            return $this->userPermissionsCache->get($cacheKey, function (ItemInterface $item) use ($user) {
                $item->expiresAfter(1800); // 30 minutes
                $this->logger->info('Cache miss for user permissions', ['user_id' => $user->getId()]);
                
                // Build permissions array based on user roles
                $permissions = [];
                $roles = $user->getRoles();
                
                foreach ($roles as $role) {
                    switch ($role) {
                        case 'ROLE_CONSIGNEE':
                            $permissions = array_merge($permissions, [
                                'accreditation.submit',
                                'accreditation.view_own',
                                'shipment.view_own',
                                'payment.submit',
                                'payment.view_own'
                            ]);
                            break;
                        case 'ROLE_BROKER':
                            $permissions = array_merge($permissions, [
                                'shipment.view_linked',
                                'shipment.search',
                                'edo.access',
                                'payment.verify'
                            ]);
                            break;
                        case 'ROLE_EVALUATOR':
                            $permissions = array_merge($permissions, [
                                'accreditation.evaluate',
                                'accreditation.view_all',
                                'accreditation.approve',
                                'accreditation.deny'
                            ]);
                            break;
                        case 'ROLE_SL_STAFF':
                            $permissions = array_merge($permissions, [
                                'shipment.manage',
                                'payment.manage',
                                'user.view',
                                'reports.view'
                            ]);
                            break;
                        case 'ROLE_ACCOUNTING':
                            $permissions = array_merge($permissions, [
                                'payment.view_all',
                                'payment.verify_all',
                                'financial.reports',
                                'audit.financial'
                            ]);
                            break;
                        case 'ROLE_SYSTEM_ADMIN':
                            $permissions = array_merge($permissions, [
                                'user.manage',
                                'system.configure',
                                'audit.view_all',
                                'form.manage',
                                'reports.all'
                            ]);
                            break;
                    }
                }
                
                return array_unique($permissions);
            });
        } catch (\Exception $e) {
            $this->logger->error('Failed to get user permissions from cache', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Invalidate user permissions cache
     */
    public function invalidateUserPermissions(User $user): void
    {
        $cacheKey = sprintf('user_permissions_%d', $user->getId());
        
        try {
            $this->userPermissionsCache->delete($cacheKey);
            $this->logger->info('Invalidated user permissions cache', ['user_id' => $user->getId()]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to invalidate user permissions cache', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get cached dashboard metrics for a user role
     */
    public function getDashboardMetrics(string $role): array
    {
        $cacheKey = sprintf('dashboard_metrics_%s', $role);
        
        try {
            return $this->dashboardMetricsCache->get($cacheKey, function (ItemInterface $item) use ($role) {
                $item->expiresAfter(300); // 5 minutes
                $this->logger->info('Cache miss for dashboard metrics', ['role' => $role]);
                
                // Return empty array - will be populated by dashboard services
                return [];
            });
        } catch (\Exception $e) {
            $this->logger->error('Failed to get dashboard metrics from cache', [
                'role' => $role,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Cache dashboard metrics
     */
    public function cacheDashboardMetrics(string $role, array $metrics): void
    {
        $cacheKey = sprintf('dashboard_metrics_%s', $role);
        
        try {
            $this->dashboardMetricsCache->delete($cacheKey);
            $this->dashboardMetricsCache->get($cacheKey, function (ItemInterface $item) use ($metrics) {
                $item->expiresAfter(300); // 5 minutes
                return $metrics;
            });
            
            $this->logger->info('Cached dashboard metrics', [
                'role' => $role,
                'metrics_count' => count($metrics)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to cache dashboard metrics', [
                'role' => $role,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Invalidate dashboard metrics cache
     */
    public function invalidateDashboardMetrics(string $role): void
    {
        $cacheKey = sprintf('dashboard_metrics_%s', $role);
        
        try {
            $this->dashboardMetricsCache->delete($cacheKey);
            $this->logger->info('Invalidated dashboard metrics cache', ['role' => $role]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to invalidate dashboard metrics cache', [
                'role' => $role,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get cached file metadata
     */
    public function getFileMetadata(string $fileHash): ?array
    {
        $cacheKey = sprintf('file_metadata_%s', $fileHash);
        
        try {
            return $this->fileMetadataCache->get($cacheKey, function (ItemInterface $item) use ($fileHash) {
                $item->expiresAfter(7200); // 2 hours
                $this->logger->info('Cache miss for file metadata', ['file_hash' => $fileHash]);
                return null;
            });
        } catch (\Exception $e) {
            $this->logger->error('Failed to get file metadata from cache', [
                'file_hash' => $fileHash,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Cache file metadata
     */
    public function cacheFileMetadata(string $fileHash, array $metadata): void
    {
        $cacheKey = sprintf('file_metadata_%s', $fileHash);
        
        try {
            $this->fileMetadataCache->delete($cacheKey);
            $this->fileMetadataCache->get($cacheKey, function (ItemInterface $item) use ($metadata) {
                $item->expiresAfter(7200); // 2 hours
                return $metadata;
            });
            
            $this->logger->info('Cached file metadata', [
                'file_hash' => $fileHash,
                'metadata_keys' => array_keys($metadata)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to cache file metadata', [
                'file_hash' => $fileHash,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Invalidate file metadata cache
     */
    public function invalidateFileMetadata(string $fileHash): void
    {
        $cacheKey = sprintf('file_metadata_%s', $fileHash);
        
        try {
            $this->fileMetadataCache->delete($cacheKey);
            $this->logger->info('Invalidated file metadata cache', ['file_hash' => $fileHash]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to invalidate file metadata cache', [
                'file_hash' => $fileHash,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Invalidate avatar colors cache for a specific user
     */
    public function invalidateAvatarColors(string $userIdentifierHash): void
    {
        // Note: This is a simplified implementation
        // The actual cache invalidation is handled by AvatarColorService
        // This method is provided for consistency with other cache operations
        try {
            $this->logger->info('Avatar colors cache invalidation requested', [
                'user_identifier_hash' => $userIdentifierHash
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to log avatar colors cache invalidation', [
                'user_identifier_hash' => $userIdentifierHash,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Invalidate all avatar colors cache
     */
    public function invalidateAllAvatarColors(): void
    {
        try {
            $this->logger->info('All avatar colors cache invalidation requested');
        } catch (\Exception $e) {
            $this->logger->error('Failed to log avatar colors cache invalidation', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Clear all caches
     */
    public function clearAllCaches(): void
    {
        try {
            $this->formConfigurationsCache->clear();
            $this->userPermissionsCache->clear();
            $this->dashboardMetricsCache->clear();
            $this->fileMetadataCache->clear();
            
            $this->logger->info('Cleared all application caches');
        } catch (\Exception $e) {
            $this->logger->error('Failed to clear all caches', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Warm up critical caches
     */
    public function warmUpCaches(): void
    {
        try {
            // Warm up form configurations for all types
            foreach (FormType::cases() as $formType) {
                $this->getActiveFormConfiguration($formType);
            }
            
            $this->logger->info('Warmed up critical caches');
        } catch (\Exception $e) {
            $this->logger->error('Failed to warm up caches', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Check if user has cached permission
     */
    public function userHasPermission(User $user, string $permission): bool
    {
        $permissions = $this->getUserPermissions($user);
        return in_array($permission, $permissions, true);
    }
}