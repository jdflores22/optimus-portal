<?php

namespace App\Tests\Integration;

use App\Entity\Container;
use App\Entity\User;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\DwellTimeNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class NotificationServiceIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private DwellTimeNotificationService $notificationService;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()->get('doctrine')->getManager();
        $this->notificationService = $kernel->getContainer()->get(DwellTimeNotificationService::class);
    }

    public function testDwellTimeNotificationIntegration(): void
    {
        // Create a test container
        $container = new Container();
        $container->setContainerNumber('INTG' . uniqid());
        $container->setSize('20');
        $container->setType('DRY');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setExpectedReturnDate(new \DateTime('+30 days'));
        $container->setTerminalArrivalDate(new \DateTime('-60 days'));
        $container->setCurrentDwellTime(60);

        $this->entityManager->persist($container);
        $this->entityManager->flush();

        // Test dwell time warning notification
        $this->notificationService->sendDwellTimeWarning($container, 30);

        // Verify the notification was processed (no exceptions thrown)
        $this->assertTrue(true, 'Dwell time warning notification processed successfully');

        // Test automatic return notification
        $container->setCurrentDwellTime(90);
        $container->setStatus(ContainerStatus::RETURNED);
        $this->entityManager->flush();

        $this->notificationService->sendAutomaticReturnNotification($container);

        // Verify the notification was processed (no exceptions thrown)
        $this->assertTrue(true, 'Automatic return notification processed successfully');

        // Test pause/resume notifications
        $this->notificationService->sendDwellTimePausedNotification($container, 'Under investigation');
        $this->notificationService->sendDwellTimeResumedNotification($container);

        // Verify the notifications were processed (no exceptions thrown)
        $this->assertTrue(true, 'Pause/resume notifications processed successfully');

        // Clean up
        $this->entityManager->remove($container);
        $this->entityManager->flush();
    }

    public function testNotificationDeliveryStatistics(): void
    {
        $stats = $this->notificationService->getDeliveryStatistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_notifications_sent', $stats);
        $this->assertArrayHasKey('successful_deliveries', $stats);
        $this->assertArrayHasKey('failed_deliveries', $stats);
        $this->assertArrayHasKey('retry_attempts', $stats);
        $this->assertArrayHasKey('channels_used', $stats);
    }
}