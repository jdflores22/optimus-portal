<?php

namespace App\Tests\Property;

use App\Entity\Container;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\GenerationSession;
use App\Entity\Manifest;
use App\Entity\NOA;
use App\Entity\StaffUser;
use App\Service\BatchEDOGenerationService;
use App\Service\EDOGenerationServiceInterface;
use App\Service\EDOAuditServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Property Test: Sequential Container Processing Validation
 * 
 * **Feature: edo-generation-progress-modal, Property 7: Sequential Container Processing**
 * 
 * For any batch with containers C1, C2, ..., CN, container Ci+1 should not begin 
 * processing until container Ci has completed (successfully or with failure).
 * 
 * **Validates: Requirements 3.1, 3.6**
 */
class BatchEDOSequentialProcessingPropertyTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private EDOGenerationServiceInterface $edoGenerationService;
    private EDOAuditServiceInterface $auditService;
    private LoggerInterface $logger;
    private BatchEDOGenerationService $batchService;
    private array $processingOrder = [];

    protected function setUp(): void
    {
        self::bootKernel();
        
        // Create mocks
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->edoGenerationService = $this->createMock(EDOGenerationServiceInterface::class);
        $this->auditService = $this->createMock(EDOAuditServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        // Reset processing order tracker
        $this->processingOrder = [];
        
        // Configure entity manager mock
        $this->entityManager->method('persist');
        $this->entityManager->method('flush');
        
        // Create batch service
        $this->batchService = new BatchEDOGenerationService(
            $this->entityManager,
            $this->edoGenerationService,
            $this->auditService,
            $this->logger
        );
    }

    /**
     * Property: Sequential processing order must be maintained
     * 
     * This test validates that containers are processed in the exact order they appear
     * in the input array, with no container starting before the previous one completes.
     */
    public function testSequentialProcessingOrderProperty(): void
    {
        // Test with multiple iterations and varying container counts
        for ($iteration = 0; $iteration < 50; $iteration++) {
            $containerCount = rand(2, 10); // Test with 2-10 containers
            $this->runSequentialProcessingOrderTest($iteration, $containerCount);
        }
    }

    /**
     * Property: Sequential processing with failures
     * 
     * This test validates that even when a container fails, the next container
     * still waits for the failure to complete before starting.
     */
    public function testSequentialProcessingWithFailuresProperty(): void
    {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            $containerCount = rand(3, 8);
            $failureRate = rand(0, 50) / 100; // 0-50% failure rate
            $this->runSequentialProcessingWithFailuresTest($iteration, $containerCount, $failureRate);
        }
    }

    /**
     * Property: No concurrent processing
     * 
     * This test validates that at any given moment, only one container is being processed.
     */
    public function testNoConcurrentProcessingProperty(): void
    {
        for ($iteration = 0; $iteration < 25; $iteration++) {
            $containerCount = rand(2, 15);
            $this->runNoConcurrentProcessingTest($iteration, $containerCount);
        }
    }

    /**
     * Property: Processing completion before next start
     * 
     * This test validates that each container's processing (including all database
     * operations and logging) completes before the next container starts.
     */
    public function testProcessingCompletionBeforeNextStartProperty(): void
    {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $containerCount = rand(2, 12);
            $this->runProcessingCompletionBeforeNextStartTest($iteration, $containerCount);
        }
    }

    private function runSequentialProcessingOrderTest(int $iteration, int $containerCount): void
    {
        // Reset processing order tracker
        $this->processingOrder = [];
        
        // Create test data
        $manifest = $this->createMockManifest($iteration);
        $containers = $this->createMockContainers($containerCount, $iteration);
        $user = $this->createMockUser($iteration);
        $expirationDate = new \DateTime('+30 days');
        
        // Track the order in which containers are processed
        $expectedOrder = array_map(fn($c) => $c->getContainerNumber(), $containers);
        
        // Configure EDO generation service to track processing order
        $this->edoGenerationService
            ->method('generateEDOForContainer')
            ->willReturnCallback(function ($container, $manifest) use (&$expectedOrder) {
                // Record this container as being processed
                $this->processingOrder[] = $container->getContainerNumber();
                
                // Create and return mock EDO
                return $this->createMockEDO($container);
            });
        
        // Execute batch generation
        $session = $this->batchService->generateEDOsForContainers(
            $containers,
            $expirationDate,
            $manifest,
            $user
        );
        
        // Assert: Processing order matches input order exactly
        $this->assertEquals(
            $expectedOrder,
            $this->processingOrder,
            "Containers must be processed in the exact order they appear in the input array (iteration {$iteration})"
        );
        
        // Assert: All containers were processed
        $this->assertCount(
            $containerCount,
            $this->processingOrder,
            "All {$containerCount} containers must be processed (iteration {$iteration})"
        );
    }

    private function runSequentialProcessingWithFailuresTest(int $iteration, int $containerCount, float $failureRate): void
    {
        // Reset processing order tracker
        $this->processingOrder = [];
        
        // Create test data
        $manifest = $this->createMockManifest($iteration);
        $containers = $this->createMockContainers($containerCount, $iteration);
        $user = $this->createMockUser($iteration);
        $expirationDate = new \DateTime('+30 days');
        
        // Determine which containers will fail
        $failingContainers = [];
        foreach ($containers as $index => $container) {
            if ((rand(0, 100) / 100) < $failureRate) {
                $failingContainers[] = $container->getContainerNumber();
            }
        }
        
        // Track processing order and failures
        $processedBeforeFailure = [];
        
        // Configure EDO generation service to simulate failures
        $this->edoGenerationService
            ->method('generateEDOForContainer')
            ->willReturnCallback(function ($container, $manifest) use ($failingContainers, &$processedBeforeFailure) {
                // Record processing order
                $this->processingOrder[] = $container->getContainerNumber();
                
                // Simulate failure for designated containers
                if (in_array($container->getContainerNumber(), $failingContainers)) {
                    throw new \RuntimeException("Simulated failure for container {$container->getContainerNumber()}");
                }
                
                return $this->createMockEDO($container);
            });
        
        // Execute batch generation
        $session = $this->batchService->generateEDOsForContainers(
            $containers,
            $expirationDate,
            $manifest,
            $user
        );
        
        // Assert: All containers were attempted in order, regardless of failures
        $expectedOrder = array_map(fn($c) => $c->getContainerNumber(), $containers);
        $this->assertEquals(
            $expectedOrder,
            $this->processingOrder,
            "Containers must be processed in order even when failures occur (iteration {$iteration})"
        );
        
        // Assert: Processing continued after failures
        if (count($failingContainers) > 0 && count($failingContainers) < $containerCount) {
            $this->assertCount(
                $containerCount,
                $this->processingOrder,
                "All containers must be attempted even after failures (iteration {$iteration})"
            );
        }
    }

    private function runNoConcurrentProcessingTest(int $iteration, int $containerCount): void
    {
        // Reset processing order tracker
        $this->processingOrder = [];
        $activeProcessing = [];
        
        // Create test data
        $manifest = $this->createMockManifest($iteration);
        $containers = $this->createMockContainers($containerCount, $iteration);
        $user = $this->createMockUser($iteration);
        $expirationDate = new \DateTime('+30 days');
        
        // Configure EDO generation service to track concurrent processing
        $this->edoGenerationService
            ->method('generateEDOForContainer')
            ->willReturnCallback(function ($container, $manifest) use (&$activeProcessing) {
                $containerNumber = $container->getContainerNumber();
                
                // Assert: No other container is currently being processed
                $this->assertEmpty(
                    $activeProcessing,
                    "No other container should be processing when {$containerNumber} starts"
                );
                
                // Mark this container as actively processing
                $activeProcessing[] = $containerNumber;
                $this->processingOrder[] = $containerNumber;
                
                // Simulate some processing time
                usleep(1000); // 1ms
                
                // Mark processing as complete
                array_pop($activeProcessing);
                
                return $this->createMockEDO($container);
            });
        
        // Execute batch generation
        $session = $this->batchService->generateEDOsForContainers(
            $containers,
            $expirationDate,
            $manifest,
            $user
        );
        
        // Assert: No concurrent processing occurred
        $this->assertCount(
            $containerCount,
            $this->processingOrder,
            "All containers must be processed sequentially (iteration {$iteration})"
        );
    }

    private function runProcessingCompletionBeforeNextStartTest(int $iteration, int $containerCount): void
    {
        // Reset processing order tracker
        $this->processingOrder = [];
        $completionTimestamps = [];
        $startTimestamps = [];
        
        // Create test data
        $manifest = $this->createMockManifest($iteration);
        $containers = $this->createMockContainers($containerCount, $iteration);
        $user = $this->createMockUser($iteration);
        $expirationDate = new \DateTime('+30 days');
        
        // Configure EDO generation service to track timing
        $this->edoGenerationService
            ->method('generateEDOForContainer')
            ->willReturnCallback(function ($container, $manifest) use (&$completionTimestamps, &$startTimestamps) {
                $containerNumber = $container->getContainerNumber();
                
                // Record start time
                $startTimestamps[$containerNumber] = microtime(true);
                $this->processingOrder[] = $containerNumber;
                
                // Simulate processing
                usleep(rand(500, 2000)); // 0.5-2ms
                
                // Record completion time
                $completionTimestamps[$containerNumber] = microtime(true);
                
                return $this->createMockEDO($container);
            });
        
        // Execute batch generation
        $session = $this->batchService->generateEDOsForContainers(
            $containers,
            $expirationDate,
            $manifest,
            $user
        );
        
        // Assert: Each container starts after the previous one completes
        for ($i = 0; $i < $containerCount - 1; $i++) {
            $currentContainer = $containers[$i]->getContainerNumber();
            $nextContainer = $containers[$i + 1]->getContainerNumber();
            
            $currentCompletion = $completionTimestamps[$currentContainer];
            $nextStart = $startTimestamps[$nextContainer];
            
            $this->assertLessThanOrEqual(
                $nextStart,
                $currentCompletion,
                "Container {$nextContainer} must not start before {$currentContainer} completes (iteration {$iteration})"
            );
        }
    }

    // Helper methods for creating mock entities

    private function createMockManifest(int $seed): Manifest
    {
        $manifest = new Manifest();
        
        // Use reflection to set ID
        $reflection = new \ReflectionClass($manifest);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($manifest, $seed);
        
        // Create and set NOA
        $noa = $this->createMockNOA($seed);
        $manifest->setNoa($noa);
        
        return $manifest;
    }

    private function createMockNOA(int $seed): NOA
    {
        $noa = new NOA();
        
        // Use reflection to set ID
        $reflection = new \ReflectionClass($noa);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($noa, $seed);
        
        $noa->setCyLocation("CY Location {$seed}");
        
        return $noa;
    }

    private function createMockContainers(int $count, int $seed): array
    {
        $containers = [];
        
        for ($i = 0; $i < $count; $i++) {
            $container = new Container();
            
            // Use reflection to set ID
            $reflection = new \ReflectionClass($container);
            $idProperty = $reflection->getProperty('id');
            $idProperty->setAccessible(true);
            $idProperty->setValue($container, $seed * 1000 + $i);
            
            $containerNumber = 'TEST' . str_pad($seed * 1000 + $i, 7, '0', STR_PAD_LEFT);
            $container->setContainerNumber($containerNumber);
            
            // Set NOA for CY location validation
            $noa = $this->createMockNOA($seed);
            $container->setNoa($noa);
            
            $containers[] = $container;
        }
        
        return $containers;
    }

    private function createMockUser(int $seed): StaffUser
    {
        $user = new StaffUser();
        
        // Use reflection to set ID
        $reflection = new \ReflectionClass($user);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($user, $seed);
        
        $user->setEmail("test{$seed}@example.com");
        $user->setFirstName("Test");
        $user->setLastName("User {$seed}");
        
        return $user;
    }

    private function createMockEDO(Container $container): ElectronicDeliveryOrder
    {
        $edo = new ElectronicDeliveryOrder();
        
        // Use reflection to set ID
        $reflection = new \ReflectionClass($edo);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($edo, rand(1000, 9999));
        
        $edo->setEdoNumber('EDO' . date('YmdHis') . rand(1000, 9999));
        $edo->setContainer($container);
        $edo->setGeneratedAt(new \DateTime());
        
        return $edo;
    }
}
