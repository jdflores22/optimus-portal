<?php

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Entity\ShippingLine;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\AuthenticationService;
use App\Service\ActivityLogService;
use App\Service\ScopeAccessControlService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\Exception\LockedException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class AuthenticationServiceTest extends TestCase
{
    private AuthenticationService $service;
    private EntityManagerInterface|MockObject $entityManager;
    private UserPasswordHasherInterface|MockObject $passwordHasher;
    private ActivityLogService|MockObject $activityLogService;
    private ScopeAccessControlService|MockObject $scopeAccessControlService;
    private RequestStack|MockObject $requestStack;
    private EntityRepository|MockObject $userRepository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->activityLogService = $this->createMock(ActivityLogService::class);
        $this->scopeAccessControlService = $this->createMock(ScopeAccessControlService::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->userRepository = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')
            ->with(User::class)
            ->willReturn($this->userRepository);

        $this->service = new AuthenticationService(
            $this->entityManager,
            $this->passwordHasher,
            $this->activityLogService,
            $this->scopeAccessControlService,
            $this->requestStack
        );
    }

    public function testSuccessfulAuthentication(): void
    {
        // Arrange
        $email = 'test@example.com';
        $password = 'password123';
        $ipAddress = '192.168.1.1';
        $userAgent = 'Mozilla/5.0';

        $user = $this->createUser($email, UserRole::SHIPPING_LINES_ADMIN);
        $user->method('isLocked')->willReturn(false);
        $user->method('isActive')->willReturn(true);
        $user->method('validateHierarchy')->willReturn([]);
        $user->method('requiresShippingLineAdmin')->willReturn(false);

        $request = $this->createMock(Request::class);
        $request->method('getClientIp')->willReturn($ipAddress);
        $request->headers = $this->createMock(\Symfony\Component\HttpFoundation\HeaderBag::class);
        $request->headers->method('get')->with('User-Agent')->willReturn($userAgent);

        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->passwordHasher->method('isPasswordValid')->willReturn(true);

        $user->expects($this->once())->method('resetFailedLoginAttempts');
        $user->expects($this->once())->method('setLockedUntil')->with(null);
        $this->entityManager->expects($this->once())->method('flush');

        $this->activityLogService->expects($this->once())
            ->method('logLogin')
            ->with($user, $ipAddress, $userAgent);

        // Act
        $result = $this->service->authenticateUser($email, $password);

        // Assert
        $this->assertSame($user, $result);
    }

    public function testAuthenticationFailsForNonExistentUser(): void
    {
        // Arrange
        $email = 'nonexistent@example.com';
        $password = 'password123';

        $this->userRepository->method('findOneBy')->willReturn(null);
        $this->activityLogService->expects($this->once())
            ->method('logFailedLogin')
            ->with($email, '127.0.0.1');

        // Act & Assert
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $this->service->authenticateUser($email, $password);
    }

    public function testAuthenticationFailsForLockedAccount(): void
    {
        // Arrange
        $email = 'locked@example.com';
        $password = 'password123';

        $user = $this->createUser($email, UserRole::SL_STAFF);
        $user->method('isLocked')->willReturn(true);

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->activityLogService->expects($this->once())
            ->method('logAccessDenied')
            ->with($user, 'login', 'Account locked');

        // Act & Assert
        $this->expectException(LockedException::class);
        $this->expectExceptionMessage('Account is locked due to too many failed login attempts');

        $this->service->authenticateUser($email, $password);
    }

    public function testAuthenticationFailsForInactiveAccount(): void
    {
        // Arrange
        $email = 'inactive@example.com';
        $password = 'password123';

        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn($email);
        $user->method('getRole')->willReturn(UserRole::SL_STAFF);
        $user->method('isLocked')->willReturn(false);
        $user->method('getStatus')->willReturn(AccountStatus::EMAIL_UNVERIFIED);

        $this->userRepository->method('findOneBy')->willReturn($user);
        
        // Password hasher should not be called for inactive accounts
        $this->passwordHasher->expects($this->never())->method('isPasswordValid');
        
        $this->activityLogService->expects($this->once())
            ->method('logAccessDenied')
            ->with($user, 'login', 'Account not active');

        // Act & Assert
        $this->expectException(DisabledException::class);
        $this->expectExceptionMessage('Account is not active. Please verify your email address.');

        $this->service->authenticateUser($email, $password);
    }

    public function testAuthenticationFailsForInvalidHierarchy(): void
    {
        // Arrange
        $email = 'invalid@example.com';
        $password = 'password123';

        $user = $this->createUser($email, UserRole::SL_STAFF);
        $user->method('isLocked')->willReturn(false);
        $user->method('isActive')->willReturn(true);
        $user->method('validateHierarchy')->willReturn(['Invalid hierarchy']);

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->activityLogService->expects($this->once())
            ->method('logAccessDenied')
            ->with($user, 'login', 'Invalid user hierarchy');

        // Act & Assert
        $this->expectException(DisabledException::class);
        $this->expectExceptionMessage('Account configuration is invalid. Please contact administrator.');

        $this->service->authenticateUser($email, $password);
    }

    public function testAuthenticationFailsForWrongPassword(): void
    {
        // Arrange
        $email = 'test@example.com';
        $password = 'wrongpassword';

        $user = $this->createUser($email, UserRole::SL_STAFF);
        $user->method('isLocked')->willReturn(false);
        $user->method('isActive')->willReturn(true);
        $user->method('validateHierarchy')->willReturn([]);
        $user->method('requiresShippingLineAdmin')->willReturn(false);

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->passwordHasher->method('isPasswordValid')->willReturn(false);

        $user->expects($this->once())->method('incrementFailedLoginAttempts');
        $this->entityManager->expects($this->once())->method('flush');
        $this->activityLogService->expects($this->once())
            ->method('logAccessDenied')
            ->with($user, 'login', 'Invalid password');

        // Act & Assert
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $this->service->authenticateUser($email, $password);
    }

    public function testAuthenticationFailsForInactiveShippingLineAdmin(): void
    {
        // Arrange
        $email = 'staff@example.com';
        $password = 'password123';

        $inactiveAdmin = $this->createUser('admin@example.com', UserRole::SHIPPING_LINES_ADMIN);
        $inactiveAdmin->method('isActive')->willReturn(false);

        $user = $this->createUser($email, UserRole::SL_STAFF);
        $user->method('isLocked')->willReturn(false);
        $user->method('isActive')->willReturn(true);
        $user->method('validateHierarchy')->willReturn([]);
        $user->method('requiresShippingLineAdmin')->willReturn(true);
        $user->method('getShippingLineAdmin')->willReturn($inactiveAdmin);

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->passwordHasher->method('isPasswordValid')->willReturn(true);
        $this->activityLogService->expects($this->once())
            ->method('logAccessDenied')
            ->with($user, 'login', 'Shipping line admin inactive');

        // Act & Assert
        $this->expectException(DisabledException::class);
        $this->expectExceptionMessage('Your shipping line administrator is inactive. Please contact support.');

        $this->service->authenticateUser($email, $password);
    }

    public function testAuthorizeActionForSystemAdmin(): void
    {
        // Arrange
        $user = $this->createUser('admin@example.com', UserRole::SYSTEM_ADMIN);
        $user->method('isActive')->willReturn(true);

        // Act & Assert
        $this->assertTrue($this->service->authorizeAction($user, 'any_action'));
    }

    public function testAuthorizeActionFailsForInactiveUser(): void
    {
        // Arrange
        $user = $this->createUser('inactive@example.com', UserRole::SL_STAFF);
        $user->method('isActive')->willReturn(false);

        $this->activityLogService->expects($this->once())
            ->method('logAccessDenied')
            ->with($user, 'test_action', 'User not active');

        // Act & Assert
        $this->assertFalse($this->service->authorizeAction($user, 'test_action'));
    }

    public function testAuthorizeActionWithResourceValidation(): void
    {
        // Arrange
        $user = $this->createUser('user@example.com', UserRole::SHIPPING_LINES_ADMIN);
        $user->method('isActive')->willReturn(true);
        $resource = new \stdClass();

        $this->scopeAccessControlService->expects($this->once())
            ->method('validateAccess')
            ->with($user, $resource);

        // Act & Assert
        $this->assertTrue($this->service->authorizeAction($user, 'test_action', $resource));
    }

    public function testPreventPrivilegeEscalation(): void
    {
        // Arrange
        $user = $this->createUser('staff@example.com', UserRole::SL_STAFF);
        $targetRole = 'SYSTEM_ADMIN';
        $action = 'admin_access';

        $this->scopeAccessControlService->expects($this->once())
            ->method('preventPrivilegeEscalation')
            ->with($user, "Attempted to access {$targetRole} while having SL_STAFF role for action: {$action}");

        // Act & Assert
        $this->service->preventPrivilegeEscalation($user, $targetRole, $action);
    }

    public function testChangePasswordSuccess(): void
    {
        // Arrange
        $user = $this->createUser('user@example.com', UserRole::SL_STAFF);
        $currentPassword = 'oldpassword';
        $newPassword = 'newpassword';
        $hashedPassword = 'hashed_new_password';

        $this->passwordHasher->method('isPasswordValid')->willReturn(true);
        $this->passwordHasher->method('hashPassword')->willReturn($hashedPassword);

        $user->expects($this->once())->method('setPasswordHash')->with($hashedPassword);
        $this->entityManager->expects($this->once())->method('flush');
        $this->activityLogService->expects($this->once())->method('logPasswordChange')->with($user);

        // Act
        $this->service->changePassword($user, $currentPassword, $newPassword);
    }

    public function testChangePasswordFailsForWrongCurrentPassword(): void
    {
        // Arrange
        $user = $this->createUser('user@example.com', UserRole::SL_STAFF);
        $currentPassword = 'wrongpassword';
        $newPassword = 'newpassword';

        $this->passwordHasher->method('isPasswordValid')->willReturn(false);
        $this->activityLogService->expects($this->once())
            ->method('logAccessDenied')
            ->with($user, 'password_change', 'Invalid current password');

        // Act & Assert
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Current password is incorrect');

        $this->service->changePassword($user, $currentPassword, $newPassword);
    }

    public function testUnlockAccountSuccess(): void
    {
        // Arrange
        $actor = $this->createUser('admin@example.com', UserRole::SYSTEM_ADMIN);
        
        // Create a separate mock for the locked user
        $targetUser = $this->createMock(User::class);
        $targetUser->method('getEmail')->willReturn('locked@example.com');
        $targetUser->method('getRole')->willReturn(UserRole::SL_STAFF);
        $targetUser->method('getStatus')->willReturn(AccountStatus::LOCKED);

        $actor->method('canManageUser')->willReturn(true);

        $targetUser->expects($this->once())->method('resetFailedLoginAttempts');
        $targetUser->expects($this->once())->method('setLockedUntil')->with(null);
        $targetUser->expects($this->once())->method('setStatus')->with(AccountStatus::APPROVED);
        $this->entityManager->expects($this->once())->method('flush');
        $this->activityLogService->expects($this->once())->method('logUserActivation')->with($actor, $targetUser);

        // Act
        $this->service->unlockAccount($actor, $targetUser);
    }

    public function testUnlockAccountFailsForUnauthorizedUser(): void
    {
        // Arrange
        $actor = $this->createUser('staff@example.com', UserRole::SL_STAFF);
        $targetUser = $this->createUser('other@example.com', UserRole::SL_STAFF);

        $actor->method('canManageUser')->willReturn(false);
        $this->activityLogService->expects($this->once())
            ->method('logAccessDenied')
            ->with($actor, 'unlock_account', 'Cannot manage target user');

        // Act & Assert
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('You do not have permission to unlock this account');

        $this->service->unlockAccount($actor, $targetUser);
    }

    public function testValidateSessionSuccess(): void
    {
        // Arrange
        $user = $this->createUser('user@example.com', UserRole::SHIPPING_LINES_ADMIN);
        $user->method('isActive')->willReturn(true);
        $user->method('validateHierarchy')->willReturn([]);
        $user->method('requiresShippingLineAdmin')->willReturn(false);

        // Act & Assert
        $this->assertTrue($this->service->validateSession($user));
    }

    public function testValidateSessionFailsForInactiveUser(): void
    {
        // Arrange
        $user = $this->createUser('user@example.com', UserRole::SL_STAFF);
        $user->method('isActive')->willReturn(false);

        // Act & Assert
        $this->assertFalse($this->service->validateSession($user));
    }

    public function testLogoutUser(): void
    {
        // Arrange
        $user = $this->createUser('user@example.com', UserRole::SL_STAFF);

        $this->activityLogService->expects($this->once())->method('logLogout')->with($user);

        // Act
        $this->service->logoutUser($user);
    }

    public function testLogSessionTimeout(): void
    {
        // Arrange
        $user = $this->createUser('user@example.com', UserRole::SL_STAFF);

        $this->activityLogService->expects($this->once())->method('logSessionTimeout')->with($user);

        // Act
        $this->service->logSessionTimeout($user);
    }

    // Helper methods

    private function createUser(string $email, UserRole $role): User|MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn($email);
        $user->method('getRole')->willReturn($role);
        $user->method('getStatus')->willReturn(AccountStatus::APPROVED);
        
        return $user;
    }
}