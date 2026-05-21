<?php

namespace App\Tests\Performance;

use App\Entity\Container;
use App\Entity\Terminal;
use App\Entity\TerminalSlot;
use App\Entity\PreAdviceRequest;
use App\Entity\User;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\TerminalType;
use App\Entity\Enum\SlotStatus;
use App\Entity\Enum\PreAdviceStatus;
use App\Entity\Enum\UserRole;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Database Performance Benchmark for Terminal Team Pre-Advice System
 * 
 * Tests database query performance with large datasets and complex queries
 */
class DatabasePerformanceBenchmark extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private UserService $userService;

    // Benchmark configuration
    private const LARGE_DATASET_SIZE = 10000;
    private const QUERY_ITERATIONS = 100;
    private const MAX_QUERY_TIME_MS = 1000; // 1 second max per query

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->userService = $container->get(UserService::class);

        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->rollback();
        parent::tearDown();
    }

    /**
     * Benchmark container search queries with large dataset
     */
    public function testContainerSearchQueryPerformance(): void
    {
        echo "\n=== Container Search Query Benchmark ===\n";
        
        // Create large container dataset
        $this->createLargeContainerDataset();

        $queries = [
            'findByContainerNumber' => function($containerNumber) {
                return $this->entityManager->getRepository(Container::class)
                    ->findOneBy(['containerNumber' => $containerNumber]);
            },
            'findAvailableContainers' => function() {
                return $this->entityManager->getRepository(Container::class)
                    ->findBy(['status' => ContainerStatus::AVAILABLE_FOR_RETURN], null, 100);
            },
            'countContainersByStatus' => function() {
                return $this->entityManager->getRepository(Container::class)
                    ->createQueryBuilder('c')
                    ->select('c.status, COUNT(c.id) as count')
                    ->groupBy('c.status')
                    ->getQuery()
                    ->getResult();
            },
            'findContainersByDateRange' => function() {
                return $this->entityManager->getRepository(Container::class)
                    ->createQueryBuilder('c')
                    ->where('c.expectedReturnDate BETWEEN :start AND :end')
                    ->setParameter('start', new \DateTime())
                    ->setParameter('end', new \DateTime('+30 days'))
                    ->getQuery()
                    ->getResult();
            }
        ];

        foreach ($queries as $queryName => $queryFunction) {
            $this->benchmarkQuery($queryName, $queryFunction);
        }
    }

    /**
     * Benchmark terminal and slot queries
     */
    public function testTerminalSlotQueryPerformance(): void
    {
        echo "\n=== Terminal Slot Query Benchmark ===\n";
        
        // Create terminals with large slot datasets
        $this->createTerminalsWithLargeSlotDataset();

        $queries = [
            'findAvailableSlotsByTerminal' => function() {
                $terminal = $this->entityManager->getRepository(Terminal::class)->findOneBy([]);
                return $this->entityManager->getRepository(TerminalSlot::class)
                    ->createQueryBuilder('ts')
                    ->where('ts.terminal = :terminal')
                    ->andWhere('ts.status = :status')
                    ->andWhere('ts.assignedCount < ts.capacity')
                    ->setParameter('terminal', $terminal)
                    ->setParameter('status', SlotStatus::AVAILABLE)
                    ->getQuery()
                    ->getResult();
            },
            'getTerminalUtilizationStats' => function() {
                return $this->entityManager->getRepository(TerminalSlot::class)
                    ->createQueryBuilder('ts')
                    ->select('t.name, AVG(ts.assignedCount / ts.capacity * 100) as utilization')
                    ->join('ts.terminal', 't')
                    ->where('ts.date >= :today')
                    ->setParameter('today', new \DateTime())
                    ->groupBy('t.id')
                    ->getQuery()
                    ->getResult();
            },
            'findSlotsByDateRange' => function() {
                return $this->entityManager->getRepository(TerminalSlot::class)
                    ->createQueryBuilder('ts')
                    ->where('ts.date BETWEEN :start AND :end')
                    ->setParameter('start', new \DateTime())
                    ->setParameter('end', new \DateTime('+7 days'))
                    ->orderBy('ts.date', 'ASC')
                    ->getQuery()
                    ->getResult();
            }
        ];

        foreach ($queries as $queryName => $queryFunction) {
            $this->benchmarkQuery($queryName, $queryFunction);
        }
    }

    /**
     * Benchmark pre-advice request queries
     */
    public function testPreAdviceRequestQueryPerformance(): void
    {
        echo "\n=== Pre-Advice Request Query Benchmark ===\n";
        
        // Create large pre-advice dataset
        $this->createLargePreAdviceDataset();

        $queries = [
            'findPendingPreAdviceRequests' => function() {
                return $this->entityManager->getRepository(PreAdviceRequest::class)
                    ->findBy(['status' => PreAdviceStatus::PENDING], ['createdAt' => 'DESC'], 50);
            },
            'getPreAdviceStatsByStatus' => function() {
                return $this->entityManager->getRepository(PreAdviceRequest::class)
                    ->createQueryBuilder('pa')
                    ->select('pa.status, COUNT(pa.id) as count')
                    ->groupBy('pa.status')
                    ->getQuery()
                    ->getResult();
            },
            'findPreAdviceWithJoins' => function() {
                return $this->entityManager->getRepository(PreAdviceRequest::class)
                    ->createQueryBuilder('pa')
                    ->join('pa.trucker', 't')
                    ->join('pa.container', 'c')
                    ->join('pa.selectedTerminal', 'term')
                    ->where('pa.status = :status')
                    ->setParameter('status', PreAdviceStatus::PENDING)
                    ->setMaxResults(100)
                    ->getQuery()
                    ->getResult();
            },
            'getPreAdviceMetrics' => function() {
                return $this->entityManager->getRepository(PreAdviceRequest::class)
                    ->createQueryBuilder('pa')
                    ->select('
                        COUNT(pa.id) as total,
                        SUM(CASE WHEN pa.status = :pending THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN pa.status = :verified THEN 1 ELSE 0 END) as verified,
                        SUM(CASE WHEN pa.status = :rejected THEN 1 ELSE 0 END) as rejected
                    ')
                    ->setParameter('pending', PreAdviceStatus::PENDING)
                    ->setParameter('verified', PreAdviceStatus::VERIFIED)
                    ->setParameter('rejected', PreAdviceStatus::REJECTED)
                    ->getQuery()
                    ->getSingleResult();
            }
        ];

        foreach ($queries as $queryName => $queryFunction) {
            $this->benchmarkQuery($queryName, $queryFunction);
        }
    }

    /**
     * Benchmark bulk insert operations
     */
    public function testBulkInsertPerformance(): void
    {
        echo "\n=== Bulk Insert Performance Benchmark ===\n";

        $batchSizes = [10, 50, 100, 500];
        
        foreach ($batchSizes as $batchSize) {
            $startTime = microtime(true);
            
            for ($i = 1; $i <= $batchSize; $i++) {
                $container = new Container();
                $container->setContainerNumber(sprintf('BULK%09d', $i));
                $container->setSize('40ft');
                $container->setType('Dry');
                $container->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN);
                $container->setCurrentLocation('Bulk Insert Location');
                $container->setExpectedReturnDate(new \DateTime('+7 days'));
                $container->setCreatedAt(new \DateTime());
                $container->setUpdatedAt(new \DateTime());

                $this->entityManager->persist($container);
            }

            $this->entityManager->flush();
            $this->entityManager->clear();

            $endTime = microtime(true);
            $executionTime = ($endTime - $startTime) * 1000;
            $throughput = $batchSize / ($executionTime / 1000);

            echo "Batch Size {$batchSize}: {$executionTime:.2f}ms ({$throughput:.2f} inserts/sec)\n";

            $this->assertLessThan(
                5000, // 5 seconds max for bulk operations
                $executionTime,
                "Bulk insert of {$batchSize} records took too long: {$executionTime}ms"
            );
        }
    }

    /**
     * Test database connection pool performance
     */
    public function testConnectionPoolPerformance(): void
    {
        echo "\n=== Database Connection Pool Benchmark ===\n";

        $connectionTests = 100;
        $startTime = microtime(true);

        for ($i = 1; $i <= $connectionTests; $i++) {
            // Execute a simple query to test connection performance
            $result = $this->entityManager->getConnection()
                ->executeQuery('SELECT 1 as test')
                ->fetchAssociative();

            $this->assertEquals(1, $result['test']);
        }

        $endTime = microtime(true);
        $totalTime = ($endTime - $startTime) * 1000;
        $avgTime = $totalTime / $connectionTests;

        echo "Connection Tests: {$connectionTests}\n";
        echo "Total Time: {$totalTime:.2f}ms\n";
        echo "Average Time per Connection: {$avgTime:.2f}ms\n";

        $this->assertLessThan(
            10, // 10ms max average connection time
            $avgTime,
            "Database connection time too slow: {$avgTime}ms average"
        );
    }

    private function benchmarkQuery(string $queryName, callable $queryFunction): void
    {
        $times = [];
        
        // Warm up
        $queryFunction();

        // Run benchmark iterations
        for ($i = 0; $i < self::QUERY_ITERATIONS; $i++) {
            $startTime = microtime(true);
            $result = $queryFunction();
            $endTime = microtime(true);
            
            $times[] = ($endTime - $startTime) * 1000; // Convert to milliseconds
        }

        $avgTime = array_sum($times) / count($times);
        $minTime = min($times);
        $maxTime = max($times);

        echo "{$queryName}:\n";
        echo "  Average: {$avgTime:.2f}ms\n";
        echo "  Min: {$minTime:.2f}ms\n";
        echo "  Max: {$maxTime:.2f}ms\n";

        $this->assertLessThan(
            self::MAX_QUERY_TIME_MS,
            $avgTime,
            "Query '{$queryName}' average time {$avgTime}ms exceeds {self::MAX_QUERY_TIME_MS}ms threshold"
        );
    }

    private function createLargeContainerDataset(): void
    {
        echo "Creating large container dataset ({self::LARGE_DATASET_SIZE} records)...\n";
        
        for ($i = 1; $i <= self::LARGE_DATASET_SIZE; $i++) {
            $container = new Container();
            $container->setContainerNumber(sprintf('PERF%09d', $i));
            $container->setSize($i % 2 === 0 ? '40ft' : '20ft');
            $container->setType($i % 3 === 0 ? 'Reefer' : 'Dry');
            $container->setStatus($i % 4 === 0 ? ContainerStatus::IN_TRANSIT : ContainerStatus::AVAILABLE_FOR_RETURN);
            $container->setCurrentLocation('Performance Location ' . ($i % 10));
            $container->setExpectedReturnDate(new \DateTime('+' . ($i % 30) . ' days'));
            $container->setCreatedAt(new \DateTime('-' . ($i % 365) . ' days'));
            $container->setUpdatedAt(new \DateTime());

            $this->entityManager->persist($container);

            if ($i % 1000 === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                echo "  Created {$i} containers...\n";
            }
        }

        $this->entityManager->flush();
        echo "Container dataset creation completed.\n";
    }

    private function createTerminalsWithLargeSlotDataset(): void
    {
        echo "Creating terminals with large slot dataset...\n";
        
        $terminalTypes = [TerminalType::CY, TerminalType::ATI, TerminalType::ICTSI];

        foreach ($terminalTypes as $index => $type) {
            $terminal = new Terminal();
            $terminal->setName($type->value . ' Performance Terminal');
            $terminal->setType($type);
            $terminal->setLocation('Performance Location ' . $index);
            $terminal->setDailyCapacity(1000);
            $terminal->setIsActive(true);
            $terminal->setCreatedAt(new \DateTime());
            $terminal->setUpdatedAt(new \DateTime());

            $this->entityManager->persist($terminal);

            // Create slots for 365 days
            for ($day = -180; $day < 185; $day++) {
                $slot = new TerminalSlot();
                $slot->setTerminal($terminal);
                $slot->setDate(new \DateTime(($day >= 0 ? '+' : '') . $day . ' days'));
                $slot->setCapacity(1000);
                $slot->setAssignedCount(rand(0, 900));
                $slot->setStatus($slot->getAssignedCount() >= 1000 ? SlotStatus::FULL : SlotStatus::AVAILABLE);
                $slot->setCreatedAt(new \DateTime());

                $this->entityManager->persist($slot);
            }

            $this->entityManager->flush();
            echo "  Created terminal {$type->value} with 365 slots\n";
        }

        echo "Terminal and slot dataset creation completed.\n";
    }

    private function createLargePreAdviceDataset(): void
    {
        echo "Creating large pre-advice dataset...\n";

        // Create test trucker
        $trucker = $this->userService->createUser([
            'email' => 'benchmark.trucker@test.com',
            'password' => 'SecurePass123!',
            'firstName' => 'Benchmark',
            'lastName' => 'Trucker',
            'phoneNumber' => '555-BENCH'
        ], UserRole::TRUCKER);

        // Get terminal
        $terminal = $this->entityManager->getRepository(Terminal::class)->findOneBy([]);

        for ($i = 1; $i <= 5000; $i++) {
            $container = new Container();
            $container->setContainerNumber(sprintf('PREQ%09d', $i));
            $container->setSize('40ft');
            $container->setType('Dry');
            $container->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN);
            $container->setCurrentLocation('Pre-Advice Location');
            $container->setExpectedReturnDate(new \DateTime('+7 days'));
            $container->setCreatedAt(new \DateTime());
            $container->setUpdatedAt(new \DateTime());

            $this->entityManager->persist($container);

            $preAdvice = new PreAdviceRequest();
            $preAdvice->setTrucker($trucker);
            $preAdvice->setContainer($container);
            $preAdvice->setSelectedTerminal($terminal);
            $preAdvice->setStatus($this->getRandomPreAdviceStatus());
            $preAdvice->setPaymentReference('BENCH_PAY_' . $i);
            $preAdvice->setCreatedAt(new \DateTime('-' . rand(1, 90) . ' days'));
            $preAdvice->setUpdatedAt(new \DateTime());

            $this->entityManager->persist($preAdvice);

            if ($i % 500 === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                
                // Re-fetch entities
                $trucker = $this->entityManager->find(User::class, $trucker->getId());
                $terminal = $this->entityManager->find(Terminal::class, $terminal->getId());
                
                echo "  Created {$i} pre-advice requests...\n";
            }
        }

        $this->entityManager->flush();
        echo "Pre-advice dataset creation completed.\n";
    }

    private function getRandomPreAdviceStatus(): PreAdviceStatus
    {
        $statuses = [
            PreAdviceStatus::PENDING,
            PreAdviceStatus::VERIFIED,
            PreAdviceStatus::REJECTED,
            PreAdviceStatus::COMPLETED
        ];

        return $statuses[array_rand($statuses)];
    }
}