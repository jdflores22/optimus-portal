<?php

declare(strict_types=1);

namespace App\Service\Avatar;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Dedicated cache manager for avatar colors system.
 * 
 * This service provides high-level cache management operations
 * for the avatar colors system, including warming and monitoring.
 */
class AvatarColorsCacheManager
{
    public function __construct(
        private readonly AvatarColorServiceInterface $avatarColorService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Warm up cache for the most frequently accessed users.
     */
    public function warmUpFrequentUsers(int $limit = 100, int $recentDays = 30): array
    {
        try {
            $users = $this->getFrequentUsers($limit, $recentDays);
            
            if (empty($users)) {
                $this->logger->info('No frequent users found for cache warming');
                return ['warmed' => 0, 'failed' => 0, 'total' => 0];
            }

            $this->avatarColorService->warmUpCache($users);

            $result = [
                'warmed' => count($users),
                'failed' => 0,
                'total' => count($users)
            ];

            $this->logger->info('Avatar colors cache warmed for frequent users', $result);
            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Failed to warm up cache for frequent users', [
                'error' => $e->getMessage()
            ]);
            return ['warmed' => 0, 'failed' => 1, 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Warm up cache for all active users.
     */
    public function warmUpAllActiveUsers(): array
    {
        try {
            $users = $this->getAllActiveUsers();
            
            if (empty($users)) {
                $this->logger->info('No active users found for cache warming');
                return ['warmed' => 0, 'failed' => 0, 'total' => 0];
            }

            // Process in batches to avoid memory issues
            $batchSize = 50;
            $batches = array_chunk($users, $batchSize);
            $totalWarmed = 0;
            $totalFailed = 0;

            foreach ($batches as $batch) {
                try {
                    $this->avatarColorService->warmUpCache($batch);
                    $totalWarmed += count($batch);
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to warm cache for batch', [
                        'batch_size' => count($batch),
                        'error' => $e->getMessage()
                    ]);
                    $totalFailed += count($batch);
                }
            }

            $result = [
                'warmed' => $totalWarmed,
                'failed' => $totalFailed,
                'total' => count($users)
            ];

            $this->logger->info('Avatar colors cache warmed for all active users', $result);
            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Failed to warm up cache for all active users', [
                'error' => $e->getMessage()
            ]);
            return ['warmed' => 0, 'failed' => 1, 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get cache performance statistics.
     */
    public function getCachePerformanceStats(): array
    {
        try {
            $stats = $this->avatarColorService->getCacheStats();
            
            // Add additional performance metrics
            $stats['frequent_users_count'] = $this->getFrequentUsersCount();
            $stats['active_users_count'] = $this->getActiveUsersCount();
            $stats['last_warming_time'] = $this->getLastWarmingTime();

            return $stats;

        } catch (\Exception $e) {
            $this->logger->error('Failed to get cache performance statistics', [
                'error' => $e->getMessage()
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Invalidate cache for users with recent profile changes.
     */
    public function invalidateCacheForRecentChanges(int $hours = 24): array
    {
        try {
            $users = $this->getUsersWithRecentChanges($hours);
            $invalidatedCount = 0;

            foreach ($users as $user) {
                try {
                    $this->avatarColorService->clearCache($user);
                    $invalidatedCount++;
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to invalidate cache for user', [
                        'user_id' => $user->getId(),
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $result = [
                'invalidated' => $invalidatedCount,
                'total' => count($users)
            ];

            $this->logger->info('Invalidated cache for users with recent changes', $result);
            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Failed to invalidate cache for recent changes', [
                'error' => $e->getMessage()
            ]);
            return ['invalidated' => 0, 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get frequent users for cache warming.
     */
    private function getFrequentUsers(int $limit, int $recentDays): array
    {
        try {
            $cutoffDate = new \DateTime(sprintf('-%d days', $recentDays));
            
            return $this->entityManager->createQueryBuilder()
                ->select('u')
                ->from(User::class, 'u')
                ->where('u.isActive = :active')
                ->andWhere('u.lastLoginAt >= :cutoff OR u.createdAt >= :cutoff')
                ->setParameter('active', true)
                ->setParameter('cutoff', $cutoffDate)
                ->orderBy('u.lastLoginAt', 'DESC')
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();

        } catch (\Exception $e) {
            $this->logger->error('Failed to get frequent users', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get all active users.
     */
    private function getAllActiveUsers(): array
    {
        try {
            return $this->entityManager->createQueryBuilder()
                ->select('u')
                ->from(User::class, 'u')
                ->where('u.isActive = :active')
                ->setParameter('active', true)
                ->orderBy('u.lastLoginAt', 'DESC')
                ->getQuery()
                ->getResult();

        } catch (\Exception $e) {
            $this->logger->error('Failed to get all active users', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get count of frequent users.
     */
    private function getFrequentUsersCount(int $recentDays = 30): int
    {
        try {
            $cutoffDate = new \DateTime(sprintf('-%d days', $recentDays));
            
            return (int) $this->entityManager->createQueryBuilder()
                ->select('COUNT(u.id)')
                ->from(User::class, 'u')
                ->where('u.isActive = :active')
                ->andWhere('u.lastLoginAt >= :cutoff OR u.createdAt >= :cutoff')
                ->setParameter('active', true)
                ->setParameter('cutoff', $cutoffDate)
                ->getQuery()
                ->getSingleScalarResult();

        } catch (\Exception $e) {
            $this->logger->error('Failed to get frequent users count', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get count of active users.
     */
    private function getActiveUsersCount(): int
    {
        try {
            return (int) $this->entityManager->createQueryBuilder()
                ->select('COUNT(u.id)')
                ->from(User::class, 'u')
                ->where('u.isActive = :active')
                ->setParameter('active', true)
                ->getQuery()
                ->getSingleScalarResult();

        } catch (\Exception $e) {
            $this->logger->error('Failed to get active users count', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get users with recent profile changes.
     */
    private function getUsersWithRecentChanges(int $hours): array
    {
        try {
            $cutoffDate = new \DateTime(sprintf('-%d hours', $hours));
            
            return $this->entityManager->createQueryBuilder()
                ->select('u')
                ->from(User::class, 'u')
                ->where('u.isActive = :active')
                ->andWhere('u.updatedAt >= :cutoff')
                ->setParameter('active', true)
                ->setParameter('cutoff', $cutoffDate)
                ->getQuery()
                ->getResult();

        } catch (\Exception $e) {
            $this->logger->error('Failed to get users with recent changes', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get last cache warming time (simplified implementation).
     */
    private function getLastWarmingTime(): ?int
    {
        // This is a simplified implementation
        // In production, you might store this in cache or database
        return null;
    }
}