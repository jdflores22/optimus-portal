<?php

namespace App\Tests\Performance;

use App\Entity\User;
use App\Entity\Container;
use App\Entity\Terminal;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\TerminalType;
use App\Entity\Enum\ContainerStatus;
use App\Service\UserService;
use App\Service\PreAdviceService;
use App\Service\PhotoVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Load Testing Script for Terminal Team Pre-Advice System
 * 
 * This script can be run independently to perform stress testing
 * with configurable parameters for concurrent users and operations.
 */
class LoadTestScript extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private UserService $userService;
    private PreAdviceService $preAdviceService;
    private PhotoVerificationService $photoVerificationService;

    // Load test configuration
    private const CONCURRENT_USERS = 100;
    private const OPERATIONS_PER_USER = 10;
    private const TEST_DURATION_SECONDS = 300; // 5 minutes
    private const RAMP_UP_TIME_SECONDS = 60; // 1 minute

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->userService = $container->get(UserService::class);
        $this->preAdviceService = $container->get(PreAdviceService::class);
        $this->photoVerificationService = $container->get(PhotoVerificationService::class);

        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->rollback();
        parent::tearDown();
    }

    /**
     * Main load test execution
     */
    public function testLoadTestExecution(): void
    {
        echo "\n=== Terminal Team Pre-Advice Load Test ===\n";
        echo "Concurrent Users: " . self::CONCURRENT_USERS . "\n";
        echo "Operations per User: " . self::OPERATIONS_PER_USER . "\n";
        echo "Test Duration: " . self::TEST_DURATION_SECONDS . " seconds\n";
        echo "Ramp-up Time: " . self::RAMP_UP_TIME_SECONDS . " seconds\n\n";

        // Prepare test data
        $this->prepareTestData();

        // Execute load test
        $results = $this->executeLoadTest();

        // Report results
        $this->reportResults($results);

        // Validate performance criteria
        $this->validatePerformanceCriteria($results);
    }

    private function prepareTestData(): void
    {
        echo "Preparing test data...\n";

        // Create test containers
        for ($i = 1; $i <= self::CONCURRENT_USERS * 2; $i++) {
            $container = new Container();
            $container->setContainerNumber(sprintf('LOAD%09d', $i));
            $container->setSize($i % 2 === 0 ? '40ft' : '20ft');
            $container->setType('Dry');
            $container->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN);
            $container->setCurrentLocation('Load Test Location');
            $container->setExpectedReturnDate(new \DateTime('+7 days'));
            $container->setCreatedAt(new \DateTime());
            $container->setUpdatedAt(new \DateTime());

            $this->entityManager->persist($container);

            if ($i % 100 === 0) {
                $this->entityManager->flush();
                echo "Created {$i} containers...\n";
            }
        }

        // Create test terminal
        $terminal = new Terminal();
        $terminal->setName('Load Test Terminal');
        $terminal->setType(TerminalType::CY);
        $terminal->setLocation('Load Test Location');
        $terminal->setDailyCapacity(10000);
        $terminal->setIsActive(true);
        $terminal->setCreatedAt(new \DateTime());
        $terminal->setUpdatedAt(new \DateTime());

        $this->entityManager->persist($terminal);

        // Create test truckers
        for ($i = 1; $i <= self::CONCURRENT_USERS; $i++) {
            $trucker = $this->userService->createUser([
                'email' => "loadtest{$i}@performance.test",
                'password' => 'SecurePass123!',
                'firstName' => "LoadTest{$i}",
                'lastName' => 'User',
                'phoneNumber' => '555-' . str_pad($i, 4, '0', STR_PAD_LEFT)
            ], UserRole::TRUCKER);

            if ($i % 50 === 0) {
                echo "Created {$i} truckers...\n";
            }
        }

        $this->entityManager->flush();
        echo "Test data preparation completed.\n\n";
    }

    private function executeLoadTest(): array
    {
        echo "Starting load test execution...\n";

        $startTime = microtime(true);
        $results = [
            'totalOperations' => 0,
            'successfulOperations' => 0,
            'failedOperations' => 0,
            'responseTimes' => [],
            'errors' => [],
            'throughput' => 0,
            'averageResponseTime' => 0,
            'maxResponseTime' => 0,
            'minResponseTime' => PHP_FLOAT_MAX
        ];

        // Simulate concurrent users with staggered start times
        $userStartInterval = self::RAMP_UP_TIME_SECONDS / self::CONCURRENT_USERS;

        for ($user = 1; $user <= self::CONCURRENT_USERS; $user++) {
            // Simulate ramp-up by adding delay
            if ($user > 1) {
                usleep($userStartInterval * 1000000 / self::CONCURRENT_USERS);
            }

            // Execute operations for this user
            for ($operation = 1; $operation <= self::OPERATIONS_PER_USER; $operation++) {
                $operationStartTime = microtime(true);
                
                try {
                    $this->executePreAdviceOperation($user, $operation);
                    $results['successfulOperations']++;
                } catch (\Exception $e) {
                    $results['failedOperations']++;
                    $results['errors'][] = $e->getMessage();
                }

                $operationEndTime = microtime(true);
                $responseTime = $operationEndTime - $operationStartTime;
                $results['responseTimes'][] = $responseTime;
                $results['totalOperations']++;

                // Update min/max response times
                $results['maxResponseTime'] = max($results['maxResponseTime'], $responseTime);
                $results['minResponseTime'] = min($results['minResponseTime'], $responseTime);

                // Check if test duration exceeded
                if ((microtime(true) - $startTime) > self::TEST_DURATION_SECONDS) {
                    break 2;
                }
            }

            // Progress reporting
            if ($user % 10 === 0) {
                $elapsed = microtime(true) - $startTime;
                echo "Completed {$user} users, {$results['totalOperations']} operations in {$elapsed:.2f}s\n";
            }
        }

        $totalTime = microtime(true) - $startTime;
        $results['throughput'] = $results['totalOperations'] / $totalTime;
        $results['averageResponseTime'] = array_sum($results['responseTimes']) / count($results['responseTimes']);

        return $results;
    }

    private function executePreAdviceOperation(int $userId, int $operationId): void
    {
        // Get test data
        $trucker = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => "loadtest{$userId}@performance.test"]);
        
        $container = $this->entityManager->getRepository(Container::class)
            ->findOneBy(['containerNumber' => sprintf('LOAD%09d', ($userId - 1) * self::OPERATIONS_PER_USER + $operationId)]);
        
        $terminal = $this->entityManager->getRepository(Terminal::class)
            ->findOneBy(['name' => 'Load Test Terminal']);

        if (!$trucker || !$container || !$terminal) {
            throw new \Exception("Test data not found for user {$userId}, operation {$operationId}");
        }

        // Create mock photo
        $photo = $this->createMockGeotagPhoto();

        // Submit pre-advice
        $preAdvice = $this->preAdviceService->submitPreAdvice(
            $trucker,
            $container,
            $terminal,
            [$photo],
            "LOAD_TEST_{$userId}_{$operationId}"
        );

        // Verify the operation was successful
        if (!$preAdvice->getId()) {
            throw new \Exception("Failed to create pre-advice for user {$userId}, operation {$operationId}");
        }
    }

    private function createMockGeotagPhoto(): \App\Entity\GeotagPhoto
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'load_test_photo');
        file_put_contents($tempFile, 'mock photo content');
        
        $uploadedFile = new UploadedFile(
            $tempFile,
            'load_test_photo.jpg',
            'image/jpeg',
            null,
            true
        );

        return $this->photoVerificationService->processGeotagPhoto(
            $uploadedFile,
            40.7128, // NYC coordinates
            -74.0060
        );
    }

    private function reportResults(array $results): void
    {
        echo "\n=== Load Test Results ===\n";
        echo "Total Operations: {$results['totalOperations']}\n";
        echo "Successful Operations: {$results['successfulOperations']}\n";
        echo "Failed Operations: {$results['failedOperations']}\n";
        echo "Success Rate: " . round(($results['successfulOperations'] / $results['totalOperations']) * 100, 2) . "%\n";
        echo "Throughput: " . round($results['throughput'], 2) . " operations/second\n";
        echo "Average Response Time: " . round($results['averageResponseTime'] * 1000, 2) . " ms\n";
        echo "Min Response Time: " . round($results['minResponseTime'] * 1000, 2) . " ms\n";
        echo "Max Response Time: " . round($results['maxResponseTime'] * 1000, 2) . " ms\n";

        // Calculate percentiles
        sort($results['responseTimes']);
        $count = count($results['responseTimes']);
        $p50 = $results['responseTimes'][intval($count * 0.5)] * 1000;
        $p95 = $results['responseTimes'][intval($count * 0.95)] * 1000;
        $p99 = $results['responseTimes'][intval($count * 0.99)] * 1000;

        echo "50th Percentile: " . round($p50, 2) . " ms\n";
        echo "95th Percentile: " . round($p95, 2) . " ms\n";
        echo "99th Percentile: " . round($p99, 2) . " ms\n";

        if (!empty($results['errors'])) {
            echo "\nErrors encountered:\n";
            $errorCounts = array_count_values($results['errors']);
            foreach ($errorCounts as $error => $count) {
                echo "- {$error}: {$count} times\n";
            }
        }

        echo "\n";
    }

    private function validatePerformanceCriteria(array $results): void
    {
        echo "=== Performance Validation ===\n";

        $criteria = [
            'Success Rate >= 95%' => ($results['successfulOperations'] / $results['totalOperations']) >= 0.95,
            'Throughput >= 10 ops/sec' => $results['throughput'] >= 10,
            'Average Response Time <= 5s' => $results['averageResponseTime'] <= 5.0,
            'Max Response Time <= 30s' => $results['maxResponseTime'] <= 30.0,
        ];

        $allPassed = true;
        foreach ($criteria as $criterion => $passed) {
            $status = $passed ? 'PASS' : 'FAIL';
            echo "{$criterion}: {$status}\n";
            if (!$passed) {
                $allPassed = false;
            }
        }

        if ($allPassed) {
            echo "\n✅ All performance criteria passed!\n";
        } else {
            echo "\n❌ Some performance criteria failed!\n";
        }

        $this->assertTrue($allPassed, 'Performance criteria validation failed');
    }
}