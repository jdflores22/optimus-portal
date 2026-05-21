<?php

namespace App\Tests\Unit\Service;

use App\Entity\ShippingLine;
use App\Entity\User;
use App\Entity\StaffUser;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Repository\ShippingLineRepository;
use App\Service\ShippingLineService;
use App\Service\ActivityLogService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\RequestStack;

class ShippingLineServiceTest extends TestCase
{
    private ShippingLineService $service;
    private EntityManagerInterface|MockObject $entityManager;
    private ShippingLineRepository|MockObject $repository;
    private ActivityLogService|MockObject $activityLogService;
    private RequestStack|MockObject $requestStack;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(ShippingLineRepository::class);
        $this->activityLogService = $this->createMock(ActivityLogService::class);
        $this->requestStack = $this->createMock(RequestStack::class);

        $this->service = new ShippingLineService(
            $this->entityManager,
            $this->repository,
            $this->activityLogService,
            $this->requestStack
        );
    }

    public function testCreateShippingLineSuccess(): void
    {
        // Arrange
        $creator = new StaffUser();
        $creator->setRole(UserRole::SYSTEM_ADMIN);
        $creator->setEmail('admin@test.com');
        $creator->setStatus(AccountStatus::APPROVED);

        $data = [
            'brandName' => 'Test Shipping Line',
            'portalConfig' => ['theme' => 'blue']
        ];

        $this->repository->expects($this->once())
            ->method('findByBrandName')
            ->with('Test Shipping Line')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(ShippingLine::class));

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->activityLogService->expects($this->once())
            ->method('logShippingLineCreation')
            ->with($creator, $this->isInstanceOf(ShippingLine::class));

        // Act
        $result = $this->service->createShippingLine($data, $creator);

        // Assert
        $this->assertInstanceOf(ShippingLine::class, $result);
        $this->assertEquals('Test Shipping Line', $result->getBrandName());
        $this->assertEquals(['theme' => 'blue'], $result->getPortalConfig());
    }

    public function testCreateShippingLineFailsWithNonSystemAdmin(): void
    {
        // Arrange
        $creator = new StaffUser();
        $creator->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $creator->setEmail('admin@test.com');

        $data = ['brandName' => 'Test Shipping Line'];

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only SYSTEM_ADMIN can create shipping lines');

        // Act
        $this->service->createShippingLine($data, $creator);
    }

    public function testCreateShippingLineFailsWithEmptyBrandName(): void
    {
        // Arrange
        $creator = new StaffUser();
        $creator->setRole(UserRole::SYSTEM_ADMIN);
        $creator->setEmail('admin@test.com');

        $data = ['brandName' => ''];

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Brand name is required');

        // Act
        $this->service->createShippingLine($data, $creator);
    }

    public function testCreateShippingLineFailsWithDuplicateBrandName(): void
    {
        // Arrange
        $creator = new StaffUser();
        $creator->setRole(UserRole::SYSTEM_ADMIN);
        $creator->setEmail('admin@test.com');

        $existingShippingLine = new ShippingLine();
        $existingShippingLine->setBrandName('Existing Line');

        $data = ['brandName' => 'Existing Line'];

        $this->repository->expects($this->once())
            ->method('findByBrandName')
            ->with('Existing Line')
            ->willReturn($existingShippingLine);

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Shipping line with this brand name already exists');

        // Act
        $this->service->createShippingLine($data, $creator);
    }

    public function testAssignAdminSuccess(): void
    {
        // Arrange
        $assignor = new StaffUser();
        $assignor->setRole(UserRole::SYSTEM_ADMIN);
        $assignor->setEmail('system@test.com');

        $admin = new StaffUser();
        $admin->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $admin->setEmail('admin@test.com');
        $admin->setStatus(AccountStatus::APPROVED);

        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Line');

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->activityLogService->expects($this->once())
            ->method('logAdminAssignment')
            ->with($assignor, $admin, $shippingLine);

        // Act
        $this->service->assignAdmin($shippingLine, $admin, $assignor);

        // Assert
        $this->assertEquals($shippingLine, $admin->getManagedShippingLine());
        $this->assertTrue($shippingLine->getShippingLineAdmins()->contains($admin));
    }

    public function testAssignAdminFailsWithNonSystemAdmin(): void
    {
        // Arrange
        $assignor = new StaffUser();
        $assignor->setRole(UserRole::SHIPPING_LINES_ADMIN);

        $admin = new StaffUser();
        $admin->setRole(UserRole::SHIPPING_LINES_ADMIN);

        $shippingLine = new ShippingLine();

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only SYSTEM_ADMIN can assign shipping line admins');

        // Act
        $this->service->assignAdmin($shippingLine, $admin, $assignor);
    }

    public function testAssignAdminFailsWithWrongRole(): void
    {
        // Arrange
        $assignor = new StaffUser();
        $assignor->setRole(UserRole::SYSTEM_ADMIN);

        $admin = new StaffUser();
        $admin->setRole(UserRole::SL_STAFF);

        $shippingLine = new ShippingLine();

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User must have SHIPPING_LINES_ADMIN role');

        // Act
        $this->service->assignAdmin($shippingLine, $admin, $assignor);
    }

    public function testValidateHierarchyValidCombinations(): void
    {
        // Arrange
        $systemAdmin = new StaffUser();
        $systemAdmin->setRole(UserRole::SYSTEM_ADMIN);

        $shippingLineAdmin = new StaffUser();
        $shippingLineAdmin->setRole(UserRole::SHIPPING_LINES_ADMIN);

        $staff = new StaffUser();
        $staff->setRole(UserRole::SL_STAFF);

        $evaluator = new StaffUser();
        $evaluator->setRole(UserRole::EVALUATOR);

        $accounting = new StaffUser();
        $accounting->setRole(UserRole::ACCOUNTING);

        $terminalTeam = new StaffUser();
        $terminalTeam->setRole(UserRole::TERMINAL_TEAM);

        // Act & Assert
        $this->assertTrue($this->service->validateHierarchy($systemAdmin, $shippingLineAdmin));
        $this->assertTrue($this->service->validateHierarchy($shippingLineAdmin, $staff));
        $this->assertTrue($this->service->validateHierarchy($shippingLineAdmin, $evaluator));
        $this->assertTrue($this->service->validateHierarchy($shippingLineAdmin, $accounting));
        $this->assertTrue($this->service->validateHierarchy($shippingLineAdmin, $terminalTeam));
    }

    public function testValidateHierarchyInvalidCombinations(): void
    {
        // Arrange
        $staff = new StaffUser();
        $staff->setRole(UserRole::SL_STAFF);

        $evaluator = new StaffUser();
        $evaluator->setRole(UserRole::EVALUATOR);

        $shippingLineAdmin = new StaffUser();
        $shippingLineAdmin->setRole(UserRole::SHIPPING_LINES_ADMIN);

        // Act & Assert
        $this->assertFalse($this->service->validateHierarchy($staff, $evaluator));
        $this->assertFalse($this->service->validateHierarchy($evaluator, $shippingLineAdmin));
        $this->assertFalse($this->service->validateHierarchy($staff, $shippingLineAdmin));
    }

    public function testDeactivateShippingLineSuccess(): void
    {
        // Arrange
        $deactivator = new StaffUser();
        $deactivator->setRole(UserRole::SYSTEM_ADMIN);

        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Line');

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->activityLogService->expects($this->once())
            ->method('logShippingLineDeactivation')
            ->with($deactivator, $shippingLine);

        // Act
        $this->service->deactivateShippingLine($shippingLine, $deactivator);

        // Assert
        $this->assertFalse($shippingLine->isActive());
    }

    public function testDeactivateShippingLineFailsWithNonSystemAdmin(): void
    {
        // Arrange
        $deactivator = new StaffUser();
        $deactivator->setRole(UserRole::SHIPPING_LINES_ADMIN);

        $shippingLine = new ShippingLine();

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only SYSTEM_ADMIN can deactivate shipping lines');

        // Act
        $this->service->deactivateShippingLine($shippingLine, $deactivator);
    }

    public function testValidateShippingLineDataValid(): void
    {
        // Arrange
        $data = ['brandName' => 'Valid Shipping Line'];

        $this->repository->expects($this->once())
            ->method('findByBrandName')
            ->with('Valid Shipping Line')
            ->willReturn(null);

        // Act
        $errors = $this->service->validateShippingLineData($data);

        // Assert
        $this->assertEmpty($errors);
    }

    public function testValidateShippingLineDataInvalid(): void
    {
        // Arrange
        $data = ['brandName' => ''];

        // Act
        $errors = $this->service->validateShippingLineData($data);

        // Assert
        $this->assertContains('Brand name is required', $errors);
    }

    public function testValidateShippingLineDataTooShort(): void
    {
        // Arrange
        $data = ['brandName' => 'A'];

        $this->repository->expects($this->once())
            ->method('findByBrandName')
            ->with('A')
            ->willReturn(null);

        // Act
        $errors = $this->service->validateShippingLineData($data);

        // Assert
        $this->assertContains('Brand name must be at least 2 characters long', $errors);
    }

    public function testValidateShippingLineDataTooLong(): void
    {
        // Arrange
        $longName = str_repeat('A', 256);
        $data = ['brandName' => $longName];

        $this->repository->expects($this->once())
            ->method('findByBrandName')
            ->with($longName)
            ->willReturn(null);

        // Act
        $errors = $this->service->validateShippingLineData($data);

        // Assert
        $this->assertContains('Brand name cannot be longer than 255 characters', $errors);
    }
}