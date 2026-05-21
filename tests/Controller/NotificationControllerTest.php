<?php

namespace App\Tests\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests for notification history UI and read status synchronization
 * Validates Requirements 8.1, 8.2, 8.3, 8.7
 */
class NotificationControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private NotificationRepository $notificationRepository;
    private ?User $testUser = null;

    protected function setUp(): void
    {
        parent::setUp();
        
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()->get('doctrine')->getManager();
        $this->notificationRepository = $this->entityManager->getRepository(Notification::class);
        
        // Create a test user
        $this->testUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'test@example.com']);
        if (!$this->testUser) {
            $this->markTestSkipped('Test user not found. Please create a test user with email test@example.com');
        }
    }

    /**
     * Test notification history page is accessible
     * Validates Requirement 8.1
     */
    public function testNotificationHistoryPageAccessible(): void
    {
        $client = static::createClient();
        
        // Login as test user
        $client->loginUser($this->testUser);
        
        // Access notification history page
        $crawler = $client->request('GET', '/notifications');
        
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Notifications');
    }

    /**
     * Test notification list API returns paginated results
     * Validates Requirement 8.1
     */
    public function testNotificationListApiReturnsPaginatedResults(): void
    {
        $client = static::createClient();
        $client->loginUser($this->testUser);
        
        // Create test notifications
        $this->createTestNotifications(5);
        
        // Request paginated notifications
        $client->request('GET', '/notifications/api/paginated?page=1&limit=10');
        
        $this->assertResponseIsSuccessful();
        
        $response = json_decode($client->getResponse()->getContent(), true);
        
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('notifications', $response);
        $this->assertArrayHasKey('pagination', $response);
        $this->assertIsArray($response['notifications']);
    }

    /**
     * Test notification can be marked as read
     * Validates Requirement 8.2
     */
    public function testNotificationCanBeMarkedAsRead(): void
    {
        $client = static::createClient();
        $client->loginUser($this->testUser);
        
        // Create a test notification
        $notification = $this->createTestNotification('Test notification', 'Test message', false);
        
        // Mark as read
        $client->request('POST', '/notifications/' . $notification->getId() . '/read');
        
        $this->assertResponseIsSuccessful();
        
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
        
        // Verify notification is marked as read in database
        $this->entityManager->refresh($notification);
        $this->assertTrue($notification->isRead());
        $this->assertNotNull($notification->getReadAt());
    }

    /**
     * Test unread count API returns correct count
     * Validates Requirement 8.3
     */
    public function testUnreadCountApiReturnsCorrectCount(): void
    {
        $client = static::createClient();
        $client->loginUser($this->testUser);
        
        // Create test notifications (3 unread, 2 read)
        $this->createTestNotification('Unread 1', 'Message 1', false);
        $this->createTestNotification('Unread 2', 'Message 2', false);
        $this->createTestNotification('Unread 3', 'Message 3', false);
        $this->createTestNotification('Read 1', 'Message 4', true);
        $this->createTestNotification('Read 2', 'Message 5', true);
        
        // Get unread count
        $client->request('GET', '/notifications/unread-count');
        
        $this->assertResponseIsSuccessful();
        
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
        $this->assertGreaterThanOrEqual(3, $response['count']);
    }

    /**
     * Test read status synchronization from PWA
     * Validates Requirement 8.7
     */
    public function testReadStatusSynchronizationFromPWA(): void
    {
        $client = static::createClient();
        $client->loginUser($this->testUser);
        
        // Create a test notification
        $notification = $this->createTestNotification('Test sync', 'Test message', false);
        
        // Sync read status from PWA
        $client->request('POST', '/notifications/sync-read-status', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'notificationId' => $notification->getId(),
            'isRead' => true
        ]));
        
        $this->assertResponseIsSuccessful();
        
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
        
        // Verify notification is marked as read in database
        $this->entityManager->refresh($notification);
        $this->assertTrue($notification->isRead());
    }

    /**
     * Test bulk read status synchronization
     * Validates Requirement 8.7
     */
    public function testBulkReadStatusSynchronization(): void
    {
        $client = static::createClient();
        $client->loginUser($this->testUser);
        
        // Create test notifications
        $notification1 = $this->createTestNotification('Test 1', 'Message 1', false);
        $notification2 = $this->createTestNotification('Test 2', 'Message 2', false);
        $notification3 = $this->createTestNotification('Test 3', 'Message 3', false);
        
        // Sync bulk read status
        $client->request('POST', '/notifications/sync-bulk-read-status', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'notifications' => [
                ['id' => $notification1->getId(), 'isRead' => true],
                ['id' => $notification2->getId(), 'isRead' => true],
                ['id' => $notification3->getId(), 'isRead' => false]
            ]
        ]));
        
        $this->assertResponseIsSuccessful();
        
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
        $this->assertEquals(3, $response['syncedCount']);
        
        // Verify notifications are updated in database
        $this->entityManager->refresh($notification1);
        $this->entityManager->refresh($notification2);
        $this->entityManager->refresh($notification3);
        
        $this->assertTrue($notification1->isRead());
        $this->assertTrue($notification2->isRead());
        $this->assertFalse($notification3->isRead());
    }

    /**
     * Test notification filtering by type
     * Validates Requirement 8.1
     */
    public function testNotificationFilteringByReadStatus(): void
    {
        $client = static::createClient();
        $client->loginUser($this->testUser);
        
        // Create test notifications
        $this->createTestNotification('Unread', 'Message', false);
        $this->createTestNotification('Read', 'Message', true);
        
        // Filter unread notifications
        $client->request('GET', '/notifications/api/paginated?filter=unread');
        
        $this->assertResponseIsSuccessful();
        
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
        
        // Verify all returned notifications are unread
        foreach ($response['notifications'] as $notification) {
            $this->assertFalse($notification['isRead']);
        }
    }

    /**
     * Helper method to create a test notification
     */
    private function createTestNotification(string $title, string $message, bool $isRead): Notification
    {
        $notification = new Notification();
        $notification->setUser($this->testUser);
        $notification->setTitle($title);
        $notification->setMessage($message);
        $notification->setType('info');
        $notification->setIsRead($isRead);
        
        $this->entityManager->persist($notification);
        $this->entityManager->flush();
        
        return $notification;
    }

    /**
     * Helper method to create multiple test notifications
     */
    private function createTestNotifications(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->createTestNotification("Test notification $i", "Test message $i", false);
        }
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
