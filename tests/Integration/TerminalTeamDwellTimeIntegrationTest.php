<?php

namespace App\Tests\Integration;

use App\Entity\Container;
use App\Entity\TerminalTeamUser;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\AccountStatus;
use App\Service\TerminalTeamDwellTimeService;
use App\Service\DwellTimeService;
use App\Service\ContainerStatusService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TerminalTeamDwellTimeIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TerminalTeamDwellTimeService $terminalTeamService;
    private DwellTimeService $dwellTimeService;
    private ContainerStatusService $containerStatusService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->terminalTeamService = $container->get(TerminalTeamDwellTimeService::class);
        $this->dwellTimeService = $container->get(DwellTimeService::class);
        $this->containerStatusService = $container->get(ContainerStatusService::class);

        // Clean up database
        $this->cleanDatabase();
    }

    protected function tearDown(): void
    {
        $this->cleanDatabase();
        parent::tearDown();
    }

    public function testTerminalTeamReceivesNotificationWhenContainerReaches60Days(): void
    {
        // Arrange
        $terminalUser = $this->createTerminalTeamUser('terminal@test.com');
        $container = $this->createContainer('TEST123456', 60);

        // Act
        $this->terminalTeamService->notifyTerminalTeamDwellTimeWarning($container, 30);

        // Assert - Check that notification was created
        $notifications = $this->entityManager->getRepository(\App\Entity\Notification::class)
            ->findBy(['user' => $terminalUser]);

        $this->assertCount(1, $notifications);
        $this->assertStringContainsString('60 days dwell time', $notifications[0]->getMessage());
        $this->assertStringContainsString('TEST123456', $notifications[0]->getMessage());
    }

    public function testTerminalTeamReceivesNotificationWhenContainerAutomaticallyReturned(): void
    {
        // Arrange
        $terminalUser = $this->createTerminalTeamUser('terminal@test.com');
        $container = $this->createContainer('TEST123456', 90);

        // Act
        $this->terminalTeamService->notifyTerminalTeamAutomaticReturn($container);

        // Assert
        $notifications = $this->entityManager->getRepository(\App\Entity\Notification::class)
            ->findBy(['user' => $terminalUser]);

        $this->assertCount(1, $notifications);
        $this->assertStringContainsString('automatically returned', $notifications[0]->getMessage());
        $this->assertStringContainsString('90 days', $notifications[0]->getMessage());
    }

    public function testTerminalTeamReceivesNotificationWhenAlertStatusChanges(): void
    {
        // Arrange
        $terminalUser = $this->createTerminalTeamUser('terminal@test.com');
        $container = $this->createContainer('TEST123456', 50);

        // Act - Change to alert status
        $this->containerStatusService->changeStatus($container, ContainerStatus::ALERT, null, 'Test alert');

        // Assert
        $notifications = $this->entityManager->getRepository(\App\Entity\Notification::class)
            ->findBy(['user' => $terminalUser]);

        $this->assertGreaterThanOrEqual(1, count($notifications));
        
        // Find the alert notification
        $alertNotification = null;
        foreach ($notifications as $notification) {
            if (str_contains($notification->getMessage(), 'alerted')) {
                $alertNotification = $notification;
                break;
            }
        }

        $this->assertNotNull($alertNotification);
        $this->assertStringContainsString('TEST123456', $alertNotification->getMessage());
    }

    public function testDashboardMetricsIncludeDwellTimeInformation(): void
    {
        // Arrange
        $this->createTerminalTeamUser('terminal@test.com');
        $container1 = $this->createContainer('TEST123456', 55); // Approaching warning
        $container2 = $this->createContainer('TEST789012', 65); // Warning issued
        $container3 = $this->createContainer('TEST345678', 92); // Automatic return
        
        $container4 = $this->createContainer('TEST901234', 50);
        $container4->setStatus(ContainerStatus::ALERT); // Alerted
        $this->entityManager->flush();

        // Act
        $metrics = $this->terminalTeamService->getTerminalTeamDashboardMetrics();

        // Assert
        $this->assertArrayHasKey('dwell_time_summary', $metrics);
        $this->assertArrayHasKey('approaching_warning_count', $metrics['dwell_time_summary']);
        $this->assertArrayHasKey('warning_issued_count', $metrics['dwell_time_summary']);
        $this->assertArrayHasKey('automatic_returns_count', $metrics['dwell_time_summary']);
        $this->assertArrayHasKey('alerted_containers_count', $metrics['dwell_time_summary']);

        $this->assertEquals(1, $metrics['dwell_time_summary']['approaching_warning_count']);
        $this->assertEquals(1, $metrics['dwell_time_summary']['warning_issued_count']);
        $this->assertEquals(1, $metrics['dwell_time_summary']['automatic_returns_count']);
        $this->assertEquals(1, $metrics['dwell_time_summary']['alerted_containers_count']);
    }

    public function testGetContainerAlertStatusInfoReturnsCorrectData(): void
    {
        // Arrange
        $container = $this->createContainer('TEST123456', 55);
        $container->setStatus(ContainerStatus::ALERT);
        $container->setDwellTimePausedAt(new \DateTime());
        $container->setTotalPausedDays(3);
        $this->entityManager->flush();

        // Act
        $alertInfo = $this->terminalTeamService->getContainerAlertStatusInfo($container);

        // Assert
        $this->assertEquals('TEST123456', $alertInfo['container_number']);
        $this->assertTrue($alertInfo['is_alerted']);
        $this->assertTrue($alertInfo['is_dwell_time_paused']);
        $this->assertEquals(55, $alertInfo['current_dwell_time']);
        $this->assertEquals(3, $alertInfo['total_paused_days']);
        $this->assertEquals('alert', $alertInfo['status']);
    }

    public function testTerminalTeamNotifiedWhenAlertStatusCleared(): void
    {
        // Arrange
        $terminalUser = $this->createTerminalTeamUser('terminal@test.com');
        $container = $this->createContainer('TEST123456', 50);
        $container->setStatus(ContainerStatus::ALERT);
        $this->entityManager->flush();

        // Clear existing notifications
        $existingNotifications = $this->entityManager->getRepository(\App\Entity\Notification::class)
            ->findBy(['user' => $terminalUser]);
        foreach ($existingNotifications as $notification) {
            $this->entityManager->remove($notification);
        }
        $this->entityManager->flush();

        // Act - Change from alert to normal status
        $this->containerStatusService->changeStatus($container, ContainerStatus::AVAILABLE_FOR_RETURN, null, 'Alert cleared');

        // Assert
        $notifications = $this->entityManager->getRepository(\App\Entity\Notification::class)
            ->findBy(['user' => $terminalUser]);

        $this->assertGreaterThanOrEqual(1, count($notifications));
        
        // Find the cleared notification
        $clearedNotification = null;
        foreach ($notifications as $notification) {
            if (str_contains($notification->getMessage(), 'cleared') || str_contains($notification->getMessage(), 'resumed')) {
                $clearedNotification = $notification;
                break;
            }
        }

        $this->assertNotNull($clearedNotification);
    }

    private function createTerminalTeamUser(string $email): TerminalTeamUser
    {
        $user = new TerminalTeamUser();
        $user->setEmail($email);
        $user->setPasswordHash('hashed_password');
        $user->setFirstName('Terminal');
        $user->setLastName('User');
        $user->setDepartment('Operations');
        $user->setRole(\App\Entity\Enum\UserRole::TERMINAL_TEAM);
        $user->setStatus(AccountStatus::APPROVED);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createContainer(string $containerNumber, int $dwellTime): Container
    {
        $container = new Container();
        $container->setContainerNumber($containerNumber);
        $container->setSize('40');
        $container->setType('Standard');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setExpectedReturnDate(new \DateTime('+30 days'));
        $container->setTerminalArrivalDate(new \DateTime("-{$dwellTime} days"));
        $container->setCurrentDwellTime($dwellTime);

        $this->entityManager->persist($container);
        $this->entityManager->flush();

        return $container;
    }

    private function cleanDatabase(): void
    {
        $connection = $this->entityManager->getConnection();
        
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        
        // Only truncate tables that exist
        $tables = ['dwell_time_events', 'containers', 'terminal_team_users'];
        foreach ($tables as $table) {
            try {
                $connection->executeStatement("TRUNCATE TABLE $table");
            } catch (\Exception $e) {
                // Table might not exist, skip it
            }
        }
        
        // Try to truncate notifications table if it exists
        try {
            $connection->executeStatement('TRUNCATE TABLE notifications');
        } catch (\Exception $e) {
            // Notifications table might not exist, skip it
        }
        
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }
}
