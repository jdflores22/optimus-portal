<?php

namespace App\Tests\Command;

use App\Command\CleanupNotificationHistoryCommand;
use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for notification history retention cleanup command
 * Validates Requirement 8.6: PWA SHALL retain notification history for 90 days
 */
class CleanupNotificationHistoryCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ?User $testUser = null;

    protected function setUp(): void
    {
        parent::setUp();
        
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()->get('doctrine')->getManager();
        
        // Create or get test user
        $this->testUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'test@example.com']);
        if (!$this->testUser) {
            $this->markTestSkipped('Test user not found. Please create a test user with email test@example.com');
        }
    }

    /**
     * Test command deletes notifications older than 90 days
     * Validates Requirement 8.6
     */
    public function testCommandDeletesNotificationsOlderThan90Days(): void
    {
        // Create notifications with different ages
        $oldNotification = $this->createNotificationWithAge(100); // 100 days old - should be deleted
        $recentNotification = $this->createNotificationWithAge(30); // 30 days old - should be kept
        $veryOldNotification = $this->createNotificationWithAge(365); // 1 year old - should be deleted
        
        // Run the command
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        
        $command = $application->find('app:cleanup-notification-history');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);
        
        // Verify command succeeded
        $this->assertEquals(0, $commandTester->getStatusCode());
        
        // Verify old notifications were deleted
        $this->entityManager->clear();
        
        $oldNotificationExists = $this->entityManager->find(Notification::class, $oldNotification->getId());
        $recentNotificationExists = $this->entityManager->find(Notification::class, $recentNotification->getId());
        $veryOldNotificationExists = $this->entityManager->find(Notification::class, $veryOldNotification->getId());
        
        $this->assertNull($oldNotificationExists, 'Notification older than 90 days should be deleted');
        $this->assertNotNull($recentNotificationExists, 'Notification younger than 90 days should be kept');
        $this->assertNull($veryOldNotificationExists, 'Very old notification should be deleted');
    }

    /**
     * Test command deletes both read and unread old notifications
     * Validates Requirement 8.6
     */
    public function testCommandDeletesBothReadAndUnreadOldNotifications(): void
    {
        // Create old notifications with different read statuses
        $oldReadNotification = $this->createNotificationWithAge(100, true);
        $oldUnreadNotification = $this->createNotificationWithAge(100, false);
        
        // Run the command
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        
        $command = $application->find('app:cleanup-notification-history');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);
        
        // Verify command succeeded
        $this->assertEquals(0, $commandTester->getStatusCode());
        
        // Verify both notifications were deleted
        $this->entityManager->clear();
        
        $oldReadExists = $this->entityManager->find(Notification::class, $oldReadNotification->getId());
        $oldUnreadExists = $this->entityManager->find(Notification::class, $oldUnreadNotification->getId());
        
        $this->assertNull($oldReadExists, 'Old read notification should be deleted');
        $this->assertNull($oldUnreadExists, 'Old unread notification should be deleted');
    }

    /**
     * Test command output shows number of deleted notifications
     */
    public function testCommandOutputShowsDeletedCount(): void
    {
        // Create old notifications
        $this->createNotificationWithAge(100);
        $this->createNotificationWithAge(120);
        
        // Run the command
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        
        $command = $application->find('app:cleanup-notification-history');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);
        
        // Verify output contains success message
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Successfully deleted', $output);
        $this->assertStringContainsString('notifications older than 90 days', $output);
    }

    /**
     * Test command keeps notifications exactly 90 days old
     * Validates the boundary condition
     */
    public function testCommandKeepsNotificationsExactly90DaysOld(): void
    {
        // Create notification exactly 90 days old
        $notification90Days = $this->createNotificationWithAge(90);
        
        // Run the command
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        
        $command = $application->find('app:cleanup-notification-history');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);
        
        // Verify notification still exists (90 days is the boundary, should be kept)
        $this->entityManager->clear();
        $notificationExists = $this->entityManager->find(Notification::class, $notification90Days->getId());
        
        // Note: Depending on implementation, this might be deleted or kept
        // The requirement says "retain for 90 days", which typically means delete after 90 days
        // So notifications exactly 90 days old might be on the boundary
        $this->assertNotNull($notificationExists, 'Notification exactly 90 days old should be kept (boundary condition)');
    }

    /**
     * Helper method to create a notification with a specific age in days
     */
    private function createNotificationWithAge(int $daysOld, bool $isRead = false): Notification
    {
        $notification = new Notification();
        $notification->setUser($this->testUser);
        $notification->setTitle("Test notification - $daysOld days old");
        $notification->setMessage("This notification is $daysOld days old");
        $notification->setType('info');
        $notification->setIsRead($isRead);
        
        // Set created_at to the past
        $createdAt = new \DateTime("-$daysOld days");
        $notification->setCreatedAt($createdAt);
        
        if ($isRead) {
            $notification->setReadAt(new \DateTime("-" . ($daysOld - 1) . " days"));
        }
        
        $this->entityManager->persist($notification);
        $this->entityManager->flush();
        
        return $notification;
    }

    protected function tearDown(): void
    {
        // Clean up test notifications
        if ($this->testUser) {
            $this->entityManager->createQueryBuilder()
                ->delete(Notification::class, 'n')
                ->where('n.user = :user')
                ->setParameter('user', $this->testUser)
                ->getQuery()
                ->execute();
        }
        
        parent::tearDown();
    }
}
