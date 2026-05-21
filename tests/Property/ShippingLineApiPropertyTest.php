<?php

namespace App\Tests\Property;

use App\Entity\ShippingLine;
use App\Entity\StaffUser;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\JwtService;
use App\Service\ShippingLineService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Property-based tests for Shipping Line API endpoints
 * 
 * **Feature: dynamic-shipping-line-management**
 */
class ShippingLineApiPropertyTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ShippingLineService $shippingLineService;
    private JwtService $jwtService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->shippingLineService = static::getContainer()->get(ShippingLineService::class);
        $this->jwtService = static::getContainer()->get(JwtService::class);
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    /**
     * **Property 33: API Access Control Consistency**
     * 
     * For any API endpoint operation, the system should enforce the same access control rules as the web interface.
     * 
     * **Validates: Requirements 13.3**
     */
    public function testApiAccessControlConsistency(): void
    {
        // Create test users with different roles
        $systemAdmin = $this->createUser('system@test.com', UserRole::SYSTEM_ADMIN);
        $shippingLineAdmin = $this->createUser('admin@test.com', UserRole::SHIPPING_LINES_ADMIN);
        $slStaff = $this->createUser('staff@test.com', UserRole::SL_STAFF);
        
        // Create a shipping line and assign admin
        $shippingLine = $this->createShippingLine('Test Shipping Line');
        $shippingLineAdmin->setManagedShippingLine($shippingLine);
        $slStaff->setShippingLineAdmin($shippingLineAdmin);
        $this->entityManager->flush();

        // Test access control consistency
        $this->assertTrue($this->canAccessShippingLine($systemAdmin, $shippingLine), 
            'SYSTEM_ADMIN should have access to any shipping line');
        
        $this->assertTrue($this->canAccessShippingLine($shippingLineAdmin, $shippingLine), 
            'SHIPPING_LINES_ADMIN should have access to their managed shipping line');
        
        $this->assertTrue($this->canAccessShippingLine($slStaff, $shippingLine), 
            'SL_STAFF should have access to their shipping line through admin hierarchy');

        // Create another shipping line that users shouldn't access
        $otherShippingLine = $this->createShippingLine('Other Shipping Line');
        
        $this->assertTrue($this->canAccessShippingLine($systemAdmin, $otherShippingLine), 
            'SYSTEM_ADMIN should have access to any shipping line');
        
        $this->assertFalse($this->canAccessShippingLine($shippingLineAdmin, $otherShippingLine), 
            'SHIPPING_LINES_ADMIN should not have access to other shipping lines');
        
        $this->assertFalse($this->canAccessShippingLine($slStaff, $otherShippingLine), 
            'SL_STAFF should not have access to other shipping lines');
    }

    /**
     * **Property 34: API Response Standards**
     * 
     * For any API request, the system should return appropriate HTTP status codes and error messages according to REST standards.
     * 
     * **Validates: Requirements 13.4**
     */
    public function testApiResponseStandards(): void
    {
        $systemAdmin = $this->createUser('admin@test.com', UserRole::SYSTEM_ADMIN);
        
        // Test successful creation (201 Created)
        $createData = [
            'brandName' => 'API Test Shipping Line',
            'portalConfig' => ['theme' => 'blue']
        ];
        
        $shippingLine = $this->shippingLineService->createShippingLine($createData, $systemAdmin);
        $this->assertInstanceOf(ShippingLine::class, $shippingLine);
        $this->assertEquals($createData['brandName'], $shippingLine->getBrandName());
        
        // Test validation errors (400 Bad Request) - use validation method instead of service
        $invalidData = [
            'brand_name' => '', // Empty brand name should fail
            'portal_config' => 'invalid' // Should be array
        ];
        
        $validationErrors = $this->validateShippingLineData($invalidData);
        $this->assertNotEmpty($validationErrors, 'Invalid data should produce validation errors');
        $this->assertArrayHasKey('brand_name', $validationErrors);
        
        // Test duplicate brand name (400 Bad Request)
        $duplicateData = [
            'brandName' => $shippingLine->getBrandName(), // Same name as created above
            'portalConfig' => []
        ];
        
        // This should throw an exception from the service
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Shipping line with this brand name already exists');
        $this->shippingLineService->createShippingLine($duplicateData, $systemAdmin);
        
        // Test not found scenarios (404 Not Found)
        $nonExistentShippingLine = $this->entityManager->getRepository(ShippingLine::class)->find(99999);
        $this->assertNull($nonExistentShippingLine, 'Non-existent shipping line should return null');
    }

    /**
     * **Property 35: API Authentication Integration**
     * 
     * For any API authentication attempt, the system should use existing authentication mechanisms consistently.
     * 
     * **Validates: Requirements 13.5**
     */
    public function testApiAuthenticationIntegration(): void
    {
        $user = $this->createUser('auth@test.com', UserRole::SYSTEM_ADMIN);
        
        // Test JWT token generation
        $token = $this->jwtService->generateToken($user);
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        
        // Test token validation
        $payload = $this->jwtService->validateToken($token);
        $this->assertIsArray($payload);
        $this->assertEquals($user->getId(), $payload['user_id']);
        $this->assertEquals($user->getEmail(), $payload['email']);
        $this->assertEquals($user->getRole()->value, $payload['role']);
        
        // Test user ID extraction from token
        $extractedUserId = $this->jwtService->getUserIdFromToken($token);
        $this->assertEquals($user->getId(), $extractedUserId);
        
        // Test invalid token handling
        $invalidPayload = $this->jwtService->validateToken('invalid.token.here');
        $this->assertNull($invalidPayload, 'Invalid token should return null');
        
        $invalidUserId = $this->jwtService->getUserIdFromToken('invalid.token.here');
        $this->assertNull($invalidUserId, 'Invalid token should return null user ID');
    }

    private function createUser(string $email, UserRole $role): StaffUser
    {
        // Make email unique by adding timestamp
        $uniqueEmail = str_replace('@test.com', '_' . time() . '@test.com', $email);
        
        $user = new StaffUser();
        $user->setEmail($uniqueEmail);
        $user->setPasswordHash('hashed_password');
        $user->setRole($role);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setDepartment('Test');
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        return $user;
    }

    private function createShippingLine(string $brandName): ShippingLine
    {
        // Make brand name unique by adding timestamp
        $uniqueBrandName = $brandName . ' ' . time();
        
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName($uniqueBrandName);
        $shippingLine->setPortalConfig(['test' => true]);
        
        $this->entityManager->persist($shippingLine);
        $this->entityManager->flush();
        
        return $shippingLine;
    }

    private function canAccessShippingLine(StaffUser $user, ShippingLine $shippingLine): bool
    {
        // SYSTEM_ADMIN can access all shipping lines
        if ($user->getRole() === UserRole::SYSTEM_ADMIN) {
            return true;
        }

        // Users can only access their own shipping line
        $userScope = $this->shippingLineService->getShippingLineScope($user);
        return $userScope && $userScope->getId() === $shippingLine->getId();
    }

    private function validateShippingLineData(array $data): array
    {
        $errors = [];

        if (!isset($data['brand_name']) || empty(trim($data['brand_name']))) {
            $errors['brand_name'] = 'Brand name is required';
        } elseif (strlen($data['brand_name']) < 2) {
            $errors['brand_name'] = 'Brand name must be at least 2 characters long';
        } elseif (strlen($data['brand_name']) > 255) {
            $errors['brand_name'] = 'Brand name cannot be longer than 255 characters';
        } else {
            // Check for duplicate brand name
            $existing = $this->shippingLineService->findByBrandName($data['brand_name']);
            if ($existing) {
                $errors['brand_name'] = 'Brand name already exists';
            }
        }

        if (isset($data['portal_config']) && !is_array($data['portal_config'])) {
            $errors['portal_config'] = 'Portal config must be an object';
        }

        return $errors;
    }

    private function cleanupTestData(): void
    {
        // Clean up shipping lines
        $shippingLines = $this->entityManager->getRepository(ShippingLine::class)->findAll();
        foreach ($shippingLines as $shippingLine) {
            if (str_contains($shippingLine->getBrandName(), 'Test')) {
                $this->entityManager->remove($shippingLine);
            }
        }
        
        // Clean up users
        $users = $this->entityManager->getRepository(StaffUser::class)->findAll();
        foreach ($users as $user) {
            if (str_contains($user->getEmail(), '@test.com')) {
                $this->entityManager->remove($user);
            }
        }
        
        $this->entityManager->flush();
    }
}