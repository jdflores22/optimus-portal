<?php

declare(strict_types=1);

namespace App\Service\Avatar;

use App\Entity\User;
use App\ValueObject\AvatarColorResult;

/**
 * Interface for the main avatar color service.
 * 
 * This service orchestrates color generation, caching, and provides
 * the primary API for generating avatar colors throughout the application.
 */
interface AvatarColorServiceInterface
{
    /**
     * Generate avatar colors for a user entity.
     * 
     * @param User $user The user to generate colors for
     * @param array $options Optional parameters for color generation
     * @return AvatarColorResult The generated color information
     */
    public function getAvatarColors(User $user, array $options = []): AvatarColorResult;

    /**
     * Generate avatar colors from a string identifier.
     * 
     * @param string $identifier The identifier to generate colors from
     * @param string|null $role Optional user role for role-based variations
     * @param array $options Optional parameters for color generation
     * @return AvatarColorResult The generated color information
     */
    public function getAvatarColorsFromIdentifier(string $identifier, ?string $role = null, array $options = []): AvatarColorResult;

    /**
     * Clear cached colors for a user or all users.
     * 
     * @param User|null $user Specific user to clear cache for, or null for all
     */
    public function clearCache(?User $user = null): void;

    /**
     * Warm up cache for frequently accessed users.
     * 
     * @param array $users Array of User entities to warm up cache for
     */
    public function warmUpCache(array $users = []): void;

    /**
     * Invalidate cache when configuration changes.
     */
    public function invalidateCacheOnConfigChange(): void;

    /**
     * Get cache statistics for monitoring.
     * 
     * @return array Cache statistics and configuration
     */
    public function getCacheStats(): array;
}