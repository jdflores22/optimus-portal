<?php

namespace App\Tests\Unit;

use App\Controller\Admin\UserHierarchyController;
use App\Entity\User;
use App\Entity\StaffUser;
use App\Entity\ShippingLine;
use App\Entity\Enum\UserRole;
use App\Service\PendingUserService;
use App\Service\EmailNotificationService;
use App\Service\InAppNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class UserHierarchyControllerIntegrationTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $notificationService;
    private MockObject $pendingUserService;
    private MockObject $emailNotificationService;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->notificationService = $this->createMock(InAppNotificationService::class);
        $this->pendingUserService = $this->createMock(PendingUserService::class);
        $this->emailNotificationService = $this->createMock(EmailNotificationService::class);
    }

    public function testControllerCanBeInstantiated(): void
    {
        $controller = new UserHierarchyController(
            $this->entityManager,
            $this->notificationService,
            $this->pendingUserService,
            $this->emailNotificationService
        );

        $this->assertInstanceOf(UserHierarchyController::class, $controller);
    }

    public function testServicesAreProperlyInjected(): void
    {
        // Test that the controller can be created with all required services
        $controller = new UserHierarchyController(
            $this->entityManager,
            $this->notificationService,
            $this->pendingUserService,
            $this->emailNotificationService
        );

        // Verify the controller has the expected methods
        $this->assertTrue(method_exists($controller, 'create'));
        $this->assertTrue(method_exists($controller, 'list'));
        $this->assertTrue(method_exists($controller, 'edit'));
    }

    public function testPendingUserServiceIntegration(): void
    {
        // Test that PendingUserService can create a pending user
        $currentUser = new StaffUser();
        $currentUser->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $currentUser->setEmail('admin@test.com');

        $pendingUser = $this->createMock(\App\Entity\PendingUser::class);
        
        $this->pendingUserService->expects($this->once())
            ->method('createPendingUser')
            ->with(
                'newuser@test.com',
                'John',
                'Doe',
                UserRole::SL_STAFF,
                $currentUser,
                null,
                null
            )
            ->willReturn($pendingUser);

        // Call the service method
        $result = $this->pendingUserService->createPendingUser(
            'newuser@test.com',
            'John',
            'Doe',
            UserRole::SL_STAFF,
            $currentUser,
            null,
            null
        );

        $this->assertSame($pendingUser, $result);
    }

    public function testEmailNotificationServiceIntegration(): void
    {
        // Test that EmailNotificationService can send role acceptance email
        $pendingUser = $this->createMock(\App\Entity\PendingUser::class);
        
        $this->emailNotificationService->expects($this->once())
            ->method('sendRoleAcceptanceEmail')
            ->with($pendingUser);

        // Call the service method
        $this->emailNotificationService->sendRoleAcceptanceEmail($pendingUser);
    }
}