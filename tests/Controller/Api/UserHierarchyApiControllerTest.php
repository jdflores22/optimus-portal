<?php

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Entity\StaffUser;
use App\Entity\ShippingLine;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\UserHierarchyService;
use App\Service\ActivityLogService;
use App\Service\ScopeAccessControlService;
use App\Service\ShippingLineService;
use App\Service\UserService;
use App\Service\JwtService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class UserHierarchyApiControllerTest extends WebTestCase
{
    private $client;
    private $entityManager;
    private $userHierarchyService;
    private $activityLogService;
    private $scopeAccessControlService;
    private $shippingLineService;
    private $userService;
    private $jwtService;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->userHierarchyService = $container->get(UserHierarchyService::class);
        $this->activityLogService = $container->get(ActivityLogService::class);
        $this->scopeAccessControlService = $container->get(ScopeAccessControlService::class);
        $this->shippingLineService = $container->get(ShippingLineService::class);
        $this->userService = $container->get(UserService::class);
        $this->jwtService = $container->get(JwtService::class);
    }

    public function testListUsersAsSystemAdmin(): void
    {
        // Create test data
        $systemAdmin = $this->createSystemAdmin();
        $shippingLine = $this->createShippingLine();
        $shippingLineAdmin = $this->createShippingLineAdmin($shippingLine);
        $staff = $this->createStaffUser($shippingLineAdmin);

        $token = $this->jwtService->generateToken($systemAdmin->getId());

        $this->client->request('GET', '/api/user-hierarchy/users', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ]);

        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('users', $responseData);
        $this->assertArrayHasKey('total', $responseData);
        $this->assertGreaterThan(0, $responseData['total']);
    }

    public function testListUsersAsShippingLineAdmin(): void
    {
        // Create test data
        $shippingLine = $this->createShippingLine();
        $shippingLineAdmin = $this->createShippingLineAdmin($shippingLine);
        $staff = $this->createStaffUser($shippingLineAdmin);

        $token = $this->jwtService->generateToken($shippingLineAdmin->getId());

        $this->client->request('GET', '/api/user-hierarchy/users', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ]);

        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('users', $responseData);
        
        // Should only see users in their scope
        foreach ($responseData['users'] as $user) {
            $this->assertTrue(
                $user['id'] === $shippingLineAdmin->getId() || 
                $user['id'] === $staff->getId()
            );
        }
    }

    public function testShowUserWithValidAccess(): void
    {
        $shippingLine = $this->createShippingLine();
        $shippingLineAdmin = $this->createShippingLineAdmin($shippingLine);
        $staff = $this->createStaffUser($shippingLineAdmin);

        $token = $this->jwtService->generateToken($shippingLineAdmin->getId());

        $this->client->request('GET', "/api/user-hierarchy/users/{$staff->getId()}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ]);

        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('user', $responseData);
        $this->assertEquals($staff->getId(), $responseData['user']['id']);
        $this->assertEquals($staff->getEmail(), $responseData['user']['email']);
    }

    public function testShowUserWithInvalidAccess(): void
    {
        $shippingLine1 = $this->createShippingLine('Line 1');
        $shippingLine2 = $this->createShippingLine('Line 2');
        $admin1 = $this->createShippingLineAdmin($shippingLine1);
        $admin2 = $this->createShippingLineAdmin($shippingLine2);

        $token = $this->jwtService->generateToken($admin1->getId());

        $this->client->request('GET', "/api/user-hierarchy/users/{$admin2->getId()}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ]);

        $this->assertEquals(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateUserAsSystemAdmin(): void
    {
        $systemAdmin = $this->createSystemAdmin();
        $shippingLine = $this->createShippingLine();
        $shippingLineAdmin = $this->createShippingLineAdmin($shippingLine);

        $token = $this->jwtService->generateToken($systemAdmin->getId());

        $userData = [
            'email' => 'newstaff@example.com',
            'password' => 'password123',
            'role' => UserRole::SL_STAFF->value,
            'shipping_line_admin_id' => $shippingLineAdmin->getId()
        ];

        $this->client->request('POST', '/api/user-hierarchy/users', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ], json_encode($userData));

        $this->assertEquals(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('user', $responseData);
        $this->assertEquals($userData['email'], $responseData['user']['email']);
        $this->assertEquals($userData['role'], $responseData['user']['role']);
    }

    public function testCreateUserAsShippingLineAdmin(): void
    {
        $shippingLine = $this->createShippingLine();
        $shippingLineAdmin = $this->createShippingLineAdmin($shippingLine);

        $token = $this->jwtService->generateToken($shippingLineAdmin->getId());

        $userData = [
            'email' => 'newstaff@example.com',
            'password' => 'password123',
            'role' => UserRole::SL_STAFF->value,
            'shipping_line_admin_id' => $shippingLineAdmin->getId()
        ];

        $this->client->request('POST', '/api/user-hierarchy/users', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ], json_encode($userData));

        $this->assertEquals(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateUserWithInvalidRole(): void
    {
        $shippingLine = $this->createShippingLine();
        $shippingLineAdmin = $this->createShippingLineAdmin($shippingLine);

        $token = $this->jwtService->generateToken($shippingLineAdmin->getId());

        $userData = [
            'email' => 'newadmin@example.com',
            'password' => 'password123',
            'role' => UserRole::SYSTEM_ADMIN->value // SHIPPING_LINES_ADMIN cannot create SYSTEM_ADMIN
        ];

        $this->client->request('POST', '/api/user-hierarchy/users', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ], json_encode($userData));

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('validation_failed', $responseData['error']);
    }

    public function testUpdateUser(): void
    {
        $shippingLine = $this->createShippingLine();
        $shippingLineAdmin = $this->createShippingLineAdmin($shippingLine);
        $staff = $this->createStaffUser($shippingLineAdmin);

        $token = $this->jwtService->generateToken($shippingLineAdmin->getId());

        $updateData = [
            'email' => 'updated@example.com',
            'status' => AccountStatus::APPROVED->value
        ];

        $this->client->request('PUT', "/api/user-hierarchy/users/{$staff->getId()}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ], json_encode($updateData));

        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals($updateData['email'], $responseData['user']['email']);
        $this->assertEquals($updateData['status'], $responseData['user']['status']);
    }

    public function testDeleteUser(): void
    {
        $shippingLine = $this->createShippingLine();
        $shippingLineAdmin = $this->createShippingLineAdmin($shippingLine);
        $staff = $this->createStaffUser($shippingLineAdmin);

        $token = $this->jwtService->generateToken($shippingLineAdmin->getId());

        $this->client->request('DELETE', "/api/user-hierarchy/users/{$staff->getId()}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ]);

        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        // Verify user is suspended, not deleted
        $this->entityManager->refresh($staff);
        $this->assertEquals(AccountStatus::SUSPENDED, $staff->getStatus());
    }

    public function testLinkUserHierarchy(): void
    {
        $systemAdmin = $this->createSystemAdmin();
        $shippingLine = $this->createShippingLine();
        $shippingLineAdmin = $this->createShippingLineAdmin($shippingLine);
        $staff = $this->createStaffUser(); // Create without admin link

        $token = $this->jwtService->generateToken($systemAdmin->getId());

        $linkData = [
            'admin_id' => $shippingLineAdmin->getId()
        ];

        $this->client->request('POST', "/api/user-hierarchy/users/{$staff->getId()}/hierarchy", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ], json_encode($linkData));

        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        // Verify the link was created
        $this->entityManager->refresh($staff);
        $this->assertEquals($shippingLineAdmin->getId(), $staff->getShippingLineAdmin()->getId());
    }

    public function testUnlinkUserHierarchy(): void
    {
        $systemAdmin = $this->createSystemAdmin();
        $shippingLine = $this->createShippingLine();
        $shippingLineAdmin = $this->createShippingLineAdmin($shippingLine);
        $staff = $this->createStaffUser($shippingLineAdmin);

        $token = $this->jwtService->generateToken($systemAdmin->getId());

        $this->client->request('DELETE', "/api/user-hierarchy/users/{$staff->getId()}/hierarchy", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ]);

        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        // Verify the link was removed
        $this->entityManager->refresh($staff);
        $this->assertNull($staff->getShippingLineAdmin());
    }

    public function testGetHierarchyTree(): void
    {
        $shippingLine = $this->createShippingLine();
        $shippingLineAdmin = $this->createShippingLineAdmin($shippingLine);
        $staff1 = $this->createStaffUser($shippingLineAdmin);
        $staff2 = $this->createStaffUser($shippingLineAdmin);

        $token = $this->jwtService->generateToken($shippingLineAdmin->getId());

        $this->client->request('GET', "/api/user-hierarchy/hierarchy-tree/{$shippingLineAdmin->getId()}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ]);

        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('hierarchy_tree', $responseData);
    }

    public function testGetSubordinates(): void
    {
        $shippingLine = $this->createShippingLine();
        $shippingLineAdmin = $this->createShippingLineAdmin($shippingLine);
        $staff1 = $this->createStaffUser($shippingLineAdmin);
        $staff2 = $this->createStaffUser($shippingLineAdmin);

        $token = $this->jwtService->generateToken($shippingLineAdmin->getId());

        $this->client->request('GET', "/api/user-hierarchy/subordinates/{$shippingLineAdmin->getId()}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ]);

        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('subordinates', $responseData);
        $this->assertEquals(2, $responseData['total']);
    }

    public function testTransferUsers(): void
    {
        $systemAdmin = $this->createSystemAdmin();
        $shippingLine = $this->createShippingLine();
        $fromAdmin = $this->createShippingLineAdmin($shippingLine, 'from@example.com');
        $toAdmin = $this->createShippingLineAdmin($shippingLine, 'to@example.com');
        $staff = $this->createStaffUser($fromAdmin);

        $token = $this->jwtService->generateToken($systemAdmin->getId());

        $transferData = [
            'from_admin_id' => $fromAdmin->getId(),
            'to_admin_id' => $toAdmin->getId(),
            'user_ids' => [$staff->getId()]
        ];

        $this->client->request('POST', '/api/user-hierarchy/transfer-users', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ], json_encode($transferData));

        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    public function testGetHierarchyStatistics(): void
    {
        $systemAdmin = $this->createSystemAdmin();
        $token = $this->jwtService->generateToken($systemAdmin->getId());

        $this->client->request('GET', '/api/user-hierarchy/statistics', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ]);

        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('statistics', $responseData);
    }

    public function testValidateIntegrity(): void
    {
        $systemAdmin = $this->createSystemAdmin();
        $token = $this->jwtService->generateToken($systemAdmin->getId());

        $this->client->request('GET', '/api/user-hierarchy/validate-integrity', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ]);

        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('integrity_issues', $responseData);
        $this->assertArrayHasKey('is_valid', $responseData);
    }

    public function testUnauthorizedAccess(): void
    {
        $this->client->request('GET', '/api/user-hierarchy/users');
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    // Helper methods for creating test data

    private function createSystemAdmin(): User
    {
        $user = new StaffUser();
        $user->setEmail('admin@system.com');
        $user->setRole(UserRole::SYSTEM_ADMIN);
        $user->setPasswordHash(password_hash('password', PASSWORD_DEFAULT));
        $user->setStatus(AccountStatus::APPROVED);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createShippingLine(string $brandName = 'Test Shipping Line'): ShippingLine
    {
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName($brandName);
        $shippingLine->setPortalConfig(['theme' => 'default']);
        $shippingLine->setIsActive(true);

        $this->entityManager->persist($shippingLine);
        $this->entityManager->flush();

        return $shippingLine;
    }

    private function createShippingLineAdmin(ShippingLine $shippingLine, string $email = 'admin@shipping.com'): User
    {
        $user = new StaffUser();
        $user->setEmail($email);
        $user->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $user->setPasswordHash(password_hash('password', PASSWORD_DEFAULT));
        $user->setStatus(AccountStatus::APPROVED);
        $user->setManagedShippingLine($shippingLine);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createStaffUser(?User $admin = null, string $email = 'staff@shipping.com'): User
    {
        static $counter = 0;
        $counter++;
        
        $user = new StaffUser();
        $user->setEmail($counter > 1 ? "staff{$counter}@shipping.com" : $email);
        $user->setRole(UserRole::SL_STAFF);
        $user->setPasswordHash(password_hash('password', PASSWORD_DEFAULT));
        $user->setStatus(AccountStatus::APPROVED);
        
        if ($admin) {
            $user->setShippingLineAdmin($admin);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}