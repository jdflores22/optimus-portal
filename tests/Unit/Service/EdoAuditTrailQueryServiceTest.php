<?php

namespace App\Tests\Unit\Service;

use App\Entity\AuditLog;
use App\Entity\Container;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\EDOStatus;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Repository\ContainerRepository;
use App\Repository\ElectronicDeliveryOrderRepository;
use App\Service\EdoAuditTrailQueryService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

class EdoAuditTrailQueryServiceTest extends TestCase
{
    public function testSearchByEdoNumberMapsAuditLogToApiShape(): void
    {
        $edo = $this->createEdo(10, 'EDO-202606-0001', 'MSCU1234567');
        $user = $this->createUser('admin@optimus.test', UserRole::SYSTEM_ADMIN);
        $auditLog = $this->createAuditLog(
            $user,
            'edo_released',
            'ElectronicDeliveryOrder',
            10,
            [
                'edo_number' => 'EDO-202606-0001',
                'manifest_id' => 5,
                'to_status' => EDOStatus::RELEASED->value,
            ],
            $edo
        );

        $service = $this->createService(
            edo: $edo,
            auditLogs: [$auditLog]
        );

        $results = $service->searchByEdoNumber('EDO-202606-0001');

        $this->assertCount(1, $results);
        $this->assertSame('edo_released', $results[0]['event_type']);
        $this->assertSame('EDO-202606-0001', $results[0]['edo_number']);
        $this->assertSame('MSCU1234567', $results[0]['container_number']);
        $this->assertSame('admin@optimus.test', $results[0]['user']['email']);
        $this->assertSame('System Admin', $results[0]['user']['full_name']);
        $this->assertArrayHasKey('timestamp', $results[0]);
        $this->assertSame(5, $results[0]['details']['manifest_id']);
    }

    public function testSearchByContainerNumberUsesContainerLinkedEdos(): void
    {
        $container = $this->createContainer(22, 'TCLU9876543');
        $edo = $this->createEdo(11, 'EDO-202606-0002', 'TCLU9876543');
        $user = $this->createUser('broker@optimus.test', UserRole::BROKER);
        $auditLog = $this->createAuditLog(
            $user,
            'edo_payment_submission',
            'EDOPayment',
            99,
            [
                'edo_number' => 'EDO-202606-0002',
                'container_number' => 'TCLU9876543',
                'amount' => 1500,
            ]
        );

        $service = $this->createService(
            container: $container,
            containerEdos: [$edo],
            auditLogs: [$auditLog]
        );

        $results = $service->searchByContainerNumber('TCLU9876543');

        $this->assertCount(1, $results);
        $this->assertSame('payment_submitted', $results[0]['event_type']);
        $this->assertSame('EDO-202606-0002', $results[0]['edo_number']);
        $this->assertSame('TCLU9876543', $results[0]['container_number']);
        $this->assertSame(1500, $results[0]['details']['amount']);
    }

    /**
     * @param AuditLog[] $auditLogs
     */
    private function createService(
        ?ElectronicDeliveryOrder $edo = null,
        ?Container $container = null,
        array $containerEdos = [],
        array $auditLogs = []
    ): EdoAuditTrailQueryService {
        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn($auditLogs);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('leftJoin')->willReturnSelf();
        $queryBuilder->method('addSelect')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $auditLogRepository = $this->createMock(EntityRepository::class);
        $auditLogRepository->method('createQueryBuilder')->willReturn($queryBuilder);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')
            ->with(AuditLog::class)
            ->willReturn($auditLogRepository);

        $edoRepository = $this->createMock(ElectronicDeliveryOrderRepository::class);
        $edoRepository->method('findByNumberWithRelations')
            ->willReturn($edo);
        $edoRepository->method('findByContainerWithRelations')
            ->willReturn($containerEdos);
        $edoRepository->method('findWithRelations')
            ->willReturnCallback(static fn (int $id) => $edo && $edo->getId() === $id ? $edo : null);

        $containerRepository = $this->createMock(ContainerRepository::class);
        $containerRepository->method('findByContainerNumber')
            ->willReturn($container);

        return new EdoAuditTrailQueryService(
            $entityManager,
            $edoRepository,
            $containerRepository
        );
    }

    private function createUser(string $email, UserRole $role): User
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn($email);
        $user->method('getRole')->willReturn($role);

        return $user;
    }

    private function createEdo(int $id, string $edoNumber, string $containerNumber): ElectronicDeliveryOrder
    {
        $container = $this->createContainer(1, $containerNumber);
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber($edoNumber);
        $edo->setStatus(EDOStatus::RELEASED);
        $edo->setContainer($container);

        $reflection = new \ReflectionClass($edo);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($edo, $id);

        return $edo;
    }

    private function createContainer(int $id, string $containerNumber): Container
    {
        $container = new Container();
        $container->setContainerNumber($containerNumber);

        $reflection = new \ReflectionClass($container);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($container, $id);

        return $container;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function createAuditLog(
        User $user,
        string $action,
        string $entityType,
        int $entityId,
        array $changes,
        ?ElectronicDeliveryOrder $relatedEdo = null
    ): AuditLog {
        $log = new AuditLog();
        $log->setUser($user);
        $log->setAction($action);
        $log->setEntityType($entityType);
        $log->setEntityId($entityId);
        $log->setChanges($changes);
        $log->setIpAddress('127.0.0.1');

        if ($relatedEdo) {
            $log->setRelatedEdo($relatedEdo);
        }

        return $log;
    }
}
