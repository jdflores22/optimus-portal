<?php

namespace App\Tests\Service;

use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\Enum\AccreditationStatus;
use App\Entity\Enum\UserRole;
use App\Service\NotificationService;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Twig\Environment;

/**
 * Feature: optimus-shipping-portal, Property 10: Email notification delivery with retry
 * 
 * For any critical event (accreditation status change, EDO generation), 
 * an email notification should be sent, and if delivery fails, 
 * the system should retry up to three times.
 * 
 * Validates: Requirements 13.1, 13.2, 13.5
 */
class EmailNotificationDeliveryRetryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private UserService $userService;
    private UserPasswordHasherInterface $passwordHasher;
    private Environment $twig;
    private LoggerInterface $logger;
    private \App\Service\RetryService $retryService;
    private \App\Service\FileService $fileService;

    protected function setUp(): void
    {
        parent::setUp();
        
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        $this->userService = new UserService($this->entityManager, $this->passwordHasher);
        $this->twig = $container->get(Environment::class);
        $this->logger = $container->get(LoggerInterface::class);
        $this->retryService = $container->get(\App\Service\RetryService::class);
        $this->fileService = $container->get(\App\Service\FileService::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up database
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $connection->executeStatement('TRUNCATE TABLE users');
        $connection->executeStatement('TRUNCATE TABLE consignees');
        $connection->executeStatement('TRUNCATE TABLE brokers');
        $connection->executeStatement('TRUNCATE TABLE staff_users');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        
        $this->entityManager->close();
    }

    /**
     * Property: For any accreditation status change notification, if email delivery fails,
     * the system should retry up to 3 times before giving up
     */
    public function testAccreditationStatusChangeNotificationRetrySucceedsAfterFailures(): void
    {
        // Create user for testing
        $user = $this->createUserForTest('test@example.com', 'Test Business', UserRole::CONSIGNEE);
        
        // Create mock mailer that fails 2 times then succeeds
        $mockMailer = new MockMailerWithRetry(2);
        
        // Create notification service with mock mailer
        $notificationService = new NotificationService(
            $mockMailer,
            $this->twig,
            $this->entityManager,
            $this->logger,
            $this->retryService,
            $this->fileService,
            'test@optimus-portal.com',
            'admin@optimus-portal.com'
        );

        // Should succeed after retries
        $notificationService->sendAccreditationStatusChange($user, AccreditationStatus::APPROVED);
        
        // Should have made 3 attempts (2 failures + 1 success)
        $this->assertEquals(3, $mockMailer->getSendAttempts());

        // Clean up
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    /**
     * Property: For any notification, if email delivery fails more than 3 times,
     * the system should give up and throw an exception
     */
    public function testAccreditationStatusChangeNotificationFailsAfterMaxRetries(): void
    {
        // Create user for testing
        $user = $this->createUserForTest('test2@example.com', 'Test Business 2', UserRole::BROKER);
        
        // Create mock mailer that always fails
        $mockMailer = new MockMailerWithRetry(5); // More failures than max retries
        
        $notificationService = new NotificationService(
            $mockMailer,
            $this->twig,
            $this->entityManager,
            $this->logger,
            $this->retryService,
            $this->fileService,
            'test@optimus-portal.com',
            'admin@optimus-portal.com'
        );

        // Should fail after all retries
        $this->expectException(\Exception::class);
        $notificationService->sendAccreditationStatusChange($user, AccreditationStatus::DENIED);
    }

    /**
     * Property: For any broker linkage notification, the system should attempt delivery
     * and retry on failure up to the maximum retry limit
     */
    public function testBrokerLinkageNotificationRetryLogic(): void
    {
        // Create broker and consignee
        $broker = $this->createUserForTest('broker@example.com', 'Broker Business', UserRole::BROKER);
        $consignee = $this->createUserForTest('consignee@example.com', 'Consignee Business', UserRole::CONSIGNEE);
        
        // Link consignee to broker
        $consignee->setLinkedBroker($broker);
        $this->entityManager->flush();

        // Create mock mailer that fails once then succeeds
        $mockMailer = new MockMailerWithRetry(1);
        
        $notificationService = new NotificationService(
            $mockMailer,
            $this->twig,
            $this->entityManager,
            $this->logger,
            $this->retryService,
            $this->fileService,
            'test@optimus-portal.com',
            'admin@optimus-portal.com'
        );

        // Should succeed after one retry
        $notificationService->sendBrokerLinkageNotification($broker, $consignee);
        
        // Should have made 2 attempts (1 failure + 1 success)
        $this->assertEquals(2, $mockMailer->getSendAttempts());

        // Clean up
        $this->entityManager->remove($consignee);
        $this->entityManager->remove($broker);
        $this->entityManager->flush();
    }

    /**
     * Property: For any account lock notification, the system should handle delivery
     * failures with appropriate retry logic
     */
    public function testAccountLockNotificationRetrySucceeds(): void
    {
        // Create user
        $user = $this->createUserForTest('locked@example.com', 'Locked Business', UserRole::CONSIGNEE);
        
        // Create mock mailer that succeeds immediately
        $mockMailer = new MockMailerWithRetry(0);
        
        $notificationService = new NotificationService(
            $mockMailer,
            $this->twig,
            $this->entityManager,
            $this->logger,
            $this->retryService,
            $this->fileService,
            'test@optimus-portal.com',
            'admin@optimus-portal.com'
        );

        // Should succeed on first try
        $notificationService->sendAccountLockNotification($user);
        
        // Should have made 1 attempt
        $this->assertEquals(1, $mockMailer->getSendAttempts());

        // Clean up
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    /**
     * Helper method to create a user for testing
     */
    private function createUserForTest(string $email, string $businessName, UserRole $role): \App\Entity\User
    {
        $data = [
            'email' => $email,
            'password' => 'TestPass123!',
        ];

        if ($role === UserRole::CONSIGNEE || $role === UserRole::BROKER) {
            $data['businessName'] = $businessName;
        } else {
            $data['firstName'] = 'Test';
            $data['lastName'] = 'User';
            $data['department'] = 'Testing';
        }

        return $this->userService->createUser($data, $role);
    }
}

/**
 * Mock mailer class that simulates failures and tracks send attempts
 */
class MockMailerWithRetry implements MailerInterface
{
    private int $sendAttempts = 0;
    private int $failuresRemaining;

    public function __construct(int $failureCount)
    {
        $this->failuresRemaining = $failureCount;
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->sendAttempts++;
        
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;
            throw new TransportException('Simulated email delivery failure');
        }
        
        // Success - email would be sent
    }

    public function getSendAttempts(): int
    {
        return $this->sendAttempts;
    }
}