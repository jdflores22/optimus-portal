<?php

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Service\JwtService;

/**
 * Test security and access control for Manifest Payment and NOA Workflow
 * 
 * Tests Requirements: 11.1, 11.2, 11.3, 11.4, 11.5, 11.6, 11.7
 */
class ManifestWorkflowSecurityTest extends WebTestCase
{
    private $client;
    private $entityManager;
    private $jwtService;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->jwtService = $container->get(JwtService::class);
    }

    /**
     * Test that only SL_STAFF can upload manifests
     */
    public function testOnlySlStaffCanUploadManifests(): void
    {
        // Test with SL_STAFF - should succeed
        $slStaff = $this->createUserWithRole(UserRole::SL_STAFF);
        $token = $this->jwtService->generateToken($slStaff->getId());
        
        $this->client->request('POST', '/api/manifests', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'manifestNumber' => 'TEST-MANIFEST-001'
        ]));
        
        $this->assertNotEquals(403, $this->client->getResponse()->getStatusCode(), 
            'SL_STAFF should be able to upload manifests');

        // Test with BROKER - should fail
        $broker = $this->createUserWithRole(UserRole::BROKER);
        $brokerToken = $this->jwtService->generateToken($broker->getId());
        
        $this->client->request('POST', '/api/manifests', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $brokerToken,
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'manifestNumber' => 'TEST-MANIFEST-002'
        ]));
        
        $this->assertEquals(403, $this->client->getResponse()->getStatusCode(), 
            'BROKER should not be able to upload manifests');
    }

    /**
     * Test that only BROKER and CONSIGNEE can submit manifest access payments
     */
    public function testOnlyBrokerAndConsigneeCanSubmitManifestAccessPayment(): void
    {
        // Test with BROKER - should succeed (after manifest exists)
        $broker = $this->createUserWithRole(UserRole::BROKER);
        $brokerToken = $this->jwtService->generateToken($broker->getId());
        
        $this->client->request('POST', '/api/manifests/1/payments/manifest-access', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $brokerToken,
            'CONTENT_TYPE' => 'multipart/form-data'
        ]);
        
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertNotEquals(403, $statusCode, 
            'BROKER should have permission to submit manifest access payment');

        // Test with SL_STAFF - should fail
        $slStaff = $this->createUserWithRole(UserRole::SL_STAFF);
        $slStaffToken = $this->jwtService->generateToken($slStaff->getId());
        
        $this->client->request('POST', '/api/manifests/1/payments/manifest-access', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $slStaffToken,
            'CONTENT_TYPE' => 'multipart/form-data'
        ]);
        
        $this->assertEquals(403, $this->client->getResponse()->getStatusCode(), 
            'SL_STAFF should not be able to submit manifest access payment');
    }

    /**
     * Test that only SYSTEM_ADMIN can validate manifest access payments
     */
    public function testOnlySystemAdminCanValidateManifestAccessPayment(): void
    {
        // Test with SYSTEM_ADMIN - should succeed
        $admin = $this->createUserWithRole(UserRole::SYSTEM_ADMIN);
        $adminToken = $this->jwtService->generateToken($admin->getId());
        
        $this->client->request('GET', '/api/payments/manifest-access/pending', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken
        ]);
        
        $this->assertNotEquals(403, $this->client->getResponse()->getStatusCode(), 
            'SYSTEM_ADMIN should be able to view pending manifest access payments');

        // Test with BROKER - should fail
        $broker = $this->createUserWithRole(UserRole::BROKER);
        $brokerToken = $this->jwtService->generateToken($broker->getId());
        
        $this->client->request('GET', '/api/payments/manifest-access/pending', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $brokerToken
        ]);
        
        $this->assertEquals(403, $this->client->getResponse()->getStatusCode(), 
            'BROKER should not be able to view pending manifest access payments');
    }

    /**
     * Test that only ACCOUNTING can validate final payments
     */
    public function testOnlyAccountingCanValidateFinalPayment(): void
    {
        // Test with ACCOUNTING - should succeed
        $accounting = $this->createUserWithRole(UserRole::ACCOUNTING);
        $accountingToken = $this->jwtService->generateToken($accounting->getId());
        
        $this->client->request('GET', '/api/payments/final/pending', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $accountingToken
        ]);
        
        $this->assertNotEquals(403, $this->client->getResponse()->getStatusCode(), 
            'ACCOUNTING should be able to view pending final payments');

        // Test with SYSTEM_ADMIN - should fail
        $admin = $this->createUserWithRole(UserRole::SYSTEM_ADMIN);
        $adminToken = $this->jwtService->generateToken($admin->getId());
        
        $this->client->request('GET', '/api/payments/final/pending', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken
        ]);
        
        $this->assertEquals(403, $this->client->getResponse()->getStatusCode(), 
            'SYSTEM_ADMIN should not be able to view pending final payments');
    }

    /**
     * Test rate limiting on payment submission endpoints
     */
    public function testRateLimitingOnPaymentSubmission(): void
    {
        $broker = $this->createUserWithRole(UserRole::BROKER);
        $brokerToken = $this->jwtService->generateToken($broker->getId());
        
        // Make multiple requests to trigger rate limit (10 per hour)
        $successCount = 0;
        $rateLimitedCount = 0;
        
        for ($i = 0; $i < 12; $i++) {
            $this->client->request('POST', '/api/manifests/1/payments/manifest-access', [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $brokerToken,
                'CONTENT_TYPE' => 'multipart/form-data'
            ]);
            
            $statusCode = $this->client->getResponse()->getStatusCode();
            
            if ($statusCode === 429) {
                $rateLimitedCount++;
            } elseif ($statusCode !== 403) {
                $successCount++;
            }
        }
        
        $this->assertGreaterThan(0, $rateLimitedCount, 
            'Rate limiting should trigger after 10 requests');
    }

    /**
     * Test file upload validation for payment receipts
     */
    public function testFileUploadValidation(): void
    {
        $broker = $this->createUserWithRole(UserRole::BROKER);
        $brokerToken = $this->jwtService->generateToken($broker->getId());
        
        // Test with invalid file type (should fail)
        $invalidFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($invalidFile, 'test content');
        
        $this->client->request('POST', '/api/manifests/1/payments/manifest-access', [], [
            'receipt' => new \Symfony\Component\HttpFoundation\File\UploadedFile(
                $invalidFile,
                'test.txt',
                'text/plain',
                null,
                true
            )
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $brokerToken
        ], null);
        
        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode(), 
            'Invalid file type should be rejected');
        
        unlink($invalidFile);
    }

    /**
     * Test that unauthenticated requests are rejected
     */
    public function testUnauthenticatedRequestsAreRejected(): void
    {
        $endpoints = [
            ['POST', '/api/manifests'],
            ['GET', '/api/manifests/1'],
            ['POST', '/api/manifests/1/payments/manifest-access'],
            ['GET', '/api/payments/manifest-access/pending'],
            ['POST', '/api/payments/1/validate'],
        ];
        
        foreach ($endpoints as [$method, $path]) {
            $this->client->request($method, $path);
            $this->assertEquals(401, $this->client->getResponse()->getStatusCode(), 
                "Unauthenticated request to {$method} {$path} should be rejected");
        }
    }

    /**
     * Helper method to create a user with a specific role
     */
    private function createUserWithRole(UserRole $role): User
    {
        // Create appropriate concrete user type based on role
        switch ($role) {
            case UserRole::BROKER:
                $user = new \App\Entity\Broker();
                $user->setFullName('Test Broker User');
                break;
            case UserRole::CONSIGNEE:
                $user = new \App\Entity\Consignee();
                $user->setBusinessName('Test Consignee Business');
                break;
            case UserRole::SL_STAFF:
            case UserRole::SYSTEM_ADMIN:
            case UserRole::ACCOUNTING:
                $user = new \App\Entity\StaffUser();
                $user->setFirstName('Test');
                $user->setLastName('User');
                $user->setDepartment('Test Department');
                break;
            default:
                $user = new \App\Entity\StaffUser();
                $user->setFirstName('Test');
                $user->setLastName('User');
                $user->setDepartment('Test Department');
        }
        
        $user->setEmail('test_' . uniqid() . '@example.com');
        $user->setPasswordHash(password_hash('test_password', PASSWORD_BCRYPT));
        $user->setRole($role);
        $user->setStatus(\App\Entity\Enum\AccountStatus::APPROVED);
        $user->setEmailVerifiedAt(new \DateTime());
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        return $user;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
        $this->entityManager = null;
    }
}
