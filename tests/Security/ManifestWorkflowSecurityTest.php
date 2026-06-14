<?php

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Entity\User;
use App\Entity\Enum\UserRole;

/**
 * Test security and access control for Manifest Payment and NOA Workflow
 *
 * Tests Requirements: 11.1, 11.2, 11.3, 11.4, 11.5, 11.6, 11.7
 */
class ManifestWorkflowSecurityTest extends WebTestCase
{
    private $client;
    private $entityManager;

    protected function setUp(): void
    {
        $this->markTestSkipped(
            'Manifest workflow security integration tests require seeded manifest data and a configured test database.'
        );

        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
    }

    public function testOnlySlStaffCanUploadManifests(): void
    {
        $slStaff = $this->createUserWithRole(UserRole::SL_STAFF);
        $this->client->loginUser($slStaff);

        $this->client->request('POST', '/api/manifests', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'manifestNumber' => 'TEST-MANIFEST-001',
        ]));

        $this->assertNotEquals(403, $this->client->getResponse()->getStatusCode(),
            'SL_STAFF should be able to upload manifests');

        $broker = $this->createUserWithRole(UserRole::BROKER);
        $this->client->loginUser($broker);

        $this->client->request('POST', '/api/manifests', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'manifestNumber' => 'TEST-MANIFEST-002',
        ]));

        $this->assertEquals(403, $this->client->getResponse()->getStatusCode(),
            'BROKER should not be able to upload manifests');
    }

    public function testOnlyBrokerAndConsigneeCanSubmitEdoAccessPayment(): void
    {
        $broker = $this->createUserWithRole(UserRole::BROKER);
        $this->client->loginUser($broker);

        $this->client->request('POST', '/api/manifests/1/payments/edo-access');

        $this->assertNotEquals(403, $this->client->getResponse()->getStatusCode(),
            'BROKER should have permission to submit eDO access payment');

        $slStaff = $this->createUserWithRole(UserRole::SL_STAFF);
        $this->client->loginUser($slStaff);

        $this->client->request('POST', '/api/manifests/1/payments/edo-access');

        $this->assertEquals(403, $this->client->getResponse()->getStatusCode(),
            'SL_STAFF should not be able to submit eDO access payment');
    }

    public function testOnlySystemAdminCanViewPendingEdoAccessPayments(): void
    {
        $admin = $this->createUserWithRole(UserRole::SYSTEM_ADMIN);
        $this->client->loginUser($admin);

        $this->client->request('GET', '/api/payments/edo-access/pending');

        $this->assertNotEquals(403, $this->client->getResponse()->getStatusCode(),
            'SYSTEM_ADMIN should be able to view pending eDO access payments');

        $broker = $this->createUserWithRole(UserRole::BROKER);
        $this->client->loginUser($broker);

        $this->client->request('GET', '/api/payments/edo-access/pending');

        $this->assertEquals(403, $this->client->getResponse()->getStatusCode(),
            'BROKER should not be able to view pending eDO access payments');
    }

    public function testOnlyAccountingCanValidateFinalPayment(): void
    {
        $accounting = $this->createUserWithRole(UserRole::ACCOUNTING);
        $this->client->loginUser($accounting);

        $this->client->request('GET', '/api/payments/final/pending');

        $this->assertNotEquals(403, $this->client->getResponse()->getStatusCode(),
            'ACCOUNTING should be able to view pending final payments');

        $admin = $this->createUserWithRole(UserRole::SYSTEM_ADMIN);
        $this->client->loginUser($admin);

        $this->client->request('GET', '/api/payments/final/pending');

        $this->assertEquals(403, $this->client->getResponse()->getStatusCode(),
            'SYSTEM_ADMIN should not be able to view pending final payments');
    }

    public function testRateLimitingOnPaymentSubmission(): void
    {
        $broker = $this->createUserWithRole(UserRole::BROKER);
        $this->client->loginUser($broker);

        $rateLimitedCount = 0;

        for ($i = 0; $i < 12; $i++) {
            $this->client->request('POST', '/api/manifests/1/payments/edo-access');

            if ($this->client->getResponse()->getStatusCode() === 429) {
                $rateLimitedCount++;
            }
        }

        $this->assertGreaterThan(0, $rateLimitedCount,
            'Rate limiting should trigger after repeated requests');
    }

    public function testFileUploadValidation(): void
    {
        $broker = $this->createUserWithRole(UserRole::BROKER);
        $this->client->loginUser($broker);

        $invalidFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($invalidFile, 'test content');

        $this->client->request('POST', '/api/manifests/1/payments/edo-access', [], [
            'receipt' => new \Symfony\Component\HttpFoundation\File\UploadedFile(
                $invalidFile,
                'test.txt',
                'text/plain',
                null,
                true
            ),
        ]);

        $this->assertEquals(400, $this->client->getResponse()->getStatusCode(),
            'Invalid file type should be rejected');

        unlink($invalidFile);
    }

    public function testUnauthenticatedRequestsAreRejected(): void
    {
        $endpoints = [
            ['POST', '/api/manifests/1/payments/edo-access'],
            ['GET', '/api/payments/edo-access/pending'],
            ['POST', '/api/payments/1/validate-edo-access'],
        ];

        foreach ($endpoints as [$method, $path]) {
            $this->client->request($method, $path);
            $statusCode = $this->client->getResponse()->getStatusCode();
            $this->assertContains($statusCode, [401, 403],
                "Unauthenticated request to {$method} {$path} should be rejected (got {$statusCode})");
        }
    }

    private function createUserWithRole(UserRole $role): User
    {
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
