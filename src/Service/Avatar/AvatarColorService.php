<?php

declare(strict_types=1);

namespace App\Service\Avatar;

use App\Entity\User;
use App\Entity\StaffUser;
use App\Entity\TerminalTeamUser;
use App\Entity\Broker;
use App\Entity\Consignee;
use App\ValueObject\AvatarColorResult;
use Symfony\Contracts\Cache\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Main avatar color service orchestrating color generation and caching.
 * 
 * This service provides the primary API for generating avatar colors,
 * handles user identifier extraction, caching, and error recovery.
 */
class AvatarColorService implements AvatarColorServiceInterface
{
    private const CACHE_KEY_PREFIX = 'avatar_colors';
    private const DEFAULT_TTL = 3600; // 1 hour

    public function __construct(
        private readonly ColorGeneratorServiceInterface $colorGenerator,
        private readonly AccessibilityValidatorServiceInterface $accessibilityValidator,
        private readonly CacheInterface $cache,
        private readonly array $avatarColorsConfig,
        private readonly ConfigurationValidatorServiceInterface $configValidator,
        private readonly LoggerInterface $logger
    ) {
        // Validate configuration on service initialization
        if (!$this->configValidator->validateConfiguration($this->avatarColorsConfig)) {
            $errors = $this->configValidator->getFormattedErrors();
            $this->logger->error('Avatar colors configuration validation failed', [
                'errors' => $errors,
                'config' => $this->avatarColorsConfig
            ]);
            // Continue with default configuration rather than failing completely
        } else {
            $this->logger->info('Avatar colors configuration validated successfully');
        }
    }

    /**
     * Generate avatar colors for a user entity.
     */
    public function getAvatarColors(User $user, array $options = []): AvatarColorResult
    {
        try {
            $identifier = $this->extractUserIdentifier($user);
            $role = $user->getRole()->value ?? null;
            
            $this->logger->debug('Generating avatar colors for user', [
                'user_id' => $user->getId(),
                'user_type' => get_class($user),
                'identifier_hash' => hash('sha256', $identifier),
                'role' => $role,
                'options' => $options
            ]);
            
            return $this->getAvatarColorsFromIdentifier($identifier, $role, $options);
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate avatar colors for user', [
                'user_id' => $user->getId() ?? 'unknown',
                'user_type' => get_class($user),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->createFallbackResult('user_error');
        }
    }

    /**
     * Generate avatar colors from a string identifier.
     */
    public function getAvatarColorsFromIdentifier(string $identifier, ?string $role = null, array $options = []): AvatarColorResult
    {
        // Validate input
        if (empty(trim($identifier))) {
            $this->logger->warning('Empty identifier provided for avatar color generation', [
                'identifier' => $identifier,
                'role' => $role
            ]);
            return $this->createFallbackResult('empty_identifier');
        }

        try {
            // Generate cache key
            $cacheKey = $this->generateCacheKey($identifier, $role, $options);
            
            // Try to get from cache first
            if ($this->isCacheEnabled()) {
                try {
                    $cached = $this->cache->get($cacheKey, function() { return null; });
                    if ($cached !== null) {
                        $result = $this->deserializeResult($cached);
                        $this->logger->debug('Avatar colors retrieved from cache', [
                            'cache_key' => $cacheKey,
                            'identifier_hash' => hash('sha256', $identifier)
                        ]);
                        return $result;
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('Cache retrieval failed, generating colors without cache', [
                        'cache_key' => $cacheKey,
                        'error' => $e->getMessage()
                    ]);
                    // Continue without cache
                }
            }

            // Generate new colors
            $result = $this->generateColors($identifier, $role, $options);
            
            // Cache the result
            if ($this->isCacheEnabled()) {
                try {
                    $ttl = $this->avatarColorsConfig['cache']['ttl'] ?? self::DEFAULT_TTL;
                    $this->cache->delete($cacheKey); // Clear any existing cache
                    $this->cache->get($cacheKey, function() use ($result) {
                        return $this->serializeResult($result);
                    }, $ttl);
                    
                    $this->logger->debug('Avatar colors cached successfully', [
                        'cache_key' => $cacheKey,
                        'ttl' => $ttl
                    ]);
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to cache avatar colors', [
                        'cache_key' => $cacheKey,
                        'error' => $e->getMessage()
                    ]);
                    // Continue without caching - result is still valid
                }
            }
            
            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Avatar color generation failed completely', [
                'identifier_hash' => hash('sha256', $identifier),
                'role' => $role,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->createFallbackResult('generation_error');
        }
    }

    /**
     * Clear cached colors for a user or all users.
     */
    public function clearCache(?User $user = null): void
    {
        try {
            if ($user === null) {
                // Clear all avatar color cache entries using pattern matching
                $this->invalidateAllAvatarColorCache();
                $this->logger->info('Cleared all avatar color cache entries');
            } else {
                // Clear cache for specific user (all role variations)
                $this->invalidateUserAvatarCache($user);
                
                $this->logger->info('Cleared avatar color cache for user', [
                    'user_id' => $user->getId()
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to clear avatar color cache', [
                'user_id' => $user?->getId(),
                'error' => $e->getMessage()
            ]);
            // Don't throw exception - cache clearing failure shouldn't break the application
        }
    }

    /**
     * Warm up cache for frequently accessed users.
     */
    public function warmUpCache(array $users = []): void
    {
        if (empty($users)) {
            $this->logger->info('No users provided for cache warming');
            return;
        }

        $warmedCount = 0;
        $failedCount = 0;

        foreach ($users as $user) {
            try {
                if (!$user instanceof User) {
                    $this->logger->warning('Invalid user object provided for cache warming', [
                        'user_type' => gettype($user)
                    ]);
                    $failedCount++;
                    continue;
                }

                // Warm up cache for user's default role
                $this->getAvatarColors($user);
                $warmedCount++;

                // If role variations are enabled, warm up common role variations
                if ($this->isRoleVariationEnabled()) {
                    $this->warmUpRoleVariations($user);
                }

            } catch (\Exception $e) {
                $this->logger->warning('Failed to warm up cache for user', [
                    'user_id' => $user->getId() ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                $failedCount++;
            }
        }

        $this->logger->info('Cache warming completed', [
            'warmed_count' => $warmedCount,
            'failed_count' => $failedCount,
            'total_users' => count($users)
        ]);
    }

    /**
     * Invalidate cache when configuration changes.
     */
    public function invalidateCacheOnConfigChange(): void
    {
        try {
            $this->invalidateAllAvatarColorCache();
            $this->logger->info('Invalidated all avatar color cache due to configuration change');
        } catch (\Exception $e) {
            $this->logger->error('Failed to invalidate cache on configuration change', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get cache statistics for monitoring.
     */
    public function getCacheStats(): array
    {
        try {
            // This is a simplified implementation - in production you might want
            // to use Redis commands to get actual cache statistics
            return [
                'cache_enabled' => $this->isCacheEnabled(),
                'cache_ttl' => $this->avatarColorsConfig['cache']['ttl'] ?? self::DEFAULT_TTL,
                'cache_prefix' => $this->avatarColorsConfig['cache']['key_prefix'] ?? self::CACHE_KEY_PREFIX,
                'timestamp' => time()
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get cache statistics', [
                'error' => $e->getMessage()
            ]);
            return [
                'cache_enabled' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Extract identifier from user entity following priority order.
     */
    private function extractUserIdentifier(User $user): string
    {
        try {
            // Priority 1: firstName + lastName (for StaffUser and TerminalTeamUser)
            if ($user instanceof StaffUser || $user instanceof TerminalTeamUser) {
                $firstName = $user->getFirstName();
                $lastName = $user->getLastName();
                if (!empty($firstName) && !empty($lastName)) {
                    return $firstName . ' ' . $lastName;
                }
            }

            // Priority 2: fullName (for Broker)
            if ($user instanceof Broker) {
                $fullName = $user->getFullName();
                if (!empty($fullName)) {
                    return $fullName;
                }
            }

            // Priority 3: businessName (for Consignee)
            if ($user instanceof Consignee) {
                $businessName = $user->getBusinessName();
                if (!empty($businessName)) {
                    return $businessName;
                }
            }

            // Priority 4: email (fallback)
            $email = $user->getEmail();
            if (!empty($email)) {
                $this->logger->debug('Using email as user identifier fallback', [
                    'user_id' => $user->getId(),
                    'user_type' => get_class($user)
                ]);
                return $email;
            }

            // Priority 5: user ID (ultimate fallback)
            $fallbackId = 'user_' . $user->getId();
            $this->logger->warning('Using user ID as ultimate fallback identifier', [
                'user_id' => $user->getId(),
                'user_type' => get_class($user),
                'fallback_identifier' => $fallbackId
            ]);
            return $fallbackId;
        } catch (\Exception $e) {
            $this->logger->error('Failed to extract user identifier', [
                'user_id' => $user->getId() ?? 'unknown',
                'user_type' => get_class($user),
                'error' => $e->getMessage()
            ]);
            
            // Ultimate fallback - use a generic identifier
            return 'user_unknown_' . time();
        }
    }

    /**
     * Generate colors for an identifier and role.
     */
    private function generateColors(string $identifier, ?string $role, array $options): AvatarColorResult
    {
        try {
            // For role-based colors, use the role as the primary identifier
            $colorIdentifier = $role ?? 'DEFAULT';
            
            $this->logger->debug('Generating role-based colors', [
                'original_identifier_hash' => hash('sha256', $identifier),
                'role' => $role,
                'color_identifier' => $colorIdentifier
            ]);

            // Get role-specific color from configuration
            $roleColors = $this->avatarColorsConfig['role_colors'] ?? [];
            
            if (isset($roleColors[$colorIdentifier])) {
                $colorConfig = $roleColors[$colorIdentifier];
                
                // Validate accessibility
                try {
                    $contrastRatio = $this->accessibilityValidator->getContrastRatio(
                        $colorConfig['hex_bg'],
                        $colorConfig['hex_text']
                    );

                    // If contrast is insufficient, suggest better text color
                    if (!$this->accessibilityValidator->validateContrast($colorConfig['hex_bg'], $colorConfig['hex_text'])) {
                        $this->logger->info('Adjusting text color for better accessibility', [
                            'role' => $role,
                            'background_color' => $colorConfig['hex_bg'],
                            'original_text_color' => $colorConfig['hex_text'],
                            'original_contrast_ratio' => $contrastRatio
                        ]);
                        
                        $suggestedTextClass = $this->accessibilityValidator->suggestTextColor($colorConfig['hex_bg']);
                        $suggestedTextHex = $suggestedTextClass === 'text-white' ? '#FFFFFF' : '#000000';
                        
                        $colorConfig['text'] = $suggestedTextClass;
                        $colorConfig['hex_text'] = $suggestedTextHex;
                        
                        // Recalculate contrast ratio
                        $contrastRatio = $this->accessibilityValidator->getContrastRatio(
                            $colorConfig['hex_bg'],
                            $colorConfig['hex_text']
                        );
                        
                        $this->logger->info('Text color adjusted for accessibility', [
                            'role' => $role,
                            'new_text_color' => $colorConfig['hex_text'],
                            'new_contrast_ratio' => $contrastRatio
                        ]);
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Accessibility validation failed for role-based color', [
                        'role' => $role,
                        'color_config' => $colorConfig,
                        'error' => $e->getMessage()
                    ]);
                    return $this->createFallbackResult('accessibility_validation_error');
                }
                
                $result = AvatarColorResult::fromConfig(
                    $colorConfig,
                    $contrastRatio,
                    false // Role-based colors are not variations, they are the primary colors
                );
                
                $this->logger->debug('Role-based avatar colors generated successfully', [
                    'role' => $role,
                    'background_class' => $result->backgroundClass,
                    'text_class' => $result->textClass,
                    'contrast_ratio' => $result->contrastRatio
                ]);
                
                return $result;
            }
            
            // Fallback to default color if role not found
            $defaultColor = $this->avatarColorsConfig['default_color'] ?? [
                'bg' => 'bg-gray-500',
                'text' => 'text-white',
                'hex_bg' => '#6B7280',
                'hex_text' => '#FFFFFF'
            ];
            
            $this->logger->warning('Role not found in configuration, using default color', [
                'role' => $role,
                'available_roles' => array_keys($roleColors)
            ]);
            
            $contrastRatio = $this->accessibilityValidator->getContrastRatio(
                $defaultColor['hex_bg'],
                $defaultColor['hex_text']
            );
            
            return AvatarColorResult::fromConfig(
                $defaultColor,
                $contrastRatio,
                false
            );
            
        } catch (\Exception $e) {
            $this->logger->error('Role-based color generation failed', [
                'identifier_hash' => hash('sha256', $identifier),
                'role' => $role,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->createFallbackResult('role_generation_error');
        }
    }

    /**
     * Create fallback result for error cases.
     */
    private function createFallbackResult(string $reason = 'unknown'): AvatarColorResult
    {
        $this->logger->info('Creating fallback avatar colors', [
            'reason' => $reason,
            'fallback_background' => 'bg-gray-500',
            'fallback_text' => 'text-white'
        ]);
        
        return new AvatarColorResult(
            backgroundClass: 'bg-gray-500',
            textClass: 'text-white',
            backgroundColor: '#6B7280', // Gray-500 color
            textColor: '#FFFFFF',
            contrastRatio: 8.59, // High contrast ratio
            isRoleVariation: false
        );
    }

    /**
     * Generate cache key for identifier and options.
     */
    private function generateCacheKey(string $identifier, ?string $role, array $options): string
    {
        try {
            $keyPrefix = $this->avatarColorsConfig['cache']['key_prefix'] ?? self::CACHE_KEY_PREFIX;
            $identifierHash = hash('sha256', $identifier);
            $roleString = $role ?? 'no_role';
            $optionsHash = empty($options) ? 'default' : hash('sha256', serialize($options));
            
            // Use underscores instead of colons to avoid cache key validation issues
            return "{$keyPrefix}_{$identifierHash}_{$roleString}_{$optionsHash}";
        } catch (\Exception $e) {
            $this->logger->warning('Failed to generate cache key, using fallback', [
                'error' => $e->getMessage()
            ]);
            // Fallback cache key
            return self::CACHE_KEY_PREFIX . '_fallback_' . time();
        }
    }

    /**
     * Check if caching is enabled.
     */
    private function isCacheEnabled(): bool
    {
        return $this->avatarColorsConfig['cache']['enabled'] ?? true;
    }

    /**
     * Serialize result for caching.
     */
    private function serializeResult(AvatarColorResult $result): array
    {
        try {
            return $result->toArray();
        } catch (\Exception $e) {
            $this->logger->error('Failed to serialize avatar color result', [
                'error' => $e->getMessage()
            ]);
            // Return a basic serializable array as fallback
            return [
                'backgroundClass' => 'bg-gray-500',
                'textClass' => 'text-white',
                'backgroundColor' => '#6B7280',
                'textColor' => '#FFFFFF',
                'contrastRatio' => 8.59,
                'isRoleVariation' => false
            ];
        }
    }

    /**
     * Deserialize result from cache.
     */
    private function deserializeResult(array $data): AvatarColorResult
    {
        try {
            return new AvatarColorResult(
                backgroundClass: $data['backgroundClass'] ?? 'bg-gray-500',
                textClass: $data['textClass'] ?? 'text-white',
                backgroundColor: $data['backgroundColor'] ?? '#6B7280',
                textColor: $data['textColor'] ?? '#FFFFFF',
                contrastRatio: $data['contrastRatio'] ?? 8.59,
                isRoleVariation: $data['isRoleVariation'] ?? false
            );
        } catch (\Exception $e) {
            $this->logger->warning('Failed to deserialize cached avatar color result, using fallback', [
                'cached_data' => $data,
                'error' => $e->getMessage()
            ]);
            return $this->createFallbackResult('deserialization_error');
        }
    }

    /**
     * Invalidate all avatar color cache entries using pattern matching.
     */
    private function invalidateAllAvatarColorCache(): void
    {
        try {
            // For Redis, we can use pattern-based deletion
            // This is more efficient than clearing the entire cache
            $keyPrefix = $this->avatarColorsConfig['cache']['key_prefix'] ?? self::CACHE_KEY_PREFIX;
            
            // Since Symfony's CacheInterface doesn't support pattern deletion,
            // we'll use the clear method as a fallback
            // In a production environment, you might want to implement a custom
            // cache adapter that supports Redis pattern deletion
            $this->cache->clear();
            
            $this->logger->debug('Invalidated all avatar color cache entries', [
                'key_prefix' => $keyPrefix
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to invalidate all avatar color cache', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Invalidate cache for a specific user (all role variations).
     */
    private function invalidateUserAvatarCache(User $user): void
    {
        try {
            $identifier = $this->extractUserIdentifier($user);
            $role = $user->getRole()->value ?? null;
            
            // Clear cache for default options
            $defaultCacheKey = $this->generateCacheKey($identifier, $role, []);
            $this->cache->delete($defaultCacheKey);
            
            // If role variations are enabled, clear cache for different role variations
            if ($this->isRoleVariationEnabled()) {
                $availableRoles = $this->getAvailableRoles();
                foreach ($availableRoles as $roleVariation) {
                    $roleCacheKey = $this->generateCacheKey($identifier, $roleVariation, []);
                    $this->cache->delete($roleCacheKey);
                }
            }
            
            $this->logger->debug('Invalidated user avatar cache', [
                'user_id' => $user->getId(),
                'identifier_hash' => hash('sha256', $identifier),
                'role' => $role
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to invalidate user avatar cache', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Warm up role variations for a user.
     */
    private function warmUpRoleVariations(User $user): void
    {
        try {
            $identifier = $this->extractUserIdentifier($user);
            $availableRoles = $this->getAvailableRoles();
            
            foreach ($availableRoles as $role) {
                // Generate and cache colors for each role variation
                $this->getAvatarColorsFromIdentifier($identifier, $role, []);
            }
            
            $this->logger->debug('Warmed up role variations for user', [
                'user_id' => $user->getId(),
                'roles_warmed' => count($availableRoles)
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to warm up role variations for user', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            // Don't throw - this is a performance optimization, not critical
        }
    }

    /**
     * Check if role variations are enabled.
     */
    private function isRoleVariationEnabled(): bool
    {
        return $this->avatarColorsConfig['role_variations']['enabled'] ?? false;
    }

    /**
     * Get available roles for cache warming.
     */
    private function getAvailableRoles(): array
    {
        try {
            $variations = $this->avatarColorsConfig['role_variations']['variations'] ?? [];
            return array_keys($variations);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get available roles', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}