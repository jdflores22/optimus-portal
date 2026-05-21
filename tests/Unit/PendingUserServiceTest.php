<?php

namespace App\Tests\Unit;

use App\Entity\PendingUser;
use App\Entity\StaffUser;
use App\Entity\User;
use App\Entity\ShippingLine;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Repository\PendingUserRepository;
use App\Service\PendingUserService;
use App\Service\EmailNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PendingUserServiceTest extends TestCase
{
    private PendingUserService $pendingUserService;
    private EntityManagerInterface|MockObject $entityManager;
    private PendingUserRepository|MockObject $pendingUserRepository;
    private UserPasswordHasherInterface|MockObject $passwordHasher;
    private EmailNotificationService|MockObject $emailNotificationService;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->pendingUserRepository = $this->createMock(PendingUserRepository::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->emailNotificationService = $this->createMock(EmailNotificationService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->pendingUserService = new PendingUserService(
            $this->entityManager,
            $this->pendingUserRepository,
            $this->passwordHasher,
            $this->emailNotificationService,
            $this->logger
        );
    }

    private function createMockAdmin(): StaffUser
    {
        $admin = new StaffUser();
        $admin->setId(1)
            ->setEmail('admin@example.com')
            ->setPasswordHash('hashed_password')
            ->setRole(UserRole::SYSTEM_ADMIN)
            ->setStatus(AccountStatus::APPROVED)
            ->setFirstName('Admin')
            ->setLastName('User')
            ->setDepartment('IT');
        return $admin;
    }

    public function testCreatePendingUser(): void
    {
        // Arrange
        $email = 'test@example.com';
        $firstName = 'John';
        $lastName = 'Doe';
        $role = UserRole::CONSIGNEE;
        
        $admin = $this->createMockAdmin();

        // Mock repository to return no existing pending users
        $this->pendingUserRepository
            ->expects($this->once())
            ->method('findByEmail')
            ->with($email)
            ->willReturn([]);

        // Mock entity manager to return no existing user
        $userRepository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $userRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => $email])
            ->willReturn(null);

        $this->entityManager
            ->expects($this->once())
            ->method('getRepository')
            ->with(User::class)
            ->willReturn($userRepository);

        $this->entityManager
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(PendingUser::class));

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        // Act
        $pendingUser = $this->pendingUserService->createPendingUser(
            $email,
            $firstName,
            $lastName,
            $role,
            $admin
        );

        // Assert
        $this->assertInstanceOf(PendingUser::class, $pendingUser);
        $this->assertEquals($email, $pendingUser->getEmail());
        $this->assertEquals($firstName, $pendingUser->getFirstName());
        $this->assertEquals($lastName, $pendingUser->getLastName());
        $this->assertEquals($role, $pendingUser->getRole());
        $this->assertEquals($admin, $pendingUser->getCreatedByAdmin());
        $this->assertEquals(64, strlen($pendingUser->getAcceptanceToken()));
        $this->assertTrue($pendingUser->isTokenValid());
    }

    public function testCreatePendingUserWithInvalidEmail(): void
    {
        // Arrange
        $email = 'invalid-email';
        $firstName = 'John';
        $lastName = 'Doe';
        $role = UserRole::CONSIGNEE;
        
        $admin = $this->createMockAdmin();

        // Expect exception
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Valid email address is required');

        // Act
        $this->pendingUserService->createPendingUser(
            $email,
            $firstName,
            $lastName,
            $role,
            $admin
        );
    }

    public function testFindByToken(): void
    {
        // Arrange
        $token = str_repeat('a', 64);
        $pendingUser = new PendingUser();

        $this->pendingUserRepository
            ->expects($this->once())
            ->method('findByToken')
            ->with($token)
            ->willReturn($pendingUser);

        // Act
        $result = $this->pendingUserService->findByToken($token);

        // Assert
        $this->assertSame($pendingUser, $result);
    }

    public function testFindByTokenWithInvalidToken(): void
    {
        // Arrange
        $token = 'invalid-token';

        // Act
        $result = $this->pendingUserService->findByToken($token);

        // Assert
        $this->assertNull($result);
    }

    public function testAcceptRole(): void
    {
        // Arrange
        $pendingUser = new PendingUser();
        $pendingUser->setEmail('test@example.com')
            ->setFirstName('John')
            ->setLastName('Doe')
            ->setRole(UserRole::CONSIGNEE)
            ->generateAcceptanceToken()
            ->setTokenExpirationToSevenDays();

        $admin = $this->createMockAdmin();
        $pendingUser->setCreatedByAdmin($admin);

        $this->passwordHasher
            ->expects($this->once())
            ->method('hashPassword')
            ->willReturn('hashed_temp_password');

        $this->entityManager
            ->expects($this->once())
            ->method('beginTransaction');

        $this->entityManager
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(User::class));

        // Expect the pending user to be marked as accepted (not removed immediately)
        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $this->entityManager
            ->expects($this->once())
            ->method('commit');

        // Act
        $user = $this->pendingUserService->acceptRole($pendingUser);

        // Assert
        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals($pendingUser->getEmail(), $user->getEmail());
        $this->assertEquals($pendingUser->getRole(), $user->getRole());
        $this->assertEquals(AccountStatus::APPROVED, $user->getStatus());
        $this->assertTrue($user->isEmailVerified(), 'Email should be verified when accepting invitation');
        // Pending user should be marked as accepted (not removed immediately)
        $this->assertEquals('accepted', $pendingUser->getStatus());
    }

    public function testAcceptRoleForStaffUserSetsDepartment(): void
    {
        // Arrange
        $pendingUser = new PendingUser();
        $pendingUser->setEmail('staff@example.com')
            ->setFirstName('Jane')
            ->setLastName('Staff')
            ->setRole(UserRole::SL_STAFF)
            ->generateAcceptanceToken()
            ->setTokenExpirationToSevenDays();

        $admin = $this->createMockAdmin();
        $pendingUser->setCreatedByAdmin($admin);

        $this->passwordHasher
            ->expects($this->once())
            ->method('hashPassword')
            ->willReturn('hashed_temp_password');

        $this->entityManager
            ->expects($this->once())
            ->method('beginTransaction');

        $this->entityManager
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(StaffUser::class));

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $this->entityManager
            ->expects($this->once())
            ->method('commit');

        // Act
        $user = $this->pendingUserService->acceptRole($pendingUser);

        // Assert
        $this->assertInstanceOf(StaffUser::class, $user);
        $this->assertEquals('Operations', $user->getDepartment());
        $this->assertEquals($pendingUser->getFirstName(), $user->getFirstName());
        $this->assertEquals($pendingUser->getLastName(), $user->getLastName());
        $this->assertTrue($user->isEmailVerified(), 'Email should be verified when accepting invitation');
        // Pending user should be marked as accepted
        $this->assertEquals('accepted', $pendingUser->getStatus());
    }

    public function testDeclineRole(): void
    {
        // Arrange
        $pendingUser = new PendingUser();
        $pendingUser->setEmail('test@example.com')
            ->setFirstName('John')
            ->setLastName('Doe')
            ->setRole(UserRole::CONSIGNEE)
            ->generateAcceptanceToken()
            ->setTokenExpirationToSevenDays();

        $admin = $this->createMockAdmin();
        $pendingUser->setCreatedByAdmin($admin);

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        // Act
        $this->pendingUserService->declineRole($pendingUser);

        // Assert
        $this->assertEquals('declined', $pendingUser->getStatus());
    }

    public function testExpirePendingUsers(): void
    {
        // Arrange
        $expiredCount = 5;

        $this->pendingUserRepository
            ->expects($this->once())
            ->method('removeExpired')
            ->willReturn($expiredCount);

        // Act
        $result = $this->pendingUserService->expirePendingUsers();

        // Assert
        $this->assertEquals($expiredCount, $result);
    }

    public function testResendInvitation(): void
    {
        // Arrange
        $pendingUser = new PendingUser();
        $pendingUser->setEmail('test@example.com')
            ->setFirstName('John')
            ->setLastName('Doe')
            ->setRole(UserRole::CONSIGNEE)
            ->setStatus('pending')
            ->generateAcceptanceToken()
            ->setTokenExpirationToSevenDays();

        $originalToken = $pendingUser->getAcceptanceToken();

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        // Act
        $this->pendingUserService->resendInvitation($pendingUser);

        // Assert
        $this->assertEquals('pending', $pendingUser->getStatus());
        $this->assertNotEquals($originalToken, $pendingUser->getAcceptanceToken());
        $this->assertTrue($pendingUser->isTokenValid());
    }
}