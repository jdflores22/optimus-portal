<?php

namespace App\Tests\Service;

use App\Entity\Broker;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\EmailVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Psr\Log\LoggerInterface;

/**
 * Test: Email Verification Service
 * 
 * This test verifies that the email verification system works correctly:
 * 1. Verification tokens are generated and stored
 * 2. Verification emails are sent
 * 3. Email verification works with valid tokens
 * 4. Invalid/expired tokens are rejected
 * 
 * **Validates: User registration email verification requirements**
 */
class EmailVerificationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private EmailVerificationService $emailVerificationService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        
        // Create mock services for testing
        $mockMailer = $this->createMock(MailerInterface::class);
        $mockTwig = $this->createMock(Environment::class);
        $mockUrlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $mockLogger = $this->createMock(LoggerInterface::class);
        
        // Configure mocks
        $mockTwig->method('render')->willReturn('<html>Test email</html>');
        $mockUrlGenerator->method('generate')->willReturn('http://test.com/verify/token123');
        
        $this->emailVerificationService = new EmailVerificationService(
            $this->entityManager,
            $mockMailer,
            $mockTwig,
            $mockUrlGenerator,
            $mockLogger,
            'test@optimus-portal.com'
        );

        // Start transaction for each test
        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        // Rollback transaction to clean up
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }
        parent::tearDown();
    }

    /**
     * Test: Email verification token generation and storage
     */
    public function testSendVerificationEmailGeneratesToken(): void
    {
        // Create test user
        $user = $this->createTestUser();
        
        // Initially user should not have verification token
        $this->assertNull($user->getEmailVerificationToken());
        $this->assertNull($user->getEmailVerificationTokenExpiresAt());
        $this->assertFalse($user->isEmailVerified());
        $this->assertEquals(AccountStatus::EMAIL_UNVERIFIED, $user->getStatus());

        // Send verification email
        $this->emailVerificationService->sendVerificationEmail($user);

        // Refresh user from database
        $this->entityManager->refresh($user);

        // Verify token was generated and stored
        $this->assertNotNull($user->getEmailVerificationToken());
        $this->assertNotNull($user->getEmailVerificationTokenExpiresAt());
        $this->assertEquals(64, strlen($user->getEmailVerificationToken())); // 32 bytes = 64 hex chars
        $this->assertTrue($user->getEmailVerificationTokenExpiresAt() > new \DateTime());
        $this->assertEquals(AccountStatus::EMAIL_UNVERIFIED, $user->getStatus());
        $this->assertFalse($user->isEmailVerified());
    }

    /**
     * Test: Email verification with valid token
     */
    public function testVerifyEmailWithValidToken(): void
    {
        // Create test user and send verification email
        $user = $this->createTestUser();
        $this->emailVerificationService->sendVerificationEmail($user);
        $this->entityManager->refresh($user);
        
        $token = $user->getEmailVerificationToken();
        $this->assertNotNull($token);

        // Verify email with token
        $verifiedUser = $this->emailVerificationService->verifyEmail($token);

        // Verify the verification was successful
        $this->assertNotNull($verifiedUser);
        $this->assertEquals($user->getId(), $verifiedUser->getId());
        $this->assertTrue($verifiedUser->isEmailVerified());
        $this->assertNotNull($verifiedUser->getEmailVerifiedAt());
        $this->assertNull($verifiedUser->getEmailVerificationToken());
        $this->assertNull($verifiedUser->getEmailVerificationTokenExpiresAt());
        $this->assertEquals(AccountStatus::PENDING, $verifiedUser->getStatus());
    }

    /**
     * Test: Email verification with invalid token
     */
    public function testVerifyEmailWithInvalidToken(): void
    {
        $invalidToken = 'invalid_token_123';
        
        $result = $this->emailVerificationService->verifyEmail($invalidToken);
        
        $this->assertNull($result);
    }

    /**
     * Test: Email verification with expired token
     */
    public function testVerifyEmailWithExpiredToken(): void
    {
        // Create test user and send verification email
        $user = $this->createTestUser();
        $this->emailVerificationService->sendVerificationEmail($user);
        $this->entityManager->refresh($user);
        
        $token = $user->getEmailVerificationToken();
        
        // Manually expire the token
        $user->setEmailVerificationTokenExpiresAt(new \DateTime('-1 hour'));
        $this->entityManager->flush();

        // Try to verify with expired token
        $result = $this->emailVerificationService->verifyEmail($token);
        
        $this->assertNull($result);
    }

    /**
     * Test: Cannot resend verification to already verified user
     */
    public function testCannotResendVerificationToVerifiedUser(): void
    {
        // Create test user and verify email
        $user = $this->createTestUser();
        $this->emailVerificationService->sendVerificationEmail($user);
        $this->entityManager->refresh($user);
        
        $token = $user->getEmailVerificationToken();
        $this->emailVerificationService->verifyEmail($token);
        $this->entityManager->refresh($user);

        // Try to resend verification to verified user
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Email is already verified');
        
        $this->emailVerificationService->resendVerificationEmail($user);
    }

    /**
     * Test: User login check with email verification
     */
    public function testCanUserLoginChecksEmailVerification(): void
    {
        // Create unverified user
        $user = $this->createTestUser();
        $this->assertFalse($this->emailVerificationService->canUserLogin($user));

        // Send verification email
        $this->emailVerificationService->sendVerificationEmail($user);
        $this->entityManager->refresh($user);
        $this->assertFalse($this->emailVerificationService->canUserLogin($user));

        // Verify email
        $token = $user->getEmailVerificationToken();
        $this->emailVerificationService->verifyEmail($token);
        $this->entityManager->refresh($user);
        $this->assertTrue($this->emailVerificationService->canUserLogin($user));
    }

    private function createTestUser(): Broker
    {
        $user = new Broker();
        $user->setEmail('test' . uniqid() . '@example.com');
        $user->setPasswordHash('hashed_password');
        $user->setRole(UserRole::BROKER);
        $user->setBusinessName('Test Business');

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}