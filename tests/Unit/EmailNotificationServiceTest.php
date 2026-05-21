<?php

namespace App\Tests\Unit;

use App\Entity\PendingUser;
use App\Entity\User;
use App\Entity\ShippingLine;
use App\Entity\StaffUser;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\EmailNotificationService;
use App\Service\InAppNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for EmailNotificationService
 * 
 * **Validates: Requirements 1.1, 1.5, 4.3, 5.1, 5.2, 5.3, 8.1, 8.2, 8.3, 8.4**
 */
class EmailNotificationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private EmailNotificationService $emailNotificationService;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);

        // Create mock services for testing
        $mockMailer = $this->createMock(MailerInterface::class);
        $mockTwig = $this->createMock(Environment::class);
        $mockUrlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $mockLogger = $this->createMock(LoggerInterface::class);
        $mockNotificationService = $this->createMock(InAppNotificationService::class);

        // Configure mocks
        $mockTwig->method('render')->willReturn('<html>Test Email</html>');
        $mockUrlGenerator->method('generate')->willReturn('http://test.com/role-acceptance/token123');

        $this->emailNotificationService = new EmailNotificationService(
            $this->entityManager,
            $mockMailer,
            $mockTwig,
            $mockUrlGenerator,
            $mockLogger,
            $mockNotificationService,
            'test@optimus.com'
        );
    }

    public function testSendRoleAcceptanceEmailWithValidPendingUser(): void
    {
        // Create test admin user with unique email
        $admin = new StaffUser();
        $admin->setEmail('admin_acceptance_' . uniqid() . '@test.com');
        $admin->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $admin->setStatus(AccountStatus::APPROVED);
        $admin->setFirstName('Admin');
        $admin->setLastName('User');
        $admin->setDepartment('IT');
        $admin->setPasswordHash('hashed_password');

        $this->entityManager->persist($admin);

        // Create test shipping line with unique name
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Shipping Line ' . uniqid());

        $this->entityManager->persist($shippingLine);

        // Create test pending user
        $pendingUser = new PendingUser();
        $pendingUser->setEmail('test@example.com');
        $pendingUser->setFirstName('John');
        $pendingUser->setLastName('Doe');
        $pendingUser->setRole(UserRole::SL_STAFF);
        $pendingUser->setCreatedByAdmin($admin);
        $pendingUser->setShippingLine($shippingLine);
        $pendingUser->generateAcceptanceToken();
        $pendingUser->setTokenExpirationToSevenDays();

        $this->entityManager->persist($pendingUser);
        $this->entityManager->flush();

        // Test sending role acceptance email
        $this->expectNotToPerformAssertions(); // We're testing that no exception is thrown
        $this->emailNotificationService->sendRoleAcceptanceEmail($pendingUser);
    }

    public function testSendWelcomeEmailWithValidUser(): void
    {
        // Create test user with unique email
        $user = new StaffUser();
        $user->setEmail('newuser_' . uniqid() . '@test.com');
        $user->setRole(UserRole::SL_STAFF);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setFirstName('Jane');
        $user->setLastName('Smith');
        $user->setDepartment('Operations');
        $user->setPasswordHash('hashed_password');

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Test sending welcome email
        $this->expectNotToPerformAssertions(); // We're testing that no exception is thrown
        $this->emailNotificationService->sendWelcomeEmail($user);
    }

    public function testSendRoleDeclinedNotificationWithValidData(): void
    {
        // Create test admin user with unique email
        $admin = new StaffUser();
        $admin->setEmail('admin_declined_' . uniqid() . '@test.com');
        $admin->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $admin->setStatus(AccountStatus::APPROVED);
        $admin->setFirstName('Admin');
        $admin->setLastName('User');
        $admin->setDepartment('IT');
        $admin->setPasswordHash('hashed_password');

        $this->entityManager->persist($admin);

        // Create test pending user
        $pendingUser = new PendingUser();
        $pendingUser->setEmail('declined@example.com');
        $pendingUser->setFirstName('Declined');
        $pendingUser->setLastName('User');
        $pendingUser->setRole(UserRole::CONSIGNEE);
        $pendingUser->setCreatedByAdmin($admin);
        $pendingUser->generateAcceptanceToken();
        $pendingUser->setTokenExpirationToSevenDays();
        $pendingUser->markAsDeclined();

        $this->entityManager->persist($pendingUser);
        $this->entityManager->flush();

        // Test sending role declined notification
        $this->expectNotToPerformAssertions(); // We're testing that no exception is thrown
        $this->emailNotificationService->sendRoleDeclinedNotification($admin, $pendingUser);
    }

    public function testSendRoleAcceptedNotificationWithValidData(): void
    {
        // Create test admin user with unique email
        $admin = new StaffUser();
        $admin->setEmail('admin_accepted_' . uniqid() . '@test.com');
        $admin->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $admin->setStatus(AccountStatus::APPROVED);
        $admin->setFirstName('Admin');
        $admin->setLastName('User');
        $admin->setDepartment('IT');
        $admin->setPasswordHash('hashed_password');

        $this->entityManager->persist($admin);

        // Create test new user with unique email
        $newUser = new StaffUser();
        $newUser->setEmail('accepted_' . uniqid() . '@test.com');
        $newUser->setRole(UserRole::SL_STAFF);
        $newUser->setStatus(AccountStatus::APPROVED);
        $newUser->setFirstName('Accepted');
        $newUser->setLastName('User');
        $newUser->setDepartment('Operations');
        $newUser->setPasswordHash('hashed_password');

        $this->entityManager->persist($newUser);
        $this->entityManager->flush();

        // Test sending role accepted notification
        $this->expectNotToPerformAssertions(); // We're testing that no exception is thrown
        $this->emailNotificationService->sendRoleAcceptedNotification($admin, $newUser);
    }

    public function testSendTokenExpiredNotificationWithValidData(): void
    {
        // Create test admin user with unique email
        $admin = new StaffUser();
        $admin->setEmail('admin_expired_' . uniqid() . '@test.com');
        $admin->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $admin->setStatus(AccountStatus::APPROVED);
        $admin->setFirstName('Admin');
        $admin->setLastName('User');
        $admin->setDepartment('IT');
        $admin->setPasswordHash('hashed_password');

        $this->entityManager->persist($admin);

        // Create test expired pending user
        $pendingUser = new PendingUser();
        $pendingUser->setEmail('expired@example.com');
        $pendingUser->setFirstName('Expired');
        $pendingUser->setLastName('User');
        $pendingUser->setRole(UserRole::BROKER);
        $pendingUser->setCreatedByAdmin($admin);
        $pendingUser->generateAcceptanceToken();
        $pendingUser->setTokenExpiresAt(new \DateTime('-1 day')); // Expired
        $pendingUser->markAsExpired();

        $this->entityManager->persist($pendingUser);
        $this->entityManager->flush();

        // Test sending token expired notification
        $this->expectNotToPerformAssertions(); // We're testing that no exception is thrown
        $this->emailNotificationService->sendTokenExpiredNotification($admin, $pendingUser);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    private function createTestUser(): StaffUser
    {
        $user = new StaffUser();
        $user->setEmail('test' . uniqid() . '@example.com');
        $user->setRole(UserRole::SL_STAFF);
        $user->setStatus(AccountStatus::EMAIL_UNVERIFIED);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setDepartment('Test Department');
        $user->setPasswordHash('hashed_password');

        return $user;
    }
}