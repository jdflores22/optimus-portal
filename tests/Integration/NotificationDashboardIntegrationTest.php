<?php

namespace App\Tests\Integration;

use App\Entity\Container;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\UserRole;
use App\Entity\StaffUser;
use App\Service\NotificationMonitoringService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test for notification dashboard and monitoring
 * Tests Requirements 8.1, 8.2, 8.3, 8.4
 */
class NotificationDashboardIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private NotificationMonitoringService $monitoringService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->monitoringService = $container->get(NotificationMonitoringService::class);
    }

    public function testLogNotificationAttemptAndRetrieve(): void
    {
        // Create test container
        $container = new Container();
        $container->setContainerNumber('TEST-NOTIF-' . time());
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setTerminalArrivalDate(new \DateTime('-65 days'));
        $container->setCurrentDwellTime(65);
        
        $this->entityManager->persist($container);

        // Create test user
        $user = new StaffUser();
        $user->setEmail('test-notif-' . time() . '@example.com');
        $user->setPasswordHash('hashed_password');
        $user->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $user->setStatus(\App\Entity\Enum\AccountStatus::APPROVED);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setDepartment('Testing');
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Log a notification attempt
        $log = $this->monitoringService->logNotificationAttempt(
            $container,
            $user,
            'dwell_time_warning',
            'email',
            true
        );

        $this->assertNotNull($log->getId());
        $this->assertEquals('delivered', $log->getDeliveryStatus());
        $this->assertEquals('email', $log->getChannel());
        $this->assertEquals('dwell_time_warning', $log->getNotificationType());

        // Retrieve statistics
        $stats = $this->monitoringService->getDeliveryStatistics();
        
        $this->assertGreaterThan(0, $stats['total_notifications']);
        $this->assertArrayHasKey('by_channel', $stats);
        $this->assertArrayHasKey('by_type', $stats);

        // Search by container number
        $results = $this->monitoringService->searchByContainerNumber($container->getContainerNumber(), 10);
        
        $this->assertNotEmpty($results);
        $this->assertEquals($container->getContainerNumber(), $results[0]['container']['container_number']);

        // Cleanup
        $this->entityManager->remove($log);
        $this->entityManager->remove($container);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    public function testFilterNotificationsByStatus(): void
    {
        // Create test container
        $container = new Container();
        $container->setContainerNumber('TEST-FILTER-' . time());
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setTerminalArrivalDate(new \DateTime('-65 days'));
        $container->setCurrentDwellTime(65);
        
        $this->entityManager->persist($container);

        // Create test user
        $user = new StaffUser();
        $user->setEmail('test-filter-' . time() . '@example.com');
        $user->setPasswordHash('hashed_password');
        $user->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $user->setStatus(\App\Entity\Enum\AccountStatus::APPROVED);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setDepartment('Testing');
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Log successful and failed notifications
        $log1 = $this->monitoringService->logNotificationAttempt(
            $container,
            $user,
            'dwell_time_warning',
            'email',
            true
        );

        $log2 = $this->monitoringService->logNotificationAttempt(
            $container,
            $user,
            'automatic_return',
            'email',
            false,
            'SMTP connection failed'
        );

        // Filter by delivered status
        $deliveredResults = $this->monitoringService->filterNotifications([
            'delivery_status' => 'delivered',
            'limit' => 10,
            'offset' => 0
        ]);

        $this->assertNotEmpty($deliveredResults);
        
        // Filter by failed status
        $failedResults = $this->monitoringService->filterNotifications([
            'delivery_status' => 'failed',
            'limit' => 10,
            'offset' => 0
        ]);

        $this->assertNotEmpty($failedResults);

        // Cleanup
        $this->entityManager->remove($log1);
        $this->entityManager->remove($log2);
        $this->entityManager->remove($container);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    public function testGetDeliveryStatisticsByDateRange(): void
    {
        // Create test container
        $container = new Container();
        $container->setContainerNumber('TEST-STATS-' . time());
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setTerminalArrivalDate(new \DateTime('-65 days'));
        $container->setCurrentDwellTime(65);
        
        $this->entityManager->persist($container);

        // Create test user
        $user = new StaffUser();
        $user->setEmail('test-stats-' . time() . '@example.com');
        $user->setPasswordHash('hashed_password');
        $user->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $user->setStatus(\App\Entity\Enum\AccountStatus::APPROVED);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setDepartment('Testing');
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Log notifications
        $log = $this->monitoringService->logNotificationAttempt(
            $container,
            $user,
            'dwell_time_warning',
            'email',
            true
        );

        // Get statistics for today
        $fromDate = new \DateTime('today');
        $toDate = new \DateTime('tomorrow');
        
        $stats = $this->monitoringService->getDeliveryStatistics($fromDate, $toDate);

        $this->assertArrayHasKey('total_notifications', $stats);
        $this->assertArrayHasKey('delivered', $stats);
        $this->assertArrayHasKey('failed', $stats);
        $this->assertArrayHasKey('success_rate', $stats);
        $this->assertArrayHasKey('by_channel', $stats);
        $this->assertArrayHasKey('by_type', $stats);

        // Cleanup
        $this->entityManager->remove($log);
        $this->entityManager->remove($container);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}
