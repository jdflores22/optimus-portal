<?php

namespace App\Tests\Command;

use App\Command\CleanupInvalidSubscriptionsCommand;
use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for push subscription cleanup command
 * Validates Requirements 16.2, 16.4 - Periodic cleanup of invalid push subscriptions
 */
class CleanupInvalidSubscriptionsCommandTest extends KernelTestCase
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
     * Test command deletes inactive push subscriptions
     * Validates Requirements 16.2, 16.4
     */
    public function testCommandDeletesInactiveSubscriptions(): void
    {
        // Create active and inactive subscriptions
        $activeSubscription = $this->createSubscription(true);
        $inactiveSubscription1 = $this->createSubscription(false);
        $inactiveSubscription2 = $this->createSubscription(false);
        
        // Run the command
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        
        $command = $application->find('app:cleanup-invalid-subscriptions');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);
        
        // Verify command succeeded
        $this->assertEquals(0, $commandTester->getStatusCode());
        
        // Verify inactive subscriptions were deleted
        $this->entityManager->clear();
        
        $activeExists = $this->entityManager->find(PushSubscription::class, $activeSubscription->getId());
        $inactive1Exists = $this->entityManager->find(PushSubscription::class, $inactiveSubscription1->getId());
        $inactive2Exists = $this->entityManager->find(PushSubscription::class, $inactiveSubscription2->getId());
        
        $this->assertNotNull($activeExists, 'Active subscription should not be deleted');
        $this->assertNull($inactive1Exists, 'Inactive subscription should be deleted');
        $this->assertNull($inactive2Exists, 'Inactive subscription should be deleted');
    }

    /**
     * Test command output shows number of deleted subscriptions
     */
    public function testCommandOutputShowsDeletedCount(): void
    {
        // Create inactive subscriptions
        $this->createSubscription(false);
        $this->createSubscription(false);
        
        // Run the command
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        
        $command = $application->find('app:cleanup-invalid-subscriptions');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);
        
        // Verify output contains success message
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Successfully removed', $output);
        $this->assertStringContainsString('invalid push subscription', $output);
    }

    /**
     * Test command handles no invalid subscriptions gracefully
     */
    public function testCommandHandlesNoInvalidSubscriptions(): void
    {
        // Clean up any existing inactive subscriptions first
        $this->entityManager->createQueryBuilder()
            ->delete(PushSubscription::class, 'ps')
            ->where('ps.isActive = false')
            ->getQuery()
            ->execute();
        
        // Run the command
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        
        $command = $application->find('app:cleanup-invalid-subscriptions');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);
        
        // Verify command succeeded
        $this->assertEquals(0, $commandTester->getStatusCode());
        
        // Verify output indicates no subscriptions to clean up
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('No invalid subscriptions found', $output);
    }

    /**
     * Test command supports dry-run option
     */
    public function testCommandSupportsDryRunOption(): void
    {
        // Create inactive subscription
        $inactiveSubscription = $this->createSubscription(false);
        
        // Run the command with dry-run option
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        
        $command = $application->find('app:cleanup-invalid-subscriptions');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--dry-run' => true]);
        
        // Verify command succeeded
        $this->assertEquals(0, $commandTester->getStatusCode());
        
        // Verify output indicates dry run mode
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('DRY RUN', $output);
        
        // Verify subscription was NOT deleted
        $this->entityManager->clear();
        $subscriptionExists = $this->entityManager->find(PushSubscription::class, $inactiveSubscription->getId());
        $this->assertNotNull($subscriptionExists, 'Subscription should not be deleted in dry-run mode');
    }

    /**
     * Helper method to create a push subscription
     */
    private function createSubscription(bool $isActive): PushSubscription
    {
        $subscription = new PushSubscription();
        $subscription->setUser($this->testUser);
        $subscription->setEndpoint('https://fcm.googleapis.com/fcm/send/test-' . uniqid());
        $subscription->setP256dhKey('test-p256dh-key-' . uniqid());
        $subscription->setAuthKey('test-auth-key-' . uniqid());
        $subscription->setIsActive($isActive);
        $subscription->setCreatedAt(new \DateTime());
        
        if ($isActive) {
            $subscription->setLastUsedAt(new \DateTime());
        }
        
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
        
        return $subscription;
    }

    protected function tearDown(): void
    {
        // Clean up test subscriptions
        if ($this->testUser) {
            $this->entityManager->createQueryBuilder()
                ->delete(PushSubscription::class, 'ps')
                ->where('ps.user = :user')
                ->setParameter('user', $this->testUser)
                ->getQuery()
                ->execute();
        }
        
        parent::tearDown();
    }
}
