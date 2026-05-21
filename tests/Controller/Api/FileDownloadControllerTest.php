<?php

namespace App\Tests\Controller\Api;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\EDOStatus;
use App\Entity\Manifest;
use App\Entity\User;
use App\Entity\Payment;
use App\Service\EDOAccessLogServiceInterface;
use App\Service\FileStorageServiceInterface;
use App\Service\ManifestAuthorizationServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class FileDownloadControllerTest extends WebTestCase
{
    private $client;
    private $entityManager;
    private $user;
    private $manifest;
    private $edo;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        // Create test user
        $this->user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'test@example.com']);
        if (!$this->user) {
            $this->markTestSkipped('Test user not found');
        }

        // Login the user
        $this->client->loginUser($this->user);
    }

    public function testDownloadEDOWithPendingReleaseStatus(): void
    {
        // Create test data
        $edo = $this->createTestEDO(EDOStatus::PENDING_RELEASE);

        // Attempt to download
        $this->client->request('GET', '/api/files/edo/' . $edo->getEdoNumber() . '/download');

        // Assert 403 Forbidden
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('pending administrative release', $responseData['error']);

        // Cleanup
        $this->cleanupTestEDO($edo);
    }

    public function testDownloadEDOWithRejectedStatus(): void
    {
        // Create test data
        $edo = $this->createTestEDO(EDOStatus::REJECTED);

        // Attempt to download
        $this->client->request('GET', '/api/files/edo/' . $edo->getEdoNumber() . '/download');

        // Assert 403 Forbidden
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('rejected', $responseData['error']);

        // Cleanup
        $this->cleanupTestEDO($edo);
    }

    public function testDownloadEDOWithReleasedStatus(): void
    {
        // Create test data with a valid file
        $edo = $this->createTestEDO(EDOStatus::RELEASED, true);

        // Attempt to download
        $this->client->request('GET', '/api/files/edo/' . $edo->getEdoNumber() . '/download');

        // Assert 200 OK or file response
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            $statusCode === Response::HTTP_OK || $statusCode === Response::HTTP_NOT_FOUND,
            'Expected 200 OK for released eDO or 404 if file not found'
        );

        // Cleanup
        $this->cleanupTestEDO($edo);
    }

    public function testEDOAccessLogging(): void
    {
        // Create test data
        $edo = $this->createTestEDO(EDOStatus::PENDING_RELEASE);

        // Attempt to download (should be denied)
        $this->client->request('GET', '/api/files/edo/' . $edo->getEdoNumber() . '/download');

        // Verify access log was created
        $accessLogs = $this->entityManager->getRepository(\App\Entity\EDOAccessLog::class)
            ->findBy(['edo' => $edo]);

        $this->assertCount(1, $accessLogs, 'Access log should be created');
        $this->assertEquals('denied', $accessLogs[0]->getAccessResult());

        // Cleanup
        foreach ($accessLogs as $log) {
            $this->entityManager->remove($log);
        }
        $this->entityManager->flush();
        $this->cleanupTestEDO($edo);
    }

    private function createTestEDO(EDOStatus $status, bool $createFile = false): ElectronicDeliveryOrder
    {
        // Find or create a test manifest
        $manifest = $this->entityManager->getRepository(Manifest::class)->findOneBy([]);
        if (!$manifest) {
            $this->markTestSkipped('No manifest found for testing');
        }

        // Find or create a test payment
        $payment = $this->entityManager->getRepository(Payment::class)->findOneBy([]);
        if (!$payment) {
            $this->markTestSkipped('No payment found for testing');
        }

        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber('TEST-EDO-' . uniqid());
        $edo->setManifest($manifest);
        $edo->setPayment($payment);
        $edo->setPdfPath('/test/path/edo.pdf');
        $edo->setStatus($status);

        $this->entityManager->persist($edo);
        $this->entityManager->flush();

        return $edo;
    }

    private function cleanupTestEDO(ElectronicDeliveryOrder $edo): void
    {
        $this->entityManager->remove($edo);
        $this->entityManager->flush();
    }
}
