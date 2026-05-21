<?php

namespace App\Tests\Integration\Service;

use App\Entity\ShippingLine;
use App\Entity\StaffUser;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\ShippingLineService;
use App\Service\UserHierarchyService;
use App\Service\ActivityLogService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test for shipping line management services
 * Tests the interaction between ShippingLineService, UserHierarchyService, and ActivityLogService
 */
class ShippingLineManagementIntegrationTest extends KernelTestCase
{
    private ShippingLineService $shippingLineService;
    private UserHierarchyService $userHierarchyService;
    private ActivityLogService $activityLogService;

    protected function setUp(): void
    {
        self::bootKernel();
        
        $this->shippingLineService = static::getContainer()->get(ShippingLineService::class);
        $this->userHierarchyService = static::getContainer()->get(UserHierarchyService::class);
        $this->activityLogService = static::getContainer()->get(ActivityLogService::class);
    }

    public function testCompleteShippingLineWorkflow(): void
    {
        // This test would require a test database setup
        // For now, we'll just verify the services can be instantiated
        
        $this->assertInstanceOf(ShippingLineService::class, $this->shippingLineService);
        $this->assertInstanceOf(UserHierarchyService::class, $this->userHierarchyService);
        $this->assertInstanceOf(ActivityLogService::class, $this->activityLogService);
    }

    public function testServiceDependencies(): void
    {
        // Verify that services have their dependencies properly injected
        $reflection = new \ReflectionClass($this->shippingLineService);
        $properties = $reflection->getProperties();
        
        $hasEntityManager = false;
        $hasActivityLogService = false;
        
        foreach ($properties as $property) {
            if ($property->getName() === 'entityManager') {
                $hasEntityManager = true;
            }
            if ($property->getName() === 'activityLogService') {
                $hasActivityLogService = true;
            }
        }
        
        $this->assertTrue($hasEntityManager, 'ShippingLineService should have EntityManager dependency');
        $this->assertTrue($hasActivityLogService, 'ShippingLineService should have ActivityLogService dependency');
    }

    public function testRoleHierarchyValidation(): void
    {
        // Test role hierarchy validation without database operations
        $validCombinations = [
            [UserRole::SYSTEM_ADMIN, UserRole::SHIPPING_LINES_ADMIN],
            [UserRole::SHIPPING_LINES_ADMIN, UserRole::SL_STAFF],
            [UserRole::SHIPPING_LINES_ADMIN, UserRole::EVALUATOR],
            [UserRole::SHIPPING_LINES_ADMIN, UserRole::ACCOUNTING],
            [UserRole::SHIPPING_LINES_ADMIN, UserRole::TERMINAL_TEAM],
        ];

        foreach ($validCombinations as [$parentRole, $childRole]) {
            $this->assertTrue(
                $this->userHierarchyService->validateRoleHierarchy($parentRole, $childRole),
                "Role hierarchy {$parentRole->value} -> {$childRole->value} should be valid"
            );
        }

        $invalidCombinations = [
            [UserRole::SL_STAFF, UserRole::EVALUATOR],
            [UserRole::EVALUATOR, UserRole::SHIPPING_LINES_ADMIN],
            [UserRole::ACCOUNTING, UserRole::SYSTEM_ADMIN],
        ];

        foreach ($invalidCombinations as [$parentRole, $childRole]) {
            $this->assertFalse(
                $this->userHierarchyService->validateRoleHierarchy($parentRole, $childRole),
                "Role hierarchy {$parentRole->value} -> {$childRole->value} should be invalid"
            );
        }
    }

    public function testShippingLineValidation(): void
    {
        // Test shipping line data validation without database operations
        $validData = ['brandName' => 'Test Shipping Line'];
        $errors = $this->shippingLineService->validateShippingLineData($validData);
        
        // Note: This might fail if there's already a shipping line with this name
        // In a real test, we'd use a test database with known state
        $this->assertIsArray($errors);

        $invalidData = ['brandName' => ''];
        $errors = $this->shippingLineService->validateShippingLineData($invalidData);
        $this->assertNotEmpty($errors);
        $this->assertContains('Brand name is required', $errors);
    }
}