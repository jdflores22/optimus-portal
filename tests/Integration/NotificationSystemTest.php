<?php

namespace App\Tests\Integration;

use App\Entity\Broker;
use App\Entity\Notification;
use App\Entity\Enum\UserRole;
use App\Service\InAppNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class NotificationSystemTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private InAppNotificationService $inAppService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->inAppService = $container->get(InAppNotificationService::class);
    }

    public function testInAppNotificationCreation(): void
    {
        // Create a test broker user
        $user = new Broker();
        $user->setEmail('test-broker@example.com');
        $user->setPasswordHash('hashed_password');
        $user->setRole(UserRole::BROKER);
        $user->setFullName('Test Broker');
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Create a notification
        $notification = $this->inAppService->createNotification(
            $user,
            'Test Notification',
            'This is a test message',
            'manifest_payment_required',
            ['manifest_id' => 123]
        );

        $this->assertInstanceOf(Notification::class, $notification);
        $this->assertEquals('Test Notification', $notification->getTitle());
        $this->assertEquals('This is a test message', $notification->getMessage());
        $this->assertEquals('warning', $notification->getType()); // Should be mapped to warning
        $this->assertFalse($notification->isRead());
        $this->assertNotNull($notification->getActionUrl());
        $this->assertNotNull($notification->getActionText());

        // Clean up
        $this->entityManager->remove($notification);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    public function testNotificationTypeMapping(): void
    {
        $user = new Broker();
        $user->setEmail('test-broker2@example.com');
        $user->setPasswordHash('hashed_password');
        $user->setRole(UserRole::BROKER);
        $user->setFullName('Test Broker 2');
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Test different event types
        $testCases = [
            ['manifest_payment_required', 'warning'],
            ['manifest_access_granted', 'success'],
            ['noa_generated', 'success'],
            ['billing_generated', 'warning'],
            ['payment_rejected', 'error'],
            ['edo_generated', 'success'],
        ];

        foreach ($testCases as [$eventType, $expectedType]) {
            $notification = $this->inAppService->createNotification(
                $user,
                'Test',
                'Test message',
                $eventType,
                ['manifest_id' => 123]
            );

            $this->assertEquals(
                $expectedType,
                $notification->getType(),
                "Event type '{$eventType}' should map to '{$expectedType}'"
            );

            $this->entityManager->remove($notification);
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    public function testNotificationActionUrlGeneration(): void
    {
        $user = new Broker();
        $user->setEmail('test-broker3@example.com');
        $user->setPasswordHash('hashed_password');
        $user->setRole(UserRole::BROKER);
        $user->setFullName('Test Broker 3');
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Test action URL generation for different event types
        $testCases = [
            ['manifest_payment_required', '/manifest/123/payment', 'Submit Payment'],
            ['manifest_access_granted', '/manifest/123', 'View Manifest'],
            ['noa_generated', '/manifest/123', 'View Manifest'],
            ['billing_generated', '/manifest/123', 'View Manifest'],
            ['payment_rejected', '/manifest/123/payment', 'Resubmit Payment'],
            ['edo_generated', '/manifest/123', 'View Manifest'],
        ];

        foreach ($testCases as [$eventType, $expectedUrl, $expectedText]) {
            $notification = $this->inAppService->createNotification(
                $user,
                'Test',
                'Test message',
                $eventType,
                ['manifest_id' => 123]
            );

            $this->assertEquals(
                $expectedUrl,
                $notification->getActionUrl(),
                "Event type '{$eventType}' should generate URL '{$expectedUrl}'"
            );

            $this->assertEquals(
                $expectedText,
                $notification->getActionText(),
                "Event type '{$eventType}' should generate text '{$expectedText}'"
            );

            $this->entityManager->remove($notification);
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    public function testMarkNotificationAsRead(): void
    {
        $user = new Broker();
        $user->setEmail('test-broker4@example.com');
        $user->setPasswordHash('hashed_password');
        $user->setRole(UserRole::BROKER);
        $user->setFullName('Test Broker 4');
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $notification = $this->inAppService->createNotification(
            $user,
            'Test',
            'Test message',
            'info'
        );

        $this->assertFalse($notification->isRead());
        $this->assertNull($notification->getReadAt());

        $notification->markAsRead();
        $this->entityManager->flush();

        $this->assertTrue($notification->isRead());
        $this->assertNotNull($notification->getReadAt());

        // Clean up
        $this->entityManager->remove($notification);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}
