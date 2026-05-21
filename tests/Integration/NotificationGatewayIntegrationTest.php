<?php

namespace App\Tests\Integration;

use App\Entity\Broker;
use App\Entity\PushSubscription;
use App\Service\NotificationGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test for NotificationGateway
 * Validates that notifications are routed to all available channels
 */
class NotificationGatewayIntegrationTest extends KernelTestCase
{
    private NotificationGateway $gateway;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        $this->gateway = $container->get(NotificationGateway::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testGetAvailableChannelsForUserWithoutPushSubscriptions(): void
    {
        // Create a test broker without push subscriptions
        $broker = new Broker();
        $broker->setEmail('test@example.com');
        $broker->setFullName('Test Broker');
        
        $channels = $this->gateway->getAvailableChannels($broker);
        
        // Should have in_app and email, but not push
        $this->assertContains('in_app', $channels);
        $this->assertContains('email', $channels);
        $this->assertNotContains('push', $channels);
    }

    public function testGetAvailableChannelsForUserWithPushSubscriptions(): void
    {
        // Create a test broker
        $broker = new Broker();
        $broker->setEmail('test@example.com');
        $broker->setFullName('Test Broker');
        
        $this->entityManager->persist($broker);
        $this->entityManager->flush();
        
        // Create an active push subscription for the broker
        $subscription = new PushSubscription();
        $subscription->setUser($broker);
        $subscription->setEndpoint('https://fcm.googleapis.com/fcm/send/test-endpoint');
        $subscription->setP256dhKey('test-p256dh-key');
        $subscription->setAuthKey('test-auth-key');
        $subscription->setIsActive(true);
        $subscription->setCreatedAt(new \DateTime());
        
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
        
        $channels = $this->gateway->getAvailableChannels($broker);
        
        // Should have all three channels
        $this->assertContains('in_app', $channels);
        $this->assertContains('email', $channels);
        $this->assertContains('push', $channels);
        $this->assertCount(3, $channels);
        
        // Cleanup
        $this->entityManager->remove($subscription);
        $this->entityManager->remove($broker);
        $this->entityManager->flush();
    }

    public function testGetAvailableChannelsForUserWithInactivePushSubscription(): void
    {
        // Create a test broker
        $broker = new Broker();
        $broker->setEmail('test@example.com');
        $broker->setFullName('Test Broker');
        
        $this->entityManager->persist($broker);
        $this->entityManager->flush();
        
        // Create an inactive push subscription for the broker
        $subscription = new PushSubscription();
        $subscription->setUser($broker);
        $subscription->setEndpoint('https://fcm.googleapis.com/fcm/send/test-endpoint');
        $subscription->setP256dhKey('test-p256dh-key');
        $subscription->setAuthKey('test-auth-key');
        $subscription->setIsActive(false); // Inactive
        $subscription->setCreatedAt(new \DateTime());
        
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
        
        $channels = $this->gateway->getAvailableChannels($broker);
        
        // Should not have push channel since subscription is inactive
        $this->assertContains('in_app', $channels);
        $this->assertContains('email', $channels);
        $this->assertNotContains('push', $channels);
        
        // Cleanup
        $this->entityManager->remove($subscription);
        $this->entityManager->remove($broker);
        $this->entityManager->flush();
    }
}
