<?php

namespace App\Tests\Unit\Service;

use App\Entity\ShippingLine;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Repository\ShippingLineRepository;
use App\Service\ValidationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ValidationServiceTest extends TestCase
{
    private ValidationService $validationService;
    private ShippingLineRepository $shippingLineRepository;
    private EntityManagerInterface $entityManager;
    private EntityRepository $userRepository;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->shippingLineRepository = $this->createMock(ShippingLineRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Configure entity manager to return user repository
        $this->entityManager->method('getRepository')
            ->with(User::class)
            ->willReturn($this->userRepository);

        $this->validationService = new ValidationService(
            $this->shippingLineRepository,
            $this->entityManager,
            $this->logger
        );
    }

    public function testValidateShippingLineCreationWithValidData(): void
    {
        // Arrange
        $data = [
            'brandName' => 'Test Shipping Line',
            'portalConfig' => [
                'branding' => [
                    'primaryColor' => '#FF0000',
                    'companyName' => 'Test Company'
                ],
                'features' => [
                    'enableNotifications' => true
                ]
            ]
        ];

        $this->shippingLineRepository->method('findByBrandName')->willReturn(null);

        // Act
        $errors = $this->validationService->validateShippingLineCreation($data);

        // Assert
        $this->assertEmpty($errors);
    }

    public function testValidateShippingLineCreationWithMissingBrandName(): void
    {
        // Arrange
        $data = [
            'portalConfig' => []
        ];

        // Act
        $errors = $this->validationService->validateShippingLineCreation($data);

        // Assert
        $this->assertArrayHasKey('brandName', $errors);
        $this->assertContains('Brand name is required and cannot be empty', $errors['brandName']);
    }

    public function testValidateShippingLineCreationWithDuplicateBrandName(): void
    {
        // Arrange
        $data = [
            'brandName' => 'Existing Shipping Line',
            'portalConfig' => []
        ];

        $existingShippingLine = $this->createMock(ShippingLine::class);
        $existingShippingLine->method('getId')->willReturn(1);
        $this->shippingLineRepository->method('findByBrandName')->willReturn($existingShippingLine);

        // Act
        $errors = $this->validationService->validateShippingLineCreation($data);

        // Assert
        $this->assertArrayHasKey('brandName', $errors);
        $this->assertContains('A shipping line with this brand name already exists. Brand names must be unique across the system', $errors['brandName']);
    }

    public function testValidateShippingLineCreationWithInvalidBrandName(): void
    {
        // Arrange
        $data = [
            'brandName' => 'A', // Too short
            'portalConfig' => []
        ];

        $this->shippingLineRepository->method('findByBrandName')->willReturn(null);

        // Act
        $errors = $this->validationService->validateShippingLineCreation($data);

        // Assert
        $this->assertArrayHasKey('brandName', $errors);
        $this->assertContains('Brand name must be at least 2 characters long', $errors['brandName']);
    }

    public function testValidateShippingLineCreationWithInvalidPortalConfig(): void
    {
        // Arrange
        $data = [
            'brandName' => 'Valid Name',
            'portalConfig' => [
                'branding' => [
                    'primaryColor' => 'invalid-color',
                    'invalidKey' => 'value'
                ]
            ]
        ];

        $this->shippingLineRepository->method('findByBrandName')->willReturn(null);

        // Act
        $errors = $this->validationService->validateShippingLineCreation($data);

        // Assert
        $this->assertArrayHasKey('portalConfig', $errors);
        $this->assertArrayHasKey('branding', $errors['portalConfig']);
    }

    public function testValidateUserHierarchyCreationWithValidData(): void
    {
        // Arrange
        $data = [
            'email' => 'test@example.com',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'role' => UserRole::SL_STAFF->value,
            'shippingLineAdminId' => 1
        ];

        $this->userRepository->method('findOneBy')->willReturn(null);
        
        $admin = $this->createMock(User::class);
        $admin->method('getRole')->willReturn(UserRole::SHIPPING_LINES_ADMIN);
        $admin->method('isActive')->willReturn(true);
        $this->userRepository->method('find')->willReturn($admin);

        // Act
        $errors = $this->validationService->validateUserHierarchyCreation($data);

        // Assert
        $this->assertEmpty($errors);
    }

    public function testValidateUserHierarchyCreationWithMissingEmail(): void
    {
        // Arrange
        $data = [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'role' => UserRole::SL_STAFF->value
        ];

        // Act
        $errors = $this->validationService->validateUserHierarchyCreation($data);

        // Assert
        $this->assertArrayHasKey('email', $errors);
        $this->assertContains('Email is required', $errors['email']);
    }

    public function testValidateUserHierarchyCreationWithInvalidEmail(): void
    {
        // Arrange
        $data = [
            'email' => 'invalid-email',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'role' => UserRole::SL_STAFF->value
        ];

        // Act
        $errors = $this->validationService->validateUserHierarchyCreation($data);

        // Assert
        $this->assertArrayHasKey('email', $errors);
        $this->assertContains('Email must be a valid email address', $errors['email']);
    }

    public function testValidateUserHierarchyCreationWithDuplicateEmail(): void
    {
        // Arrange
        $data = [
            'email' => 'existing@example.com',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'role' => UserRole::SL_STAFF->value
        ];

        $existingUser = $this->createMock(User::class);
        $this->userRepository->method('findOneBy')->willReturn($existingUser);

        // Act
        $errors = $this->validationService->validateUserHierarchyCreation($data);

        // Assert
        $this->assertArrayHasKey('email', $errors);
        $this->assertContains('A user with this email address already exists', $errors['email']);
    }

    public function testValidateUserHierarchyCreationWithMissingAdmin(): void
    {
        // Arrange
        $data = [
            'email' => 'test@example.com',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'role' => UserRole::SL_STAFF->value
            // Missing shippingLineAdminId
        ];

        $this->userRepository->method('findOneBy')->willReturn(null);

        // Act
        $errors = $this->validationService->validateUserHierarchyCreation($data);

        // Assert
        $this->assertArrayHasKey('hierarchy', $errors);
        $this->assertContains("Role 'SL_STAFF' requires a shipping line admin to be specified", $errors['hierarchy']);
    }

    public function testValidateUserHierarchyCreationWithInvalidRole(): void
    {
        // Arrange
        $data = [
            'email' => 'test@example.com',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'role' => 'INVALID_ROLE'
        ];

        $this->userRepository->method('findOneBy')->willReturn(null);

        // Act
        $errors = $this->validationService->validateUserHierarchyCreation($data);

        // Assert
        $this->assertArrayHasKey('role', $errors);
        $this->assertStringContainsString('Invalid role', $errors['role'][0]);
    }

    public function testHandleConstraintViolationWithForeignKeyError(): void
    {
        // Arrange
        $exception = new \Exception('foreign key constraint violation on shipping_line_admin_id');

        // Act
        $errors = $this->validationService->handleConstraintViolation($exception);

        // Assert
        $this->assertNotEmpty($errors);
        $this->assertContains('Cannot create user: The specified shipping line admin does not exist or has been deleted', $errors);
    }

    public function testHandleConstraintViolationWithUniqueConstraintError(): void
    {
        // Arrange
        $exception = new \Exception('unique constraint violation on brand_name');

        // Act
        $errors = $this->validationService->handleConstraintViolation($exception);

        // Assert
        $this->assertNotEmpty($errors);
        $this->assertContains('A shipping line with this brand name already exists. Please choose a different name', $errors);
    }

    public function testValidateBrandingConfigurationWithValidColors(): void
    {
        // Arrange
        $data = [
            'brandName' => 'Test Line',
            'portalConfig' => [
                'branding' => [
                    'primaryColor' => '#FF0000',
                    'secondaryColor' => 'blue',
                    'companyName' => 'Test Company'
                ]
            ]
        ];

        $this->shippingLineRepository->method('findByBrandName')->willReturn(null);

        // Act
        $errors = $this->validationService->validateShippingLineCreation($data);

        // Assert
        $this->assertEmpty($errors);
    }

    public function testValidateBrandingConfigurationWithInvalidColors(): void
    {
        // Arrange
        $data = [
            'brandName' => 'Test Line',
            'portalConfig' => [
                'branding' => [
                    'primaryColor' => 'invalid-color',
                    'secondaryColor' => '#GGGGGG'
                ]
            ]
        ];

        $this->shippingLineRepository->method('findByBrandName')->willReturn(null);

        // Act
        $errors = $this->validationService->validateShippingLineCreation($data);

        // Assert
        $this->assertArrayHasKey('portalConfig', $errors);
        $this->assertArrayHasKey('branding', $errors['portalConfig']);
    }
}