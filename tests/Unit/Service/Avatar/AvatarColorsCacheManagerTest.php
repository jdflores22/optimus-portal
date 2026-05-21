<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Avatar;

use App\Entity\User;
use App\Service\Avatar\AvatarColorsCacheManager;
use App\Service\Avatar\AvatarColorServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AvatarColorsCacheManager.
 */
class AvatarColorsCacheManagerTest extends TestCase
{
    private AvatarColorsCacheManager $cacheManager;
    private MockObject|AvatarColorServiceInterface $avatarColorService;
    private MockObject|EntityManagerInterface $entityManager;
    private MockObject|LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->avatarColorService = $this->createMock(AvatarColorServiceInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->cacheManager = new AvatarColorsCacheManager(
            $this->avatarColorService,
            $this->entityManager,
            $this->logger
        );
    }

    public function testWarmUpFrequentUsersWithNoUsers(): void
    {
        // Mock query builder chain to return empty result
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $this->entityManager->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        // Configure query builder to return self for method chaining
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $query->expects($this->once())
            ->method('getResult')
            ->willReturn([]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('No frequent users found for cache warming');

        $result = $this->cacheManager->warmUpFrequentUsers();

        $this->assertEquals(['warmed' => 0, 'failed' => 0, 'total' => 0], $result);
    }

    public function testWarmUpFrequentUsersWithUsers(): void
    {
        $users = [
            $this->createMock(User::class),
            $this->createMock(User::class)
        ];

        // Mock query builder chain to return users
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $this->entityManager->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        // Configure query builder to return self for method chaining
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $query->expects($this->once())
            ->method('getResult')
            ->willReturn($users);

        $this->avatarColorService->expects($this->once())
            ->method('warmUpCache')
            ->with($users);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Avatar colors cache warmed for frequent users', ['warmed' => 2, 'failed' => 0, 'total' => 2]);

        $result = $this->cacheManager->warmUpFrequentUsers();

        $this->assertEquals(['warmed' => 2, 'failed' => 0, 'total' => 2], $result);
    }

    public function testGetCachePerformanceStats(): void
    {
        $baseStats = [
            'cache_enabled' => true,
            'cache_ttl' => 3600,
            'cache_prefix' => 'avatar_colors'
        ];

        $this->avatarColorService->expects($this->once())
            ->method('getCacheStats')
            ->willReturn($baseStats);

        // Mock the query builders for count queries
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $this->entityManager->expects($this->exactly(2))
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        // Configure query builder to return self for method chaining
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        // Return different counts for frequent and active users
        $query->expects($this->exactly(2))
            ->method('getSingleScalarResult')
            ->willReturnOnConsecutiveCalls(50, 100);

        $result = $this->cacheManager->getCachePerformanceStats();

        $this->assertTrue($result['cache_enabled']);
        $this->assertEquals(3600, $result['cache_ttl']);
        $this->assertEquals('avatar_colors', $result['cache_prefix']);
        $this->assertEquals(50, $result['frequent_users_count']);
        $this->assertEquals(100, $result['active_users_count']);
        $this->assertNull($result['last_warming_time']);
    }

    public function testInvalidateCacheForRecentChanges(): void
    {
        $user1 = $this->createMock(User::class);
        $user2 = $this->createMock(User::class);
        $users = [$user1, $user2];

        // Mock query builder chain
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $this->entityManager->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        // Configure query builder to return self for method chaining
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $query->expects($this->once())
            ->method('getResult')
            ->willReturn($users);

        // Expect clearCache to be called for each user
        $this->avatarColorService->expects($this->exactly(2))
            ->method('clearCache')
            ->with($this->callback(function ($user) {
                return $user instanceof User;
            }));

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Invalidated cache for users with recent changes', ['invalidated' => 2, 'total' => 2]);

        $result = $this->cacheManager->invalidateCacheForRecentChanges();

        $this->assertEquals(['invalidated' => 2, 'total' => 2], $result);
    }
}