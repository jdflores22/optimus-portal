<?php

namespace App\Tests\Unit\Service;

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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit Test: Sequential Container Processing
 * 
 * Tests specific examples of sequential processing behavior in batch eDO generation.
 * Complements property-based tests with concrete scenarios.
 */
class BatchEDOSequentialProcessingTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private EDOGenerationServiceInterface $edoGenerationService;
    private EDOAuditServiceInterface $auditService;
    private LoggerInterface $logger;
    private BatchEDOGenerationService $batchService;
    private array $processingLog = [];

    protected function setUp(): void
    {
        // Create mocks
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->edoGenerationService = $this->createMock(EDOGenerationServiceInterface::class);
        $this->auditService = $this->createMock(EDOAuditServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        // Reset processing log
        $this->processingLog = [];
        
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
     * Test: Containers are processed in the exact order provided
     */
    public function testContainersProcessedInOrder(): void
    {
        // Arrange
        $manifest = $this->createManifest(1);
        $containers = [
            $this->createContainer('CONT0000001'),
            $this->createContainer('CONT0000002'),
            $this->createContainer('CONT0000003')
        ];
        $user = $this->createUser(1);
        $expirationDate = new \DateTime('+30 days');
        
        // Track processing order
        $this->edoGenerationService
            ->method('generateEDOForContainer')
            ->willReturnCallback(function ($container) {
                $this->processingLog[] = [
                    'action' => 'start',
                    'container' => $container->getContainerNumber(),
                    'timestamp' => microtime(true)
                ];
                
                usleep(1000); // Simulate processing time
                
                $this->processingLog[] = [
                    'action' => 'complete',
                    'container' => $container->getContainerNumber(),
                    'timestamp' => microtime(true)
                ];
                
                return $this->createEDO($container);
            });
        
        // Act
        $session = $this->batchService->generateEDOsForContainers(
            $containers,
            $expirationDate,
            $manifest,
            $user
        );
        
        // Assert: Processing order matches input order
        $startEvents = array_filter($this->processingLog, fn($e) => $e['action'] === 'start');
        $startOrder = array_map(fn($e) => $e['container'], $startEvents);
        
        $this->assertEquals(['CONT0000001', 'CONT0000002', 'CONT0000003'], $startOrder);
    }

    /**
     * Test: Next container waits for previous completion
     */
    public function testNextContainerWaitsForPreviousCompletion(): void
    {
        // Arrange
        $manifest = $this->createManifest(1);
        $containers = [
            $this->createContainer('CONT0000001'),
            $this->createContainer('CONT0000002')
        ];
        $user = $this->createUser(1);
        $expirationDate = new \DateTime('+30 days');
        
        // Track processing events
        $this->edoGenerationService
            ->method('generateEDOForContainer')
            ->willReturnCallback(function ($container) {
                $this->processingLog[] = [
                    'action' => 'start',
                    'container' => $container->getContainerNumber(),
                    'timestamp' => microtime(true)
                ];
                
                usleep(2000); // 2ms processing time
                
                $this->processingLog[] = [
                    'action' => 'complete',
                    'container' => $container->getContainerNumber(),
                    'timestamp' => microtime(true)
                ];
                
                return $this->createEDO($container);
            });
        
        // Act
        $session = $this->batchService->generateEDOsForContainers(
            $containers,
            $expirationDate,
            $manifest,
            $user
        );
        
        // Assert: Container 2 starts after Container 1 completes
        $container1Complete = null;
        $container2Start = null;
        
        foreach ($this->processingLog as $event) {
            if ($event['container'] === 'CONT0000001' && $event['action'] === 'complete') {
                $container1Complete = $event['timestamp'];
            }
            if ($event['container'] === 'CONT0000002' && $event['action'] === 'start') {
                $container2Start = $event['timestamp'];
            }
        }
        
        $this->assertNotNull($container1Complete);
        $this->assertNotNull($container2Start);
        $this->assertLessThanOrEqual($container2Start, $container1Complete);
    }

    /**
     * Test: Sequential processing continues after failure
     */
    public function testSequentialProcessingContinuesAfterFailure(): void
    {
        // Arrange
        $manifest = $this->createManifest(1);
        $containers = [
            $this->createContainer('CONT0000001'),
            $this->createContainer('CONT0000002'), // This will fail
            $this->createContainer('CONT0000003')
        ];
        $user = $this->createUser(1);
        $expirationDate = new \DateTime('+30 days');
        
        // Configure service to fail on second container
        $this->edoGenerationService
            ->method('generateEDOForContainer')
            ->willReturnCallback(function ($container) {
                $this->processingLog[] = [
                    'action' => 'start',
                    'container' => $container->getContainerNumber(),
                    'timestamp' => microtime(true)
                ];
                
                if ($container->getContainerNumber() === 'CONT0000002') {
                    $this->processingLog[] = [
                        'action' => 'failed',
                        'container' => $container->getContainerNumber(),
                        'timestamp' => microtime(true)
                    ];
                    throw new \RuntimeException('Simulated failure');
                }
                
                $this->processingLog[] = [
                    'action' => 'complete',
                    'container' => $container->getContainerNumber(),
                    'timestamp' => microtime(true)
                ];
                
                return $this->createEDO($container);
            });
        
        // Act
        $session = $this->batchService->generateEDOsForContainers(
            $containers,
            $expirationDate,
            $manifest,
            $user
        );
        
        // Assert: All three containers were attempted in order
        $startEvents = array_filter($this->processingLog, fn($e) => $e['action'] === 'start');
        $this->assertCount(3, $startEvents);
        
        $startOrder = array_map(fn($e) => $e['container'], $startEvents);
        $this->assertEquals(['CONT0000001', 'CONT0000002', 'CONT0000003'], $startOrder);
        
        // Assert: Container 3 was processed after Container 2 failed
        $container2Failed = null;
        $container3Start = null;
        
        foreach ($this->processingLog as $event) {
            if ($event['container'] === 'CONT0000002' && $event['action'] === 'failed') {
                $container2Failed = $event['timestamp'];
            }
            if ($event['container'] === 'CONT0000003' && $event['action'] === 'start') {
                $container3Start = $event['timestamp'];
            }
        }
        
        $this->assertNotNull($container2Failed);
        $this->assertNotNull($container3Start);
        $this->assertLessThanOrEqual($container3Start, $container2Failed);
    }

    /**
     * Test: Single container batch processes correctly
     */
    public function testSingleContainerBatchProcessesCorrectly(): void
    {
        // Arrange
        $manifest = $this->createManifest(1);
        $containers = [
            $this->createContainer('CONT0000001')
        ];
        $user = $this->createUser(1);
        $expirationDate = new \DateTime('+30 days');
        
        $this->edoGenerationService
            ->method('generateEDOForContainer')
            ->willReturnCallback(function ($container) {
                $this->processingLog[] = [
                    'action' => 'start',
                    'container' => $container->getContainerNumber()
                ];
                return $this->createEDO($container);
            });
        
        // Act
        $session = $this->batchService->generateEDOsForContainers(
            $containers,
            $expirationDate,
            $manifest,
            $user
        );
        
        // Assert: Single container was processed
        $this->assertCount(1, $this->processingLog);
        $this->assertEquals('CONT0000001', $this->processingLog[0]['container']);
    }

    /**
     * Test: Large batch maintains sequential order
     */
    public function testLargeBatchMaintainsSequentialOrder(): void
    {
        // Arrange
        $manifest = $this->createManifest(1);
        $containerCount = 20;
        $containers = [];
        $expectedOrder = [];
        
        for ($i = 1; $i <= $containerCount; $i++) {
            $containerNumber = 'CONT' . str_pad($i, 7, '0', STR_PAD_LEFT);
            $containers[] = $this->createContainer($containerNumber);
            $expectedOrder[] = $containerNumber;
        }
        
        $user = $this->createUser(1);
        $expirationDate = new \DateTime('+30 days');
        
        $this->edoGenerationService
            ->method('generateEDOForContainer')
            ->willReturnCallback(function ($container) {
                $this->processingLog[] = $container->getContainerNumber();
                return $this->createEDO($container);
            });
        
        // Act
        $session = $this->batchService->generateEDOsForContainers(
            $containers,
            $expirationDate,
            $manifest,
            $user
        );
        
        // Assert: All 20 containers processed in exact order
        $this->assertEquals($expectedOrder, $this->processingLog);
    }

    /**
     * Test: Mixed success and failure maintains order
     */
    public function testMixedSuccessAndFailureMaintainsOrder(): void
    {
        // Arrange
        $manifest = $this->createManifest(1);
        $containers = [
            $this->createContainer('CONT0000001'), // Success
            $this->createContainer('CONT0000002'), // Fail
            $this->createContainer('CONT0000003'), // Success
            $this->createContainer('CONT0000004'), // Fail
            $this->createContainer('CONT0000005')  // Success
        ];
        $user = $this->createUser(1);
        $expirationDate = new \DateTime('+30 days');
        
        $failingContainers = ['CONT0000002', 'CONT0000004'];
        
        $this->edoGenerationService
            ->method('generateEDOForContainer')
            ->willReturnCallback(function ($container) use ($failingContainers) {
                $this->processingLog[] = $container->getContainerNumber();
                
                if (in_array($container->getContainerNumber(), $failingContainers)) {
                    throw new \RuntimeException('Simulated failure');
                }
                
                return $this->createEDO($container);
            });
        
        // Act
        $session = $this->batchService->generateEDOsForContainers(
            $containers,
            $expirationDate,
            $manifest,
            $user
        );
        
        // Assert: All containers attempted in order
        $this->assertEquals(
            ['CONT0000001', 'CONT0000002', 'CONT0000003', 'CONT0000004', 'CONT0000005'],
            $this->processingLog
        );
    }

    // Helper methods

    private function createManifest(int $id): Manifest
    {
        $manifest = new Manifest();
        
        $reflection = new \ReflectionClass($manifest);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($manifest, $id);
        
        $noa = $this->createNOA($id);
        $manifest->setNoa($noa);
        
        return $manifest;
    }

    private function createNOA(int $id): NOA
    {
        $noa = new NOA();
        
        $reflection = new \ReflectionClass($noa);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($noa, $id);
        
        $noa->setPortLocation("CY Location {$id}");
        
        return $noa;
    }

    private function createContainer(string $containerNumber): Container
    {
        $container = new Container();
        
        $reflection = new \ReflectionClass($container);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($container, rand(1, 10000));
        
        $container->setContainerNumber($containerNumber);
        
        $noa = $this->createNOA(1);
        $container->setNoa($noa);
        
        return $container;
    }

    private function createUser(int $id): StaffUser
    {
        $user = new StaffUser();
        
        $reflection = new \ReflectionClass($user);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($user, $id);
        
        $user->setEmail("test{$id}@example.com");
        $user->setFirstName("Test");
        $user->setLastName("User");
        
        return $user;
    }

    private function createEDO(Container $container): ElectronicDeliveryOrder
    {
        $edo = new ElectronicDeliveryOrder();
        
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
