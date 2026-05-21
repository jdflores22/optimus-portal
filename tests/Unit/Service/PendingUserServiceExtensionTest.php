<?php

namespace App\Tests\Unit\Service;

use App\Service\PendingUserService;
use App\Repository\PendingUserRepository;
use App\Entity\PendingUser;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Query;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class PendingUserServiceExtensionTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $pendingUserRepository;
    private MockObject $passwordHasher;
    private MockObject $logger;
    private PendingUserService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->pendingUserRepository = $this->createMock(PendingUserRepository::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new PendingUserService(
            $this->entityManager,
            $this->pendingUserRepository,
            $this->passwordHasher,
            $this->logger
        );
    }

    public function testGetPendingUserStatistics(): void
    {
        // Arrange
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $this->entityManager->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('select')
            ->with('p.status, COUNT(p.id) as count')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('from')
            ->with(PendingUser::class, 'p')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('groupBy')
            ->with('p.status')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($query);

        $query->expects($this->once())
            ->method('getResult')
            ->willReturn([
                ['status' => 'pending', 'count' => 5],
                ['status' => 'expired', 'count' => 2],
                ['status' => 'delivery_failed', 'count' => 1]
            ]);

        // Act
        $result = $this->service->getPendingUserStatistics();

        // Assert
        $expected = [
            'pending' => 5,
            'expired' => 2,
            'delivery_failed' => 1,
            'accepted' => 0,
            'declined' => 0,
            'total' => 8
        ];

        $this->assertEquals($expected, $result);
    }

    public function testGetDeliveryFailedCount(): void
    {
        // Arrange
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $this->entityManager->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('select')
            ->with('COUNT(p.id)')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('from')
            ->with(PendingUser::class, 'p')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('where')
            ->with('p.status = :status')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('status', 'delivery_failed')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($query);

        $query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn('3');

        // Act
        $result = $this->service->getDeliveryFailedCount();

        // Assert
        $this->assertEquals(3, $result);
    }

    public function testMarkAsDeliveryFailed(): void
    {
        // Arrange
        $pendingUser = $this->createMock(PendingUser::class);
        $pendingUser->expects($this->once())
            ->method('setStatus')
            ->with('delivery_failed');

        $pendingUser->expects($this->once())
            ->method('getEmail')
            ->willReturn('test@example.com');

        $pendingUser->expects($this->once())
            ->method('getRole')
            ->willReturn(UserRole::SL_STAFF);

        $admin = $this->createMock(User::class);
        $admin->expects($this->once())
            ->method('getId')
            ->willReturn(1);

        $pendingUser->expects($this->once())
            ->method('getCreatedByAdmin')
            ->willReturn($admin);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Pending user marked as delivery failed', [
                'email' => 'test@example.com',
                'role' => 'SL_STAFF',
                'admin_id' => 1,
                'error' => 'SMTP connection failed'
            ]);

        // Act
        $this->service->markAsDeliveryFailed($pendingUser, 'SMTP connection failed');

        // Assert - no exception thrown means success
        $this->assertTrue(true);
    }
}