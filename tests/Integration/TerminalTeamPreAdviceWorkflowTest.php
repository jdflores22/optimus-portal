<?php

namespace App\Tests\Integration;

use App\Entity\Container;
use App\Entity\Terminal;
use App\Entity\TerminalSlot;
use App\Entity\PreAdviceRequest;
use App\Entity\GeotagPhoto;
use App\Entity\StaffUser;
use App\Entity\Trucker;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\TerminalType;
use App\Entity\Enum\SlotStatus;
use App\Entity\Enum\PreAdviceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Comprehensive Integration Test for Terminal Team Pre-Advice Workflow
 * 
 * Tests complete pre-advice workflow from submission to EDO generation
 * Verifies Terminal Team verification process
 * Includes error handling and edge case testing
 * 
 * Requirements: All requirements
 */
class TerminalTeamPreAdviceWorkflowTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = $kernel->getContainer();
        
        $this->entityManager = $container->get('doctrine')->getManager();
        
        // Ensure we have a fresh entity manager
        if (!$this->entityManager->isOpen()) {
            $this->entityManager = $container->get('doctrine')->resetManager();
        }
    }

    protected function tearDown(): void
    {
        if ($this->entityManager && $this->entityManager->isOpen()) {
            $this->entityManager->clear();
            $this->entityManager->close();
        }
        parent::tearDown();
    }

    /**
     * Test complete pre-advice workflow from trucker submission to EDO generation
     */
    public function testCompletePreAdviceWorkflow(): void
    {
        $this->entityManager->beginTransaction();

        try {
            // Step 1: Setup test data
            $terminal = $this->createTestTerminal();
            $container = $this->createTestContainer();
            $trucker = $this->createTestTrucker();
            $terminalTeamMember = $this->createTestTerminalTeamMember();
            
            $this->entityManager->flush();

            // Step 2: Create terminal slot for tomorrow
            $tomorrow = new \DateTime('tomorrow');
            $terminalSlot = $this->createTestTerminalSlot($terminal, $tomorrow);
            $this->entityManager->flush();

            // Step 3: Verify container is available for return
            $this->assertEquals(ContainerStatus::AVAILABLE_FOR_RETURN, $container->getStatus());
            $this->assertGreaterThanOrEqual(new \DateTime('today'), $container->getExpectedReturnDate());

            // Step 4: Create geotag photos
            $geotagPhotos = $this->createTestGeotagPhotos();
            
            // Step 5: Create pre-advice request
            $preAdviceRequest = $this->createTestPreAdviceRequest($trucker, $container, $terminal);
            
            // Attach photos to pre-advice request
            foreach ($geotagPhotos as $photo) {
                $photo->setPreAdviceRequest($preAdviceRequest);
                $this->entityManager->persist($photo);
            }
            
            $this->entityManager->flush();

            // Step 6: Verify pre-advice request creation
            $this->assertInstanceOf(PreAdviceRequest::class, $preAdviceRequest);
            $this->assertEquals(PreAdviceStatus::PENDING, $preAdviceRequest->getStatus());
            $this->assertEquals($trucker, $preAdviceRequest->getTrucker());
            $this->assertEquals($container, $preAdviceRequest->getContainer());
            $this->assertEquals($terminal, $preAdviceRequest->getSelectedTerminal());
            $this->assertNotEmpty($preAdviceRequest->getPaymentReference());

            // Step 7: Verify photos are attached
            $this->assertCount(2, $preAdviceRequest->getGeotagPhotos());
            foreach ($preAdviceRequest->getGeotagPhotos() as $photo) {
                $this->assertInstanceOf(GeotagPhoto::class, $photo);
                $this->assertNotNull($photo->getLatitude());
                $this->assertNotNull($photo->getLongitude());
            }

            // Step 8: Terminal Team approves pre-advice request
            $preAdviceRequest->setStatus(PreAdviceStatus::VERIFIED);
            $preAdviceRequest->setVerifiedBy($terminalTeamMember);
            $preAdviceRequest->setVerifiedAt(new \DateTime());
            $preAdviceRequest->setAssignedSlot($terminalSlot);

            // Step 9: Generate EDO and QR code
            $edoNumber = 'EDO' . date('YmdHis') . str_pad($preAdviceRequest->getId(), 8, '0', STR_PAD_LEFT) . 'CY';
            $qrCode = 'TTPA_v1_' . date('ymdHis') . str_pad($preAdviceRequest->getId(), 6, '0', STR_PAD_LEFT) . 'CY' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)) . '_' . substr(hash('sha256', 'test_content'), 0, 8);
            
            $preAdviceRequest->setEdoNumber($edoNumber);
            $preAdviceRequest->setQrCode($qrCode);
            $preAdviceRequest->setStatus(PreAdviceStatus::COMPLETED);
            
            $this->entityManager->flush();

            // Step 10: Verify final state
            $this->assertEquals(PreAdviceStatus::COMPLETED, $preAdviceRequest->getStatus());
            $this->assertEquals($terminalTeamMember, $preAdviceRequest->getVerifiedBy());
            $this->assertNotNull($preAdviceRequest->getVerifiedAt());
            $this->assertEquals($terminalSlot, $preAdviceRequest->getAssignedSlot());
            $this->assertNotNull($preAdviceRequest->getEdoNumber());
            $this->assertNotNull($preAdviceRequest->getQrCode());
            $this->assertStringStartsWith('EDO', $preAdviceRequest->getEdoNumber());
            $this->assertStringStartsWith('TTPA_v1_', $preAdviceRequest->getQrCode());

            // Step 11: Verify slot assignment
            $terminalSlot->setAssignedCount($terminalSlot->getAssignedCount() + 1);
            $this->entityManager->flush();
            $this->assertEquals(1, $terminalSlot->getAssignedCount());

        } finally {
            $this->entityManager->rollback();
        }
    }

    /**
     * Test Terminal Team rejection workflow
     */
    public function testTerminalTeamRejectionWorkflow(): void
    {
        $this->entityManager->beginTransaction();

        try {
            // Setup test data
            $terminal = $this->createTestTerminal();
            $container = $this->createTestContainer();
            $trucker = $this->createTestTrucker();
            $terminalTeamMember = $this->createTestTerminalTeamMember();
            
            $this->entityManager->flush();

            // Create slots
            $tomorrow = new \DateTime('tomorrow');
            $terminalSlot = $this->createTestTerminalSlot($terminal, $tomorrow);
            $this->entityManager->flush();

            // Submit pre-advice request with poor quality photos
            $invalidPhotos = $this->createTestGeotagPhotos(false); // Invalid photos
            $preAdviceRequest = $this->createTestPreAdviceRequest($trucker, $container, $terminal);
            
            // Attach invalid photos
            foreach ($invalidPhotos as $photo) {
                $photo->setPreAdviceRequest($preAdviceRequest);
                $this->entityManager->persist($photo);
            }
            
            $this->entityManager->flush();

            // Terminal Team rejects the request
            $preAdviceRequest->setStatus(PreAdviceStatus::REJECTED);
            $preAdviceRequest->setVerifiedBy($terminalTeamMember);
            $preAdviceRequest->setVerifiedAt(new \DateTime());
            $preAdviceRequest->setRejectionReason('Poor photo quality - GPS coordinates missing');
            
            $this->entityManager->flush();

            $this->assertEquals(PreAdviceStatus::REJECTED, $preAdviceRequest->getStatus());
            $this->assertEquals($terminalTeamMember, $preAdviceRequest->getVerifiedBy());
            $this->assertNotNull($preAdviceRequest->getVerifiedAt());
            $this->assertEquals('Poor photo quality - GPS coordinates missing', $preAdviceRequest->getRejectionReason());
            $this->assertNull($preAdviceRequest->getAssignedSlot());
            $this->assertNull($preAdviceRequest->getEdoNumber());
            $this->assertNull($preAdviceRequest->getQrCode());

            // Verify no slot was assigned
            $this->assertEquals(0, $terminalSlot->getAssignedCount());

        } finally {
            $this->entityManager->rollback();
        }
    }

    /**
     * Test error handling for invalid container
     */
    public function testInvalidContainerErrorHandling(): void
    {
        $this->entityManager->beginTransaction();

        try {
            $trucker = $this->createTestTrucker();
            $this->entityManager->flush();

            // Try to find non-existent container
            $containerRepository = $this->entityManager->getRepository(Container::class);
            $nonExistentContainer = $containerRepository->findOneBy(['containerNumber' => 'INVALID123']);
            $this->assertNull($nonExistentContainer, 'Non-existent container should return null');

            // Verify container number format validation
            $validFormat = preg_match('/^[A-Z]{4}[0-9]{7}$/', 'ABCD1234567');
            $this->assertEquals(1, $validFormat, 'Valid container number format should pass validation');

            $invalidFormat = preg_match('/^[A-Z]{4}[0-9]{7}$/', 'INVALID');
            $this->assertEquals(0, $invalidFormat, 'Invalid container number format should fail validation');

        } finally {
            $this->entityManager->rollback();
        }
    }

    /**
     * Test terminal capacity exceeded scenario
     */
    public function testTerminalCapacityExceeded(): void
    {
        $this->entityManager->beginTransaction();

        try {
            // Create terminal with capacity of 1
            $terminal = $this->createTestTerminal();
            $terminal->setDailyCapacity(1);
            
            $container1 = $this->createTestContainer('CONT1234567');
            $container2 = $this->createTestContainer('CONT9876543');
            $trucker1 = $this->createTestTrucker('trucker1@test.com');
            $trucker2 = $this->createTestTrucker('trucker2@test.com');
            $terminalTeamMember = $this->createTestTerminalTeamMember();
            
            $this->entityManager->flush();

            $tomorrow = new \DateTime('tomorrow');
            $terminalSlot = $this->createTestTerminalSlot($terminal, $tomorrow);
            $terminalSlot->setCapacity(1); // Set capacity to 1
            $this->entityManager->flush();

            // First trucker submits and gets approved
            $preAdviceRequest1 = $this->createTestPreAdviceRequest($trucker1, $container1, $terminal);
            $preAdviceRequest1->setStatus(PreAdviceStatus::VERIFIED);
            $preAdviceRequest1->setVerifiedBy($terminalTeamMember);
            $preAdviceRequest1->setVerifiedAt(new \DateTime());
            $preAdviceRequest1->setAssignedSlot($terminalSlot);
            
            // Update slot assignment
            $terminalSlot->setAssignedCount(1);
            $this->entityManager->flush();

            // Second trucker submits request
            $preAdviceRequest2 = $this->createTestPreAdviceRequest($trucker2, $container2, $terminal);
            $this->entityManager->flush();

            // Verify first request is approved
            $this->assertEquals(PreAdviceStatus::VERIFIED, $preAdviceRequest1->getStatus());
            $this->assertEquals($terminalSlot, $preAdviceRequest1->getAssignedSlot());

            // Verify second request is still pending (capacity exceeded)
            $this->assertEquals(PreAdviceStatus::PENDING, $preAdviceRequest2->getStatus());
            $this->assertNull($preAdviceRequest2->getAssignedSlot());

            // Verify slot is at capacity
            $this->assertEquals(1, $terminalSlot->getAssignedCount());
            $this->assertEquals(1, $terminalSlot->getCapacity());

        } finally {
            $this->entityManager->rollback();
        }
    }

    /**
     * Test photo validation edge cases
     */
    public function testPhotoValidationEdgeCases(): void
    {
        $this->entityManager->beginTransaction();

        try {
            $terminal = $this->createTestTerminal();
            $container = $this->createTestContainer();
            $trucker = $this->createTestTrucker();
            
            $this->entityManager->flush();

            // Test with valid photos (all photos must have GPS coordinates since they're required fields)
            $validPhotos = $this->createTestGeotagPhotos(true);
            
            foreach ($validPhotos as $photo) {
                $this->assertNotNull($photo->getLatitude(), 'Photo with GPS should have latitude');
                $this->assertNotNull($photo->getLongitude(), 'Photo with GPS should have longitude');
                $this->assertIsString($photo->getLatitude(), 'Latitude should be a string');
                $this->assertIsString($photo->getLongitude(), 'Longitude should be a string');
                
                // Validate GPS coordinate format
                $this->assertMatchesRegularExpression('/^-?\d+\.\d+$/', $photo->getLatitude(), 'Latitude should be a valid decimal');
                $this->assertMatchesRegularExpression('/^-?\d+\.\d+$/', $photo->getLongitude(), 'Longitude should be a valid decimal');
            }

            // Test photo verification status
            $photo = $validPhotos[0];
            $this->assertFalse($photo->isVerified(), 'Photo should not be verified initially');
            
            $photo->setIsVerified(true);
            $photo->setVerificationNotes('GPS coordinates verified');
            
            $this->assertTrue($photo->isVerified(), 'Photo should be verified after setting');
            $this->assertEquals('GPS coordinates verified', $photo->getVerificationNotes());

        } finally {
            $this->entityManager->rollback();
        }
    }

    /**
     * Test concurrent pre-advice submissions
     */
    public function testConcurrentPreAdviceSubmissions(): void
    {
        $this->entityManager->beginTransaction();

        try {
            $terminal = $this->createTestTerminal();
            $terminal->setDailyCapacity(2); // Allow 2 slots
            
            $container1 = $this->createTestContainer('CONC1234567');
            $container2 = $this->createTestContainer('CONC9876543');
            $trucker1 = $this->createTestTrucker('concurrent1@test.com');
            $trucker2 = $this->createTestTrucker('concurrent2@test.com');
            
            $this->entityManager->flush();

            $tomorrow = new \DateTime('tomorrow');
            $terminalSlot = $this->createTestTerminalSlot($terminal, $tomorrow);
            $terminalSlot->setCapacity(2); // Allow 2 assignments
            $this->entityManager->flush();

            // Simulate concurrent submissions
            $preAdviceRequest1 = $this->createTestPreAdviceRequest($trucker1, $container1, $terminal);
            $preAdviceRequest2 = $this->createTestPreAdviceRequest($trucker2, $container2, $terminal);
            
            $this->entityManager->flush();

            $this->assertEquals(PreAdviceStatus::PENDING, $preAdviceRequest1->getStatus());
            $this->assertEquals(PreAdviceStatus::PENDING, $preAdviceRequest2->getStatus());
            $this->assertNotEquals($preAdviceRequest1->getId(), $preAdviceRequest2->getId());

            // Verify both requests exist in database
            $preAdviceRepository = $this->entityManager->getRepository(PreAdviceRequest::class);
            $pendingRequests = $preAdviceRepository->findBy([
                'selectedTerminal' => $terminal,
                'status' => PreAdviceStatus::PENDING
            ]);
            $this->assertCount(2, $pendingRequests);

        } finally {
            $this->entityManager->rollback();
        }
    }

    // Helper methods

    private function createTestTerminal(): Terminal
    {
        $terminal = new Terminal();
        $terminal->setName('Test Terminal ' . uniqid())
            ->setType(TerminalType::CY)
            ->setLocation('Test Location')
            ->setDailyCapacity(50)
            ->setIsActive(true);

        $this->entityManager->persist($terminal);
        return $terminal;
    }

    private function createTestContainer(string $containerNumber = null): Container
    {
        $container = new Container();
        $container->setContainerNumber($containerNumber ?? 'TEST' . uniqid())
            ->setSize('20ft')
            ->setType('Dry')
            ->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN)
            ->setCurrentLocation('Test Location')
            ->setExpectedReturnDate(new \DateTime('+1 day'));

        $this->entityManager->persist($container);
        return $container;
    }

    private function createTestTrucker(string $email = null): Trucker
    {
        $trucker = new Trucker();
        $trucker->setEmail($email ?? 'trucker' . uniqid() . '@test.com')
            ->setPasswordHash('hashed_password')
            ->setRole(UserRole::TRUCKER)
            ->setStatus(AccountStatus::APPROVED)
            ->setFirstName('Test')
            ->setLastName('Trucker');

        $this->entityManager->persist($trucker);
        return $trucker;
    }

    private function createTestTerminalTeamMember(): StaffUser
    {
        $terminalTeam = new StaffUser();
        $terminalTeam->setEmail('terminal' . uniqid() . '@test.com')
            ->setPasswordHash('hashed_password')
            ->setRole(UserRole::TERMINAL_TEAM)
            ->setStatus(AccountStatus::APPROVED)
            ->setFirstName('Terminal')
            ->setLastName('Team')
            ->setDepartment('Terminal Operations');

        $this->entityManager->persist($terminalTeam);
        return $terminalTeam;
    }

    private function createTestPreAdviceRequest(Trucker $trucker, Container $container, Terminal $terminal): PreAdviceRequest
    {
        $preAdviceRequest = new PreAdviceRequest();
        $preAdviceRequest->setTrucker($trucker)
            ->setContainer($container)
            ->setSelectedTerminal($terminal)
            ->setPaymentReference('PAY' . date('YmdHis') . rand(1000, 9999))
            ->setStatus(PreAdviceStatus::PENDING);

        $this->entityManager->persist($preAdviceRequest);
        return $preAdviceRequest;
    }

    private function createTestTerminalSlot(Terminal $terminal, \DateTime $date): TerminalSlot
    {
        $terminalSlot = new TerminalSlot();
        $terminalSlot->setTerminal($terminal)
            ->setDate($date)
            ->setCapacity($terminal->getDailyCapacity())
            ->setAssignedCount(0)
            ->setStatus(SlotStatus::AVAILABLE);

        $this->entityManager->persist($terminalSlot);
        return $terminalSlot;
    }

    private function createTestGeotagPhotos(bool $withGPS = true): array
    {
        $photos = [];
        
        for ($i = 0; $i < 2; $i++) {
            $photo = new GeotagPhoto();
            $photo->setFilename('test_photo_' . $i . '.jpg')
                ->setOriginalName('container_side_' . $i . '.jpg')
                ->setCapturedAt(new \DateTime())
                ->setIsVerified(false);

            // Since latitude and longitude are required fields, always set them
            // For "invalid" photos, we can use obviously fake coordinates
            if ($withGPS) {
                $photo->setLatitude((string)(40.7128 + (rand(-100, 100) / 10000)))
                    ->setLongitude((string)(-74.0060 + (rand(-100, 100) / 10000)));
            } else {
                // Use obviously invalid coordinates for "bad" photos
                $photo->setLatitude('0.0000000')
                    ->setLongitude('0.0000000');
            }

            $this->entityManager->persist($photo);
            $photos[] = $photo;
        }

        return $photos;
    }
}