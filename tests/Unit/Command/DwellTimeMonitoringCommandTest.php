<?php

namespace App\Tests\Unit\Command;

use App\Command\DwellTimeMonitoringCommand;
use App\Service\DwellTimeMonitorInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for DwellTimeMonitoringCommand
 * 
 * **Validates: Requirements 1.1, 2.1, 8.3**
 */
class DwellTimeMonitoringCommandTest extends TestCase
{
    private DwellTimeMonitoringCommand $command;
    private DwellTimeMonitorInterface|MockObject $dwellTimeMonitor;
    private LoggerInterface|MockObject $logger;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->dwellTimeMonitor = $this->createMock(DwellTimeMonitorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->command = new DwellTimeMonitoringCommand(
            $this->dwellTimeMonitor,
            $this->logger
        );

        $this->commandTester = new CommandTester($this->command);
    }

    public function testExecuteFullProcessing(): void
    {
        // Arrange
        $notifications = [
            [
                'container_id' => 1,
                'container_number' => 'TEST123',
                'notifications' => [
                    ['type' => 'notification_60_day', 'dwell_time' => 60]
                ]
            ]
        ];
        
        $returns = [
            [
                'container_id' => 2,
                'container_number' => 'TEST456',
                'dwell_time' => 90,
                'return_date' => new \DateTime()
            ]
        ];
        
        $report = [
            'total_containers' => 10,
            'containers_approaching_notification' => 2,
            'containers_approaching_return' => 1,
            'containers_paused' => 0,
            'notifications_due' => 1,
            'returns_due' => 1,
            'summary' => [
                'Active monitoring for 10 containers',
                '2 containers approaching 60-day notification threshold',
                '1 containers approaching 90-day return threshold'
            ]
        ];

        $this->dwellTimeMonitor->expects($this->once())
            ->method('checkNotificationThresholds')
            ->willReturn($notifications);
            
        $this->dwellTimeMonitor->expects($this->once())
            ->method('processAutomaticReturns')
            ->willReturn($returns);
            
        $this->dwellTimeMonitor->expects($this->once())
            ->method('processContainers');
            
        $this->dwellTimeMonitor->expects($this->once())
            ->method('generateDailyReport')
            ->willReturn($report);

        // Act
        $exitCode = $this->commandTester->execute([]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Processing All Dwell Time Monitoring', $output);
        $this->assertStringContainsString('Found 1 container(s) requiring notifications', $output);
        $this->assertStringContainsString('Found 1 container(s) requiring automatic return', $output);
        $this->assertStringContainsString('Daily Summary Report', $output);
    }

    public function testExecuteReportOnly(): void
    {
        // Arrange
        $report = [
            'total_containers' => 5,
            'containers_approaching_notification' => 1,
            'containers_approaching_return' => 0,
            'containers_paused' => 2,
            'notifications_due' => 0,
            'returns_due' => 0,
            'summary' => [
                'Active monitoring for 5 containers',
                '1 containers approaching 60-day notification threshold',
                '0 containers approaching 90-day return threshold',
                '2 containers currently paused (alert status)'
            ]
        ];

        $this->dwellTimeMonitor->expects($this->once())
            ->method('generateDailyReport')
            ->willReturn($report);

        // Act
        $exitCode = $this->commandTester->execute(['--report-only' => true]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Generating Daily Dwell Time Report', $output);
        $this->assertStringContainsString('Total Containers', $output);
        $this->assertStringContainsString('5', $output);
        $this->assertStringContainsString('Summary', $output);
    }

    public function testExecuteNotificationsOnly(): void
    {
        // Arrange
        $notifications = [
            [
                'container_id' => 1,
                'container_number' => 'TEST123',
                'notifications' => [
                    ['type' => 'notification_60_day', 'dwell_time' => 60]
                ]
            ]
        ];

        $this->dwellTimeMonitor->expects($this->once())
            ->method('checkNotificationThresholds')
            ->willReturn($notifications);
            
        $this->dwellTimeMonitor->expects($this->once())
            ->method('processContainers');

        // Act
        $exitCode = $this->commandTester->execute(['--notifications-only' => true]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Processing Dwell Time Notifications', $output);
        $this->assertStringContainsString('TEST123', $output);
        $this->assertStringContainsString('Processed 1 container(s) for notifications', $output);
    }

    public function testExecuteReturnsOnly(): void
    {
        // Arrange
        $returns = [
            [
                'container_id' => 1,
                'container_number' => 'TEST456',
                'dwell_time' => 90,
                'return_date' => new \DateTime('2024-01-15 10:30:00')
            ]
        ];

        $this->dwellTimeMonitor->expects($this->once())
            ->method('processAutomaticReturns')
            ->willReturn($returns);

        // Act
        $exitCode = $this->commandTester->execute(['--returns-only' => true]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Processing Automatic Returns', $output);
        $this->assertStringContainsString('TEST456', $output);
        $this->assertStringContainsString('90 days', $output);
        $this->assertStringContainsString('Processed 1 container(s) for automatic return', $output);
    }

    public function testExecuteNoNotifications(): void
    {
        // Arrange
        $this->dwellTimeMonitor->expects($this->once())
            ->method('checkNotificationThresholds')
            ->willReturn([]);

        // Act
        $exitCode = $this->commandTester->execute(['--notifications-only' => true]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No containers require notifications at this time', $output);
    }

    public function testExecuteNoReturns(): void
    {
        // Arrange
        $this->dwellTimeMonitor->expects($this->once())
            ->method('processAutomaticReturns')
            ->willReturn([]);

        // Act
        $exitCode = $this->commandTester->execute(['--returns-only' => true]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No containers require automatic return at this time', $output);
    }

    public function testExecuteWithException(): void
    {
        // Arrange
        $this->dwellTimeMonitor->expects($this->once())
            ->method('checkNotificationThresholds')
            ->willThrowException(new \Exception('Database connection failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Dwell time monitoring command failed', $this->anything());

        // Act
        $exitCode = $this->commandTester->execute([]);

        // Assert
        $this->assertEquals(Command::FAILURE, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Dwell time monitoring failed: Database connection failed', $output);
    }

    public function testExecuteNoProcessingNeeded(): void
    {
        // Arrange
        $report = [
            'summary' => ['No containers require processing']
        ];

        $this->dwellTimeMonitor->expects($this->once())
            ->method('checkNotificationThresholds')
            ->willReturn([]);
            
        $this->dwellTimeMonitor->expects($this->once())
            ->method('processAutomaticReturns')
            ->willReturn([]);
            
        $this->dwellTimeMonitor->expects($this->once())
            ->method('generateDailyReport')
            ->willReturn($report);

        // Act
        $exitCode = $this->commandTester->execute([]);

        // Assert
        $this->assertEquals(Command::SUCCESS, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No containers require processing at this time', $output);
    }
}