<?php

namespace App\Tests\Unit\Controller;

use App\Controller\TerminalTeamController;
use App\Entity\Container;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\UserRole;
use App\Entity\ShippingLine;
use App\Entity\TerminalTeamUser;
use App\Entity\User;
use App\Service\AuditService;
use App\Service\CacheService;
use App\Service\PreAdviceService;
use App\Service\SlotManagementService;
use App\Service\TerminalService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\AbstractQuery;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TerminalTeamController shipping line filtering
 * Task 14: Shipping line hierarchy filtering for Terminal Team
 */
class TerminalTeamControllerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private PreAdviceService $preAdviceService;
    private TerminalService $terminalService;
    private SlotManagementService $slotManagementService;
    private AuditService $auditService;
    private CacheService $cacheService;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->preAdviceService = $this->createMock(PreAdviceService::class);
        $this->terminalService = $this->createMock(TerminalService::class);
        $this->slotManagementService = $this->createMock(SlotManagementService::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->cacheService = $this->createMock(CacheService::class);
    }

    /**
     * Test that getDwellTimeContainers filters by shipping line for Terminal Team users
     * Validates: Requirements 9.1, 9.2, 9.3
     */
    public function testGetDwellTimeContainersFiltersByShippingLine(): void
    {
        // Create test data
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Shipping Line');

        $shippingLineAdmin = $this->createMock(User::class);
        $shippingLineAdmin->method('getManagedShippingLine')->willReturn($shippingLine);
        $shippingLineAdmin->method('getRole')->willReturn(UserRole::SHIPPING_LINES_ADMIN);

        $terminalTeamUser = $this->createMock(TerminalTeamUser::class);
        $terminalTeamUser->method('getShippingLineAdmin')->willReturn($shippingLineAdmin);
        $terminalTeamUser->method('getShippingLineScope')->willReturn($shippingLine);

        // Create mock controller with getUser() method
        $controller = $this->getMockBuilder(TerminalTeamController::class)
            ->setConstructorArgs([
                $this->entityManager,
                $this->preAdviceService,
                $this->terminalService,
                $this->slotManagementService,
                $this->auditService,
                $this->cacheService
            ])
            ->onlyMethods(['getUser'])
            ->getMock();

        $controller->method('getUser')->willReturn($terminalTeamUser);

        // Create mock containers
        $container1 = new Container();
        $reflection1 = new \ReflectionClass($container1);
        $idProperty1 = $reflection1->getProperty('id');
        $idProperty1->setAccessible(true);
        $idProperty1->setValue($container1, 1);
        $container1->setContainerNumber('TEST001');
        $container1->setSize('20');
        $container1->setType('GP');
        $container1->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN);
        $container1->setCurrentDwellTime(65);
        $container1->setTerminalArrivalDate(new \DateTime('-65 days'));
        $container1->setShippingLine($shippingLine);
        $container1->setExpectedReturnDate(new \DateTime('+30 days'));

        $container2 = new Container();
        $reflection2 = new \ReflectionClass($container2);
        $idProperty2 = $reflection2->getProperty('id');
        $idProperty2->setAccessible(true);
        $idProperty2->setValue($container2, 2);
        $container2->setContainerNumber('TEST002');
        $container2->setSize('40');
        $container2->setType('HC');
        $container2->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN);
        $container2->setCurrentDwellTime(95);
        $container2->setTerminalArrivalDate(new \DateTime('-95 days'));
        $container2->setShippingLine($shippingLine);
        $container2->setExpectedReturnDate(new \DateTime('+30 days'));

        $containers = [$container1, $container2];

        // Mock query builder and repository
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getResult')->willReturn($containers);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->entityManager->method('getRepository')->willReturn($repository);

        // Use reflection to call the private method
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('getDwellTimeContainers');
        $method->setAccessible(true);

        $result = $method->invoke($controller);

        // Assertions
        $this->assertIsArray($result);
        $this->assertArrayHasKey('containers_60_to_89', $result);
        $this->assertArrayHasKey('containers_90_plus', $result);
        $this->assertArrayHasKey('stats', $result);
        $this->assertArrayHasKey('no_shipping_line', $result);
        $this->assertFalse($result['no_shipping_line']);
        $this->assertEquals(2, $result['stats']['total']);
        $this->assertEquals(1, $result['stats']['count_60_to_89']);
        $this->assertEquals(1, $result['stats']['count_90_plus']);
    }

    /**
     * Test that getDwellTimeContainers returns empty data when no shipping line is associated
     * Validates: Requirement 9.4
     */
    public function testGetDwellTimeContainersReturnsEmptyWhenNoShippingLine(): void
    {
        // Create terminal team user without shipping line admin
        $terminalTeamUser = $this->createMock(TerminalTeamUser::class);
        $terminalTeamUser->method('getShippingLineAdmin')->willReturn(null);
        $terminalTeamUser->method('getShippingLineScope')->willReturn(null);

        // Create mock controller
        $controller = $this->getMockBuilder(TerminalTeamController::class)
            ->setConstructorArgs([
                $this->entityManager,
                $this->preAdviceService,
                $this->terminalService,
                $this->slotManagementService,
                $this->auditService,
                $this->cacheService
            ])
            ->onlyMethods(['getUser'])
            ->getMock();

        $controller->method('getUser')->willReturn($terminalTeamUser);

        // Use reflection to call the private method
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('getDwellTimeContainers');
        $method->setAccessible(true);

        $result = $method->invoke($controller);

        // Assertions
        $this->assertIsArray($result);
        $this->assertArrayHasKey('no_shipping_line', $result);
        $this->assertTrue($result['no_shipping_line']);
        $this->assertEquals(0, $result['stats']['total']);
        $this->assertEquals(0, $result['stats']['count_60_to_89']);
        $this->assertEquals(0, $result['stats']['count_90_plus']);
        $this->assertEmpty($result['containers_60_to_89']);
        $this->assertEmpty($result['containers_90_plus']);
    }
}
