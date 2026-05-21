<?php

namespace App\Tests\Controller\Api;

use App\Entity\ShippingLine;
use App\Entity\StaffUser;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\JwtService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class ShippingLineApiControllerTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $entityManager;
    private JwtService $jwtService;
    private StaffUser $systemAdmin;
    private string $authToken;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->jwtService = static::getContainer()->get(JwtService::class);
        
        // Create a system admin user for testing
        $this->systemAdmin = $this->createSystemAdminUser();
        $this->authToken = $this->jwtService->generateToken($this->systemAdmin);
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $this->cleanupTestData();
        parent::tearDown();
    }

    public function testListShippingLinesRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/shipping-lines');
        
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Authentication required', $responseData['error']);
    }

    public function testListShippingLinesRequiresSystemAdminRole(): void
    {
        // Create a non-admin user
        $regularUser = $this->createUser('regular@test.com', UserRole::SL_STAFF);
        $regularToken = $this->jwtService->generateToken($regularUser);
        
        $client = static::createClient();
        $client->request('GET', '/api/shipping-lines', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $regularToken
        ]);
        
        $this->assertEquals(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        
        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('Insufficient permissions', $responseData['error']);
    }

    public function testListShippingLinesSuccess(): void
    {
        // Create test shipping lines
        $shippingLine1 = $this->createShippingLine('Test Line 1');
        $shippingLine2 = $this->createShippingLine('Test Line 2');
        
        $this->client->request('GET', '/api/shipping-lines', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken
        ]);
        
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('shipping_lines', $responseData);
        $this->assertArrayHasKey('total', $responseData);
        $this->assertGreaterThanOrEqual(2, $responseData['total']);
    }

    public function testCreateShippingLineSuccess(): void
    {
        $data = [
            'brand_name' => 'New Test Shipping Line',
            'portal_config' => [
                'theme' => 'blue',
                'logo_url' => 'https://example.com/logo.png'
            ]
        ];
        
        $this->client->request('POST', '/api/shipping-lines', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
            'CONTENT_TYPE' => 'application/json'
        ], json_encode($data));
        
        $this->assertEquals(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('shipping_line', $responseData);
        $this->assertEquals($data['brand_name'], $responseData['shipping_line']['brand_name']);
        $this->assertEquals($data['portal_config'], $responseData['shipping_line']['portal_config']);
    }

    public function testCreateShippingLineValidationErrors(): void
    {
        $data = [
            'brand_name' => '', // Empty brand name should fail
            'portal_config' => 'invalid' // Should be array
        ];
        
        $this->client->request('POST', '/api/shipping-lines', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
            'CONTENT_TYPE' => 'application/json'
        ], json_encode($data));
        
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('validation_failed', $responseData['error']);
        $this->assertArrayHasKey('details', $responseData);
    }

    public function testCreateShippingLineDuplicateBrandName(): void
    {
        // Create existing shipping line
        $existingShippingLine = $this->createShippingLine('Duplicate Test Line');
        
        $data = [
            'brand_name' => 'Duplicate Test Line', // Same name should fail
            'portal_config' => []
        ];
        
        $this->client->request('POST', '/api/shipping-lines', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
            'CONTENT_TYPE' => 'application/json'
        ], json_encode($data));
        
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('validation_failed', $responseData['error']);
        $this->assertArrayHasKey('brand_name', $responseData['details']);
    }

    public function testShowShippingLineSuccess(): void
    {
        $shippingLine = $this->createShippingLine('Show Test Line');
        
        $this->client->request('GET', '/api/shipping-lines/' . $shippingLine->getId(), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken
        ]);
        
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('shipping_line', $responseData);
        $this->assertEquals($shippingLine->getId(), $responseData['shipping_line']['id']);
        $this->assertEquals($shippingLine->getBrandName(), $responseData['shipping_line']['brand_name']);
    }

    public function testShowShippingLineNotFound(): void
    {
        $this->client->request('GET', '/api/shipping-lines/99999', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken
        ]);
        
        $this->assertEquals(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Shipping line not found', $responseData['error']);
    }

    public function testUpdateShippingLineSuccess(): void
    {
        $shippingLine = $this->createShippingLine('Update Test Line');
        
        $updateData = [
            'brand_name' => 'Updated Test Line',
            'portal_config' => [
                'theme' => 'green',
                'updated' => true
            ]
        ];
        
        $this->client->request('PUT', '/api/shipping-lines/' . $shippingLine->getId(), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
            'CONTENT_TYPE' => 'application/json'
        ], json_encode($updateData));
        
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals($updateData['brand_name'], $responseData['shipping_line']['brand_name']);
        $this->assertEquals($updateData['portal_config'], $responseData['shipping_line']['portal_config']);
    }

    public function testDeleteShippingLineSuccess(): void
    {
        $shippingLine = $this->createShippingLine('Delete Test Line');
        
        $this->client->request('DELETE', '/api/shipping-lines/' . $shippingLine->getId(), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken
        ]);
        
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Shipping line deactivated successfully', $responseData['message']);
    }

    public function testStatisticsEndpoint(): void
    {
        $shippingLine = $this->createShippingLine('Statistics Test Line');
        
        $this->client->request('GET', '/api/shipping-lines/' . $shippingLine->getId() . '/statistics', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken
        ]);
        
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('statistics', $responseData);
        $this->assertArrayHasKey('total_admins', $responseData['statistics']);
        $this->assertArrayHasKey('active_admins', $responseData['statistics']);
        $this->assertArrayHasKey('total_users', $responseData['statistics']);
    }

    private function createSystemAdminUser(): StaffUser
    {
        $user = new StaffUser();
        $user->setEmail('admin@test.com');
        $user->setPasswordHash('hashed_password');
        $user->setRole(UserRole::SYSTEM_ADMIN);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setFirstName('System');
        $user->setLastName('Admin');
        $user->setDepartment('IT');
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        return $user;
    }

    private function createUser(string $email, UserRole $role): StaffUser
    {
        $user = new StaffUser();
        $user->setEmail($email);
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
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName($brandName);
        $shippingLine->setPortalConfig(['test' => true]);
        
        $this->entityManager->persist($shippingLine);
        $this->entityManager->flush();
        
        return $shippingLine;
    }

    private function cleanupTestData(): void
    {
        // Clean up shipping lines
        $shippingLines = $this->entityManager->getRepository(ShippingLine::class)->findAll();
        foreach ($shippingLines as $shippingLine) {
            $this->entityManager->remove($shippingLine);
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