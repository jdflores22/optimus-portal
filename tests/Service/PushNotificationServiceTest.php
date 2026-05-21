<?php

namespace App\Tests\Service;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Service\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PushNotificationServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private PushNotificationService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->service = new PushNotificationService(
            $this->entityManager,
            $this->logger,
            'test-public-key',
            'test-private-key',
            'mailto:test@example.com'
        );
    }

    public function testServiceCanBeInstantiated(): void
    {
        $this->assertInstanceOf(PushNotificationService::class, $this->service);
    }

    public function testHasActiveSubscriptionsReturnsFalseForUserWithNoSubscriptions(): void
    {
        $user = $this->createMock(User::class);
        $repository = $this->createMock(\App\Repository\PushSubscriptionRepository::class);
        
        $this->entityManager
            ->expects($this->once())
            ->method('getRepository')
            ->with(PushSubscription::class)
            ->willReturn($repository);
        
        $repository
            ->expects($this->once())
            ->method('countActiveByUser')
            ->with($user)
            ->willReturn(0);
        
        $result = $this->service->hasActiveSubscriptions($user);
        
        $this->assertFalse($result);
    }

    public function testHasActiveSubscriptionsReturnsTrueForUserWithSubscriptions(): void
    {
        $user = $this->createMock(User::class);
        $repository = $this->createMock(\App\Repository\PushSubscriptionRepository::class);
        
        $this->entityManager
            ->expects($this->once())
            ->method('getRepository')
            ->with(PushSubscription::class)
            ->willReturn($repository);
        
        $repository
            ->expects($this->once())
            ->method('countActiveByUser')
            ->with($user)
            ->willReturn(2);
        
        $result = $this->service->hasActiveSubscriptions($user);
        
        $this->assertTrue($result);
    }

    public function testCleanupInvalidSubscriptionsRemovesInactiveSubscriptions(): void
    {
        // Create a query builder mock
        $qb = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        
        // Mock the query builder chain
        $qb->expects($this->once())
            ->method('delete')
            ->with(PushSubscription::class, 'ps')
            ->willReturnSelf();
        
        $qb->expects($this->once())
            ->method('where')
            ->with('ps.isActive = false')
            ->willReturnSelf();
        
        $qb->expects($this->once())
            ->method('getQuery')
            ->willReturn($query);
        
        // Mock the query execution to return 3 deleted subscriptions
        $query->expects($this->once())
            ->method('execute')
            ->willReturn(3);
        
        // Mock entity manager to return the query builder
        $this->entityManager
            ->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($qb);
        
        // Expect logger to be called with cleanup info
        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Cleaned up invalid push subscriptions', ['count' => 3]);
        
        $result = $this->service->cleanupInvalidSubscriptions();
        
        $this->assertEquals(3, $result);
    }

    public function testGetVapidPublicKeyReturnsConfiguredKey(): void
    {
        $publicKey = $this->service->getVapidPublicKey();
        
        $this->assertEquals('test-public-key', $publicKey);
    }
}
