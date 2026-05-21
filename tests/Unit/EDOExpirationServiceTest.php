<?php

namespace App\Tests\Unit;

use App\Entity\Container;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\EDOStatus;
use App\Entity\Manifest;
use App\Repository\ElectronicDeliveryOrderRepository;
use App\Service\EDOExpirationService;
use App\Service\EDONotificationServiceInterface;
use App\Utility\ExpirationCalculator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for EDO Expiration Service
 * 
 * Tests Requirements: 4.1, 4.2, 4.3, 4.4, 4.5
 */
class EDOExpirationServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private ElectronicDeliveryOrderRepository $edoRepository;
    private ExpirationCalculator $expirationCalculator;
    private EDONotificationServiceInterface $notificationService;
    private LoggerInterface $logger;
    private EDOExpirationService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->edoRepository = $this->createMock(ElectronicDeliveryOrderRepository::class);
        $this->expirationCalculator = new ExpirationCalculator();
        $this->notificationService = $this->createMock(EDONotificationServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new EDOExpirationService(
            $this->entityManager,
            $this->edoRepository,
            $this->expirationCalculator,
            $this->notificationService,
            $this->logger
        );
    }

    /**
     * Test that checkExpiration returns true for expired eDO
     * Validates: Requirement 4.1, 4.2
     */
    public function testCheckExpirationReturnsTrueForExpiredEDO(): void
    {
        $edo = $this->createMockEDO();
        
        // Set expiration date to yesterday
        $yesterday = new \DateTime('yesterday', new \DateTimeZone('UTC'));
        $edo->setExpiresAt($yesterday);

        $result = $this->service->checkExpiration($edo);

        $this->assertTrue($result, 'eDO with past expiration date should be detected as expired');
    }

    /**
     * Test that checkExpiration returns false for non-expired eDO
     * Validates: Requirement 4.1, 4.5
     */
    public function testCheckExpirationReturnsFalseForNonExpiredEDO(): void
    {
        $edo = $this->createMockEDO();
        
        // Set expiration date to tomorrow
        $tomorrow = new \DateTime('tomorrow', new \DateTimeZone('UTC'));
        $edo->setExpiresAt($tomorrow);

        $result = $this->service->checkExpiration($edo);

        $this->assertFalse($result, 'eDO with future expiration date should not be detected as expired');
    }

    /**
     * Test that checkExpiration returns false when no expiration date is set
     * Validates: Requirement 4.1
     */
    public function testCheckExpirationReturnsFalseWhenNoExpirationDate(): void
    {
        $edo = $this->createMockEDO();
        $edo->setExpiresAt(null);

        $result = $this->service->checkExpiration($edo);

        $this->assertFalse($result, 'eDO without expiration date should not be detected as expired');
    }

    /**
     * Test that calculateExpiredDays returns correct number of days
     * Validates: Requirement 4.4
     */
    public function testCalculateExpiredDaysReturnsCorrectValue(): void
    {
        $edo = $this->createMockEDO();
        
        // Set expiration date to 5 days ago
        $fiveDaysAgo = new \DateTime('-5 days', new \DateTimeZone('UTC'));
        $edo->setExpiresAt($fiveDaysAgo);

        $result = $this->service->calculateExpiredDays($edo);

        $this->assertEquals(5, $result, 'Should calculate 5 expired days');
    }

    /**
     * Test that calculateExpiredDays returns 0 for non-expired eDO
     * Validates: Requirement 4.4
     */
    public function testCalculateExpiredDaysReturnsZeroForNonExpiredEDO(): void
    {
        $edo = $this->createMockEDO();
        
        // Set expiration date to tomorrow
        $tomorrow = new \DateTime('tomorrow', new \DateTimeZone('UTC'));
        $edo->setExpiresAt($tomorrow);

        $result = $this->service->calculateExpiredDays($edo);

        $this->assertEquals(0, $result, 'Non-expired eDO should have 0 expired days');
    }

    /**
     * Test that markAsExpired updates status and sends notifications
     * Validates: Requirement 4.2, 4.3, 5.1, 5.2
     */
    public function testMarkAsExpiredUpdatesStatusAndNotifies(): void
    {
        $edo = $this->createMockEDO();
        
        // Set expiration date to 3 days ago
        $threeDaysAgo = new \DateTime('-3 days', new \DateTimeZone('UTC'));
        $edo->setExpiresAt($threeDaysAgo);

        // Expect entity manager to flush changes
        $this->entityManager->expects($this->once())
            ->method('flush');

        // Expect notification service to be called
        $this->notificationService->expects($this->once())
            ->method('notifyExpiration')
            ->with($edo);

        $this->service->markAsExpired($edo);

        // Verify status was updated
        $this->assertEquals(EDOStatus::EXPIRED, $edo->getStatus(), 'eDO status should be set to EXPIRED');
        
        // Verify expired days was calculated and set
        $this->assertEquals(3, $edo->getExpiredDays(), 'Expired days should be set to 3');
    }

    /**
     * Test that processExpiredEDOs processes all active eDOs
     * Validates: Requirement 4.1, 4.2
     */
    public function testProcessExpiredEDOsProcessesActiveEDOs(): void
    {
        // Create mock eDOs
        $expiredEDO1 = $this->createMockEDO();
        $expiredEDO1->setExpiresAt(new \DateTime('-2 days', new \DateTimeZone('UTC')));

        $expiredEDO2 = $this->createMockEDO();
        $expiredEDO2->setExpiresAt(new \DateTime('-1 day', new \DateTimeZone('UTC')));

        $activeEDO = $this->createMockEDO();
        $activeEDO->setExpiresAt(new \DateTime('+1 day', new \DateTimeZone('UTC')));

        $activeEDOs = [$expiredEDO1, $expiredEDO2, $activeEDO];

        // Mock repository to return active eDOs
        $this->edoRepository->expects($this->once())
            ->method('findBy')
            ->with(['status' => EDOStatus::ACTIVE])
            ->willReturn($activeEDOs);

        // Expect entity manager to flush for each expired eDO
        $this->entityManager->expects($this->exactly(2))
            ->method('flush');

        // Expect notification service to be called for each expired eDO
        $this->notificationService->expects($this->exactly(2))
            ->method('notifyExpiration');

        $result = $this->service->processExpiredEDOs();

        // Should return count of expired eDOs
        $this->assertEquals(2, $result, 'Should process 2 expired eDOs');
    }

    /**
     * Helper method to create a mock eDO with required relationships
     */
    private function createMockEDO(): ElectronicDeliveryOrder
    {
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber('EDO-20260101-TEST-0001');
        $edo->setStatus(EDOStatus::ACTIVE);
        $edo->setPdfPath('/path/to/pdf');

        // Create mock container
        $container = $this->createMock(Container::class);
        $container->method('getContainerNumber')->willReturn('CONT123456');
        $edo->setContainer($container);

        // Create mock manifest
        $manifest = $this->createMock(Manifest::class);
        $edo->setManifest($manifest);

        return $edo;
    }
}
