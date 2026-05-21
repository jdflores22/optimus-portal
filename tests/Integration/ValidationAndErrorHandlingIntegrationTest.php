<?php

namespace App\Tests\Integration;

use App\Entity\ShippingLine;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Exception\ValidationException;
use App\Service\ValidationService;
use App\Service\ErrorRecoveryService;
use App\Service\ShippingLineService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for validation and error handling functionality
 * Tests Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.6
 */
class ValidationAndErrorHandlingIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ValidationService $validationService;
    private ErrorRecoveryService $errorRecoveryService;
    private ShippingLineService $shippingLineService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->validationService = $container->get(ValidationService::class);
        $this->errorRecoveryService = $container->get(ErrorRecoveryService::class);
        $this->shippingLineService = $container->get(ShippingLineService::class);

        // Start transaction for test isolation
        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        // Rollback transaction to clean up test data
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }
        parent::tearDown();
    }

    /**
     * Test comprehensive input validation for shipping line creation
     * Requirements: 10.1, 10.3
     */
    public function testShippingLineValidationWithValidData(): void
    {
        // Arrange
        $data = [
            'brandName' => 'Test Shipping Line ' . uniqid(),
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

        // Act
        $errors = $this->validationService->validateShippingLineCreation($data);

        // Assert
        $this->assertEmpty($errors, 'Valid data should not produce validation errors');
    }

    /**
     * Test validation error handling with descriptive messages
     * Requirements: 10.1, 10.3
     */
    public function testShippingLineValidationWithInvalidData(): void
    {
        // Arrange
        $data = [
            'brandName' => '', // Invalid: empty
            'portalConfig' => [
                'branding' => [
                    'primaryColor' => 'invalid-color', // Invalid color
                    'invalidKey' => 'value' // Invalid key
                ]
            ]
        ];

        // Act
        $errors = $this->validationService->validateShippingLineCreation($data);

        // Assert
        $this->assertNotEmpty($errors, 'Invalid data should produce validation errors');
        $this->assertArrayHasKey('brandName', $errors);
        $this->assertArrayHasKey('portalConfig', $errors);
        $this->assertContains('Brand name is required and cannot be empty', $errors['brandName']);
    }

    /**
     * Test user hierarchy validation
     * Requirements: 10.2, 10.3
     */
    public function testUserHierarchyValidationWithMissingAdmin(): void
    {
        // Arrange
        $data = [
            'email' => 'test@example.com',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'role' => UserRole::SL_STAFF->value
            // Missing shippingLineAdminId
        ];

        // Act
        $errors = $this->validationService->validateUserHierarchyCreation($data);

        // Assert
        $this->assertNotEmpty($errors);
        $this->assertArrayHasKey('hierarchy', $errors);
        $this->assertContains("Role 'SL_STAFF' requires a shipping line admin to be specified", $errors['hierarchy']);
    }

    /**
     * Test constraint violation handling
     * Requirements: 10.4, 10.5
     */
    public function testConstraintViolationHandling(): void
    {
        // Arrange
        $exception = new \Exception('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'test-brand\' for key \'brand_name\'');

        // Act
        $errors = $this->validationService->handleConstraintViolation($exception);

        // Assert
        $this->assertNotEmpty($errors);
        $this->assertContains('A shipping line with this brand name already exists. Please choose a different name', $errors);
    }

    /**
     * Test automatic cleanup functionality
     * Requirements: 10.4, 10.5, 10.6
     */
    public function testAutomaticCleanup(): void
    {
        // Act
        $result = $this->errorRecoveryService->performAutomaticCleanup();

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('orphaned_users_cleaned', $result);
        $this->assertArrayHasKey('inactive_sessions_cleaned', $result);
        $this->assertArrayHasKey('temporary_data_cleaned', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertIsInt($result['orphaned_users_cleaned']);
    }

    /**
     * Test shipping line creation with validation exception handling
     * Requirements: 10.1, 10.3, 10.5
     */
    public function testShippingLineCreationWithValidationException(): void
    {
        // Arrange
        $systemAdmin = $this->createTestUser(UserRole::SYSTEM_ADMIN);
        $invalidData = [
            'brandName' => '', // Invalid
            'portalConfig' => null // Invalid
        ];

        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->shippingLineService->createShippingLine($invalidData, $systemAdmin);
    }

    /**
     * Test error recovery for shipping line creation failures
     * Requirements: 10.4, 10.5, 10.6
     */
    public function testErrorRecoveryForShippingLineCreation(): void
    {
        // Arrange
        $systemAdmin = $this->createTestUser(UserRole::SYSTEM_ADMIN);
        $data = ['brandName' => 'Test Line', 'portalConfig' => []];
        $exception = new \Exception('Test failure');

        // Act
        $result = $this->errorRecoveryService->handleShippingLineCreationFailure($data, $exception, $systemAdmin);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('degraded_mode', $result);
        $this->assertArrayHasKey('cleanup_performed', $result);
        $this->assertArrayHasKey('error_message', $result);
        $this->assertArrayHasKey('recovery_actions', $result);
        $this->assertFalse($result['success']);
    }

    /**
     * Test rollback capabilities for complex operations
     * Requirements: 10.4, 10.5, 10.6
     */
    public function testRollbackCapabilities(): void
    {
        // Arrange
        $systemAdmin = $this->createTestUser(UserRole::SYSTEM_ADMIN);
        $operationData = ['shipping_line_id' => 999]; // Non-existent ID
        
        // Act
        $result = $this->errorRecoveryService->rollbackComplexOperation(
            'shipping_line_creation_with_admin',
            $operationData,
            $systemAdmin
        );

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('operations_rolled_back', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertTrue($result['success']); // Should succeed even with non-existent ID
    }

    /**
     * Test comprehensive validation with business rules
     * Requirements: 10.1, 10.2, 10.3
     */
    public function testComprehensiveValidationWithBusinessRules(): void
    {
        // Arrange
        $data = [
            'brandName' => 'A', // Too short
            'portalConfig' => [
                'branding' => [
                    'primaryColor' => '#GGGGGG', // Invalid hex
                    'logoUrl' => 'not-a-url', // Invalid URL
                    'companyName' => str_repeat('A', 300) // Too long
                ],
                'features' => [
                    'invalidFeature' => true // Invalid feature
                ]
            ]
        ];

        // Act
        $errors = $this->validationService->validateShippingLineCreation($data);

        // Assert
        $this->assertNotEmpty($errors);
        $this->assertArrayHasKey('brandName', $errors);
        $this->assertArrayHasKey('portalConfig', $errors);
        
        // Check specific validation messages
        $this->assertContains('Brand name must be at least 2 characters long', $errors['brandName']);
        
        $brandingErrors = $errors['portalConfig']['branding'];
        $this->assertContains('Primary color must be a valid hex color (e.g., #FF0000) or CSS color name', $brandingErrors);
        $this->assertContains('Logo URL must be a valid URL', $brandingErrors);
        $this->assertContains('Company name cannot exceed 255 characters', $brandingErrors);
        
        $featureErrors = $errors['portalConfig']['features'];
        $this->assertContains("Invalid feature flag: 'invalidFeature'", $featureErrors);
    }

    private function createTestUser(UserRole $role): User
    {
        $user = new User();
        $user->setEmail('test-' . uniqid() . '@example.com');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setRole($role);
        $user->setPassword('hashed_password');

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}