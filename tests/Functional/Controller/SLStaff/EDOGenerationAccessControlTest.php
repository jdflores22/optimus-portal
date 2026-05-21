<?php

namespace App\Tests\Functional\Controller\SLStaff;

use App\Entity\StaffUser;
use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\Manifest;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\WorkflowState;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test Task 12: Role-Based Access Control for eDO Generation
 * 
 * Verifies that:
 * - Only SL_STAFF can access eDO generation endpoints
 * - Non-SL_STAFF users receive HTTP 403
 * - Unauthorized access attempts are logged
 */
class EDOGenerationAccessControlTest extends WebTestCase
{
    private $client;
    private $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
    }

    /**
     * Test that SL_STAFF can access the generate eDOs endpoint
     */
    public function testSLStaffCanAccessGenerateEDOsEndpoint(): void
    {
        // Create SL_STAFF user
        $slStaff = $this->createStaffUser('slstaff@test.com', UserRole::SL_STAFF);
        
        // Login as SL_STAFF
        $this->client->loginUser($slStaff);
        
        // Create a test manifest
        $manifest = $this->createTestManifest();
        
        // Attempt to access generate eDOs endpoint
        $this->client->request(
            'POST',
            '/sl-staff/edo-generation/generate/' . $manifest->getId(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['expirationDate' => (new \DateTime('+7 days'))->format('Y-m-d')])
        );
        
        // Should not return 403 (may return 400 or 500 due to test data, but not 403)
        $this->assertNotEquals(
            Response::HTTP_FORBIDDEN,
            $this->client->getResponse()->getStatusCode(),
            'SL_STAFF should be able to access the generate eDOs endpoint'
        );
    }

    /**
     * Test that BROKER cannot access the generate eDOs endpoint
     */
    public function testBrokerCannotAccessGenerateEDOsEndpoint(): void
    {
        // Create BROKER user
        $broker = $this->createBrokerUser('broker@test.com');
        
        // Login as BROKER
        $this->client->loginUser($broker);
        
        // Create a test manifest
        $manifest = $this->createTestManifest();
        
        // Attempt to access generate eDOs endpoint
        $this->client->request(
            'POST',
            '/sl-staff/edo-generation/generate/' . $manifest->getId(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['expirationDate' => (new \DateTime('+7 days'))->format('Y-m-d')])
        );
        
        // Should return 403 Forbidden
        $this->assertEquals(
            Response::HTTP_FORBIDDEN,
            $this->client->getResponse()->getStatusCode(),
            'BROKER should not be able to access the generate eDOs endpoint'
        );
    }

    /**
     * Test that CONSIGNEE cannot access the generate eDOs endpoint
     */
    public function testConsigneeCannotAccessGenerateEDOsEndpoint(): void
    {
        // Create CONSIGNEE user
        $consignee = $this->createConsigneeUser('consignee@test.com');
        
        // Login as CONSIGNEE
        $this->client->loginUser($consignee);
        
        // Create a test manifest
        $manifest = $this->createTestManifest();
        
        // Attempt to access generate eDOs endpoint
        $this->client->request(
            'POST',
            '/sl-staff/edo-generation/generate/' . $manifest->getId(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['expirationDate' => (new \DateTime('+7 days'))->format('Y-m-d')])
        );
        
        // Should return 403 Forbidden
        $this->assertEquals(
            Response::HTTP_FORBIDDEN,
            $this->client->getResponse()->getStatusCode(),
            'CONSIGNEE should not be able to access the generate eDOs endpoint'
        );
    }

    /**
     * Test that ACCOUNTING cannot access the generate eDOs endpoint
     */
    public function testAccountingCannotAccessGenerateEDOsEndpoint(): void
    {
        // Create ACCOUNTING user
        $accounting = $this->createStaffUser('accounting@test.com', UserRole::ACCOUNTING);
        
        // Login as ACCOUNTING
        $this->client->loginUser($accounting);
        
        // Create a test manifest
        $manifest = $this->createTestManifest();
        
        // Attempt to access generate eDOs endpoint
        $this->client->request(
            'POST',
            '/sl-staff/edo-generation/generate/' . $manifest->getId(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['expirationDate' => (new \DateTime('+7 days'))->format('Y-m-d')])
        );
        
        // Should return 403 Forbidden
        $this->assertEquals(
            Response::HTTP_FORBIDDEN,
            $this->client->getResponse()->getStatusCode(),
            'ACCOUNTING should not be able to access the generate eDOs endpoint'
        );
    }

    /**
     * Test that SL_STAFF can access the manifest details endpoint
     */
    public function testSLStaffCanAccessManifestDetailsEndpoint(): void
    {
        // Create SL_STAFF user
        $slStaff = $this->createStaffUser('slstaff2@test.com', UserRole::SL_STAFF);
        
        // Login as SL_STAFF
        $this->client->loginUser($slStaff);
        
        // Create a test manifest
        $manifest = $this->createTestManifest();
        
        // Attempt to access manifest details endpoint
        $this->client->request(
            'GET',
            '/sl-staff/edo-generation/manifest/' . $manifest->getId()
        );
        
        // Should not return 403
        $this->assertNotEquals(
            Response::HTTP_FORBIDDEN,
            $this->client->getResponse()->getStatusCode(),
            'SL_STAFF should be able to access the manifest details endpoint'
        );
    }

    /**
     * Test that BROKER cannot access the manifest details endpoint
     */
    public function testBrokerCannotAccessManifestDetailsEndpoint(): void
    {
        // Create BROKER user
        $broker = $this->createBrokerUser('broker2@test.com');
        
        // Login as BROKER
        $this->client->loginUser($broker);
        
        // Create a test manifest
        $manifest = $this->createTestManifest();
        
        // Attempt to access manifest details endpoint
        $this->client->request(
            'GET',
            '/sl-staff/edo-generation/manifest/' . $manifest->getId()
        );
        
        // Should return 403 Forbidden
        $this->assertEquals(
            Response::HTTP_FORBIDDEN,
            $this->client->getResponse()->getStatusCode(),
            'BROKER should not be able to access the manifest details endpoint'
        );
    }

    /**
     * Test that unauthorized access attempts are logged
     */
    public function testUnauthorizedAccessAttemptsAreLogged(): void
    {
        // Create BROKER user
        $broker = $this->createBrokerUser('broker3@test.com');
        
        // Login as BROKER
        $this->client->loginUser($broker);
        
        // Create a test manifest
        $manifest = $this->createTestManifest();
        
        // Enable profiler to check logs
        $this->client->enableProfiler();
        
        // Attempt to access generate eDOs endpoint
        $this->client->request(
            'POST',
            '/sl-staff/edo-generation/generate/' . $manifest->getId(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['expirationDate' => (new \DateTime('+7 days'))->format('Y-m-d')])
        );
        
        // Verify 403 response
        $this->assertEquals(
            Response::HTTP_FORBIDDEN,
            $this->client->getResponse()->getStatusCode()
        );
        
        // Check that the exception was logged
        $profile = $this->client->getProfile();
        if ($profile) {
            $logger = $profile->getCollector('logger');
            $logs = $logger->getLogs();
            
            // Look for access denied log entries
            $accessDeniedLogged = false;
            foreach ($logs as $log) {
                if (isset($log['message']) && 
                    (str_contains($log['message'], 'Access Denied') || 
                     str_contains($log['message'], 'access denied') ||
                     str_contains($log['message'], 'Client error occurred'))) {
                    $accessDeniedLogged = true;
                    break;
                }
            }
            
            $this->assertTrue(
                $accessDeniedLogged,
                'Unauthorized access attempt should be logged'
            );
        }
    }

    /**
     * Helper method to create a staff user
     */
    private function createStaffUser(string $email, UserRole $role): StaffUser
    {
        $user = new StaffUser();
        $user->setEmail($email);
        $user->setPasswordHash('$2y$13$test'); // Dummy password hash
        $user->setRole($role);
        $user->setIsActive(true);
        $user->setFirstName('Test');
        $user->setLastName('User');
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        return $user;
    }

    /**
     * Helper method to create a broker user
     */
    private function createBrokerUser(string $email): Broker
    {
        $user = new Broker();
        $user->setEmail($email);
        $user->setPasswordHash('$2y$13$test'); // Dummy password hash
        $user->setRole(UserRole::BROKER);
        $user->setIsActive(true);
        $user->setFullName('Test Broker');
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        return $user;
    }

    /**
     * Helper method to create a consignee user
     */
    private function createConsigneeUser(string $email): Consignee
    {
        $user = new Consignee();
        $user->setEmail($email);
        $user->setPasswordHash('$2y$13$test'); // Dummy password hash
        $user->setRole(UserRole::CONSIGNEE);
        $user->setIsActive(true);
        $user->setFullName('Test Consignee');
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        return $user;
    }

    /**
     * Helper method to create a test manifest
     */
    private function createTestManifest(): Manifest
    {
        $manifest = new Manifest();
        $manifest->setManifestNumber('TEST-MANIFEST-' . uniqid());
        $manifest->setBlNumber('TEST-BL-' . uniqid());
        $manifest->setWorkflowState(WorkflowState::PAYMENT_VERIFIED);
        
        $this->entityManager->persist($manifest);
        $this->entityManager->flush();
        
        return $manifest;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
        $this->entityManager = null;
    }
}
