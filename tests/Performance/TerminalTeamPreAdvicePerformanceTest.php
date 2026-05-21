<?php

namespace App\Tests\Performance;

use App\Entity\User;
use App\Entity\Container;
use App\Entity\Terminal;
use App\Entity\TerminalSlot;
use App\Entity\PreAdviceRequest;
use App\Entity\GeotagPhoto;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\TerminalType;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\PreAdviceStatus;
use App\Entity\Enum\SlotStatus;
use App\Service\UserService;
use App\Service\ContainerSearchService;
use App\Service\TerminalService;
use App\Service\PreAdviceService;
use App\Service\PhotoVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Performance and Load Testing for Terminal Team Pre-Advice System
 * 
 * Tests system performance under:
 * - Concurrent pre-advice submissions
 * - Large dataset operations
 * - Photo upload performance
 * 
 * Requirements: System performance
 */
class TerminalTeamPreAdvicePerformanceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private UserService $userService;
    private ContainerSearchService $containerSearchService;
    private TerminalService $terminalService;
    private PreAdviceService $preAdviceService;
    private PhotoVerificationService $photoVerificationService;

    // Performance thresholds (in seconds)
    private const MAX_CONTAINER_SEARCH_TIME = 2.0;
    private const MAX_PRE_ADVICE_SUBMISSION_TIME = 5.0;
    private const MAX_PHOTO_UPLOAD_TIME = 10.0;
    private const MAX_DASHBOARD_LOAD_TIME = 3.0;
    private const MAX_CONCURRENT_OPERATIONS_TIME = 30.0;

    // Test data sizes
    private const LARGE_DATASET_SIZE = 1000;
    private const CONCURRENT_OPERATIONS_COUNT = 50;
    private const LARGE_PHOTO_SIZE_MB = 8; // 8MB photos for performance testing

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->userService = $container->get(UserService::class);
        $this->containerSearchService = $container->get(ContainerSearchService::class);
        $this->terminalService = $container->get(TerminalService::class);
        $this->preAdviceService = $container->get(PreAdviceService::class);
        $this->photoVerificationService = $container->get(PhotoVerificationService::class);

        // Clean database and start transaction
        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->rollback();
        parent::tearDown();
    }

    /**
     * Test container search performance with large dataset
     */
    public function testContainerSearchPerformanceWithLargeDataset(): void
    {
        // Create large dataset of containers
        $this->createLargeContainerDataset(self::LARGE_DATASET_SIZE);

        // Test container search performance
        $searchQueries = [
            'CONT123456789',
            'CONT999999999',
            'CONT500000000',
            'NONEXISTENT123'
        ];

        foreach ($searchQueries as $containerNumber) {
            $startTime = microtime(true);
            
            $result = $this->containerSearchService->searchContainer($containerNumber);
            
            $endTime = microtime(true);
            $executionTime = $endTime - $startTime;

            $this->assertLessThan(
                self::MAX_CONTAINER_SEARCH_TIME,
                $executionTime,
                "Container search for '{$containerNumber}' took {$executionTime}s, exceeding {self::MAX_CONTAINER_SEARCH_TIME}s threshold"
            );
        }
    }

    /**
     * Test terminal availability checking performance with large slot dataset
     */
    public function testTerminalAvailabilityPerformanceWithLargeDataset(): void
    {
        // Create terminals with large number of slots
        $terminals = $this->createTerminalsWithLargeSlotDataset();

        foreach ($terminals as $terminal) {
            $startTime = microtime(true);
            
            $availableSlots = $this->terminalService->getAvailableSlots(
                $terminal,
                new \DateTime(),
                new \DateTime('+30 days')
            );
            
            $endTime = microtime(true);
            $executionTime = $endTime - $startTime;

            $this->assertLessThan(
                self::MAX_DASHBOARD_LOAD_TIME,
                $executionTime,
                "Terminal availability check for terminal {$terminal->getId()} took {$executionTime}s, exceeding {self::MAX_DASHBOARD_LOAD_TIME}s threshold"
            );

            $this->assertIsArray($availableSlots);
        }
    }

    /**
     * Test concurrent pre-advice submissions performance
     */
    public function testConcurrentPreAdviceSubmissionsPerformance(): void
    {
        // Create test data
        $truckers = $this->createMultipleTruckers(self::CONCURRENT_OPERATIONS_COUNT);
        $containers = $this->createMultipleContainers(self::CONCURRENT_OPERATIONS_COUNT);
        $terminal = $this->createTerminalWithSlots();

        $startTime = microtime(true);

        // Simulate concurrent pre-advice submissions
        $submissions = [];
        for ($i = 0; $i < self::CONCURRENT_OPERATIONS_COUNT; $i++) {
            $photo = $this->createTestGeotagPhoto();
            
            $submissionStartTime = microtime(true);
            
            $preAdviceRequest = $this->preAdviceService->submitPreAdvice(
                $truckers[$i],
                $containers[$i],
                $terminal,
                [$photo],
                'TEST_PAYMENT_REF_' . $i
            );
            
            $submissionEndTime = microtime(true);
            $submissionTime = $submissionEndTime - $submissionStartTime;

            $this->assertLessThan(
                self::MAX_PRE_ADVICE_SUBMISSION_TIME,
                $submissionTime,
                "Pre-advice submission {$i} took {$submissionTime}s, exceeding {self::MAX_PRE_ADVICE_SUBMISSION_TIME}s threshold"
            );

            $submissions[] = $preAdviceRequest;
        }

        $endTime = microtime(true);
        $totalExecutionTime = $endTime - $startTime;

        $this->assertLessThan(
            self::MAX_CONCURRENT_OPERATIONS_TIME,
            $totalExecutionTime,
            "Concurrent pre-advice submissions took {$totalExecutionTime}s, exceeding {self::MAX_CONCURRENT_OPERATIONS_TIME}s threshold"
        );

        // Verify all submissions were successful
        $this->assertCount(self::CONCURRENT_OPERATIONS_COUNT, $submissions);
        foreach ($submissions as $submission) {
            $this->assertInstanceOf(PreAdviceRequest::class, $submission);
            $this->assertEquals(PreAdviceStatus::PENDING, $submission->getStatus());
        }
    }

    /**
     * Test photo upload performance with large files
     */
    public function testPhotoUploadPerformanceWithLargeFiles(): void
    {
        $trucker = $this->createTestTrucker();
        $container = $this->createTestContainer();
        $terminal = $this->createTerminalWithSlots();

        // Create large photo files for testing
        $largePhotos = $this->createLargeTestPhotos(5, self::LARGE_PHOTO_SIZE_MB);

        foreach ($largePhotos as $index => $photo) {
            $startTime = microtime(true);
            
            $geotagPhoto = $this->photoVerificationService->processGeotagPhoto(
                $photo,
                40.7128, // NYC latitude
                -74.0060  // NYC longitude
            );
            
            $endTime = microtime(true);
            $executionTime = $endTime - $startTime;

            $this->assertLessThan(
                self::MAX_PHOTO_UPLOAD_TIME,
                $executionTime,
                "Photo upload {$index} ({self::LARGE_PHOTO_SIZE_MB}MB) took {$executionTime}s, exceeding {self::MAX_PHOTO_UPLOAD_TIME}s threshold"
            );

            $this->assertInstanceOf(GeotagPhoto::class, $geotagPhoto);
            $this->assertEquals(40.7128, $geotagPhoto->getLatitude(), '', 0.001);
            $this->assertEquals(-74.0060, $geotagPhoto->getLongitude(), '', 0.001);

            // Clean up uploaded file
            unlink($photo->getPathname());
        }
    }

    /**
     * Test database query performance with large pre-advice dataset
     */
    public function testDatabaseQueryPerformanceWithLargePreAdviceDataset(): void
    {
        // Create large dataset of pre-advice requests
        $this->createLargePreAdviceDataset(self::LARGE_DATASET_SIZE);

        // Test various database queries that would be used in the dashboard
        $queries = [
            'getPendingPreAdviceCount' => function() {
                return $this->entityManager->getRepository(PreAdviceRequest::class)
                    ->count(['status' => PreAdviceStatus::PENDING]);
            },
            'getVerifiedPreAdviceCount' => function() {
                return $this->entityManager->getRepository(PreAdviceRequest::class)
                    ->count(['status' => PreAdviceStatus::VERIFIED]);
            },
            'getRecentPreAdviceRequests' => function() {
                return $this->entityManager->getRepository(PreAdviceRequest::class)
                    ->findBy([], ['createdAt' => 'DESC'], 50);
            },
            'getTerminalUtilization' => function() {
                return $this->entityManager->getRepository(TerminalSlot::class)
                    ->createQueryBuilder('ts')
                    ->select('COUNT(ts.id) as total_slots, SUM(ts.assignedCount) as assigned_slots')
                    ->where('ts.date >= :today')
                    ->setParameter('today', new \DateTime())
                    ->getQuery()
                    ->getSingleResult();
            }
        ];

        foreach ($queries as $queryName => $queryFunction) {
            $startTime = microtime(true);
            
            $result = $queryFunction();
            
            $endTime = microtime(true);
            $executionTime = $endTime - $startTime;

            $this->assertLessThan(
                self::MAX_DASHBOARD_LOAD_TIME,
                $executionTime,
                "Database query '{$queryName}' took {$executionTime}s, exceeding {self::MAX_DASHBOARD_LOAD_TIME}s threshold"
            );

            $this->assertNotNull($result);
        }
    }

    /**
     * Test memory usage during bulk operations
     */
    public function testMemoryUsageDuringBulkOperations(): void
    {
        $initialMemory = memory_get_usage(true);
        $maxMemoryLimit = 256 * 1024 * 1024; // 256MB limit

        // Perform bulk operations
        $this->createLargeContainerDataset(500);
        $this->createLargePreAdviceDataset(500);

        $peakMemory = memory_get_peak_usage(true);
        $memoryIncrease = $peakMemory - $initialMemory;

        $this->assertLessThan(
            $maxMemoryLimit,
            $peakMemory,
            "Peak memory usage ({$peakMemory} bytes) exceeded limit ({$maxMemoryLimit} bytes)"
        );

        // Log memory usage for monitoring
        echo "\nMemory Usage Report:\n";
        echo "Initial Memory: " . number_format($initialMemory / 1024 / 1024, 2) . " MB\n";
        echo "Peak Memory: " . number_format($peakMemory / 1024 / 1024, 2) . " MB\n";
        echo "Memory Increase: " . number_format($memoryIncrease / 1024 / 1024, 2) . " MB\n";
    }

    // Helper methods for creating test data

    private function createLargeContainerDataset(int $count): array
    {
        $containers = [];
        
        for ($i = 1; $i <= $count; $i++) {
            $container = new Container();
            $container->setContainerNumber(sprintf('CONT%09d', $i));
            $container->setSize($i % 2 === 0 ? '40ft' : '20ft');
            $container->setType('Dry');
            $container->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN);
            $container->setCurrentLocation('Port Location ' . ($i % 10));
            $container->setExpectedReturnDate(new \DateTime('+' . ($i % 30) . ' days'));
            $container->setCreatedAt(new \DateTime());
            $container->setUpdatedAt(new \DateTime());

            $this->entityManager->persist($container);
            $containers[] = $container;

            // Flush in batches to avoid memory issues
            if ($i % 100 === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        $this->entityManager->flush();
        return $containers;
    }

    private function createTerminalsWithLargeSlotDataset(): array
    {
        $terminals = [];
        $terminalTypes = [TerminalType::CY, TerminalType::ATI, TerminalType::ICTSI];

        foreach ($terminalTypes as $index => $type) {
            $terminal = new Terminal();
            $terminal->setName($type->value . ' Terminal');
            $terminal->setType($type);
            $terminal->setLocation('Location ' . $index);
            $terminal->setDailyCapacity(100);
            $terminal->setIsActive(true);
            $terminal->setCreatedAt(new \DateTime());
            $terminal->setUpdatedAt(new \DateTime());

            $this->entityManager->persist($terminal);

            // Create many slots for each terminal
            for ($day = 0; $day < 90; $day++) {
                $slot = new TerminalSlot();
                $slot->setTerminal($terminal);
                $slot->setDate(new \DateTime('+' . $day . ' days'));
                $slot->setCapacity(100);
                $slot->setAssignedCount(rand(0, 80));
                $slot->setStatus($slot->getAssignedCount() >= 100 ? SlotStatus::FULL : SlotStatus::AVAILABLE);
                $slot->setCreatedAt(new \DateTime());

                $this->entityManager->persist($slot);
            }

            $terminals[] = $terminal;
        }

        $this->entityManager->flush();
        return $terminals;
    }

    private function createMultipleTruckers(int $count): array
    {
        $truckers = [];

        for ($i = 1; $i <= $count; $i++) {
            $trucker = $this->userService->createUser([
                'email' => "trucker{$i}@performance.test",
                'password' => 'SecurePass123!',
                'firstName' => "Trucker{$i}",
                'lastName' => 'Performance',
                'phoneNumber' => '555-' . str_pad($i, 4, '0', STR_PAD_LEFT)
            ], UserRole::TRUCKER);

            $truckers[] = $trucker;
        }

        $this->entityManager->flush();
        return $truckers;
    }

    private function createMultipleContainers(int $count): array
    {
        $containers = [];

        for ($i = 1; $i <= $count; $i++) {
            $container = new Container();
            $container->setContainerNumber(sprintf('PERF%09d', $i));
            $container->setSize('40ft');
            $container->setType('Dry');
            $container->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN);
            $container->setCurrentLocation('Performance Test Location');
            $container->setExpectedReturnDate(new \DateTime('+7 days'));
            $container->setCreatedAt(new \DateTime());
            $container->setUpdatedAt(new \DateTime());

            $this->entityManager->persist($container);
            $containers[] = $container;
        }

        $this->entityManager->flush();
        return $containers;
    }

    private function createTerminalWithSlots(): Terminal
    {
        $terminal = new Terminal();
        $terminal->setName('Performance Test Terminal');
        $terminal->setType(TerminalType::CY);
        $terminal->setLocation('Performance Test Location');
        $terminal->setDailyCapacity(1000);
        $terminal->setIsActive(true);
        $terminal->setCreatedAt(new \DateTime());
        $terminal->setUpdatedAt(new \DateTime());

        $this->entityManager->persist($terminal);

        // Create slots for the next 30 days
        for ($day = 0; $day < 30; $day++) {
            $slot = new TerminalSlot();
            $slot->setTerminal($terminal);
            $slot->setDate(new \DateTime('+' . $day . ' days'));
            $slot->setCapacity(1000);
            $slot->setAssignedCount(0);
            $slot->setStatus(SlotStatus::AVAILABLE);
            $slot->setCreatedAt(new \DateTime());

            $this->entityManager->persist($slot);
        }

        $this->entityManager->flush();
        return $terminal;
    }

    private function createTestTrucker(): User
    {
        return $this->userService->createUser([
            'email' => 'performance.trucker@test.com',
            'password' => 'SecurePass123!',
            'firstName' => 'Performance',
            'lastName' => 'Trucker',
            'phoneNumber' => '555-PERF'
        ], UserRole::TRUCKER);
    }

    private function createTestContainer(): Container
    {
        $container = new Container();
        $container->setContainerNumber('PERFTEST001');
        $container->setSize('40ft');
        $container->setType('Dry');
        $container->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN);
        $container->setCurrentLocation('Performance Test Location');
        $container->setExpectedReturnDate(new \DateTime('+7 days'));
        $container->setCreatedAt(new \DateTime());
        $container->setUpdatedAt(new \DateTime());

        $this->entityManager->persist($container);
        $this->entityManager->flush();

        return $container;
    }

    private function createTestGeotagPhoto(): UploadedFile
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'geotag_photo');
        
        // Create a small test image with EXIF data
        $imageContent = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwA/8A');
        file_put_contents($tempFile, $imageContent);

        return new UploadedFile(
            $tempFile,
            'test_geotag_photo.jpg',
            'image/jpeg',
            null,
            true
        );
    }

    private function createLargeTestPhotos(int $count, int $sizeMB): array
    {
        $photos = [];
        $sizeBytes = $sizeMB * 1024 * 1024;

        for ($i = 0; $i < $count; $i++) {
            $tempFile = tempnam(sys_get_temp_dir(), 'large_photo_' . $i);
            
            // Create a large file by repeating image data
            $baseImage = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwA/8A');
            $largeContent = str_repeat($baseImage, (int)($sizeBytes / strlen($baseImage)));
            file_put_contents($tempFile, $largeContent);

            $photos[] = new UploadedFile(
                $tempFile,
                "large_photo_{$i}.jpg",
                'image/jpeg',
                null,
                true
            );
        }

        return $photos;
    }

    private function createLargePreAdviceDataset(int $count): void
    {
        $trucker = $this->createTestTrucker();
        $terminal = $this->createTerminalWithSlots();

        for ($i = 1; $i <= $count; $i++) {
            $container = new Container();
            $container->setContainerNumber(sprintf('BULK%09d', $i));
            $container->setSize($i % 2 === 0 ? '40ft' : '20ft');
            $container->setType('Dry');
            $container->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN);
            $container->setCurrentLocation('Bulk Test Location');
            $container->setExpectedReturnDate(new \DateTime('+7 days'));
            $container->setCreatedAt(new \DateTime());
            $container->setUpdatedAt(new \DateTime());

            $this->entityManager->persist($container);

            $preAdvice = new PreAdviceRequest();
            $preAdvice->setTrucker($trucker);
            $preAdvice->setContainer($container);
            $preAdvice->setSelectedTerminal($terminal);
            $preAdvice->setStatus(PreAdviceStatus::PENDING);
            $preAdvice->setPaymentReference('BULK_PAY_' . $i);
            $preAdvice->setCreatedAt(new \DateTime('-' . rand(1, 30) . ' days'));
            $preAdvice->setUpdatedAt(new \DateTime());

            $this->entityManager->persist($preAdvice);

            // Flush in batches
            if ($i % 100 === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                
                // Re-fetch entities that will be reused
                $trucker = $this->entityManager->find(User::class, $trucker->getId());
                $terminal = $this->entityManager->find(Terminal::class, $terminal->getId());
            }
        }

        $this->entityManager->flush();
    }
}