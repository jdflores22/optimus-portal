<?php

namespace App\Tests\Service;

use App\Entity\Container;
use App\Entity\Terminal;
use App\Entity\TerminalSlot;
use App\Entity\PreAdviceRequest;
use App\Entity\Trucker;
use App\Entity\TerminalTeamUser;
use App\Entity\Enum\PreAdviceStatus;
use App\Entity\Enum\TerminalType;
use App\Entity\Enum\SlotStatus;
use App\Entity\Enum\ContainerStatus;
use App\Service\ReportingService;
use App\Repository\PreAdviceRequestRepository;
use App\Repository\TerminalRepository;
use App\Repository\TerminalSlotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Unit tests for ReportingService to verify report generation functionality
 */
class ReportingServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ReportingService $reportingService;
    private PreAdviceRequestRepository $preAdviceRequestRepository;
    private TerminalRepository $terminalRepository;
    private TerminalSlotRepository $terminalSlotRepository;

    protected function setUp(): void
    {
        parent::setUp();
        
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->preAdviceRequestRepository = $this->entityManager->getRepository(PreAdviceRequest::class);
        $this->terminalRepository = $this->entityManager->getRepository(Terminal::class);
        $this->terminalSlotRepository = $this->entityManager->getRepository(TerminalSlot::class);
        
        $this->reportingService = new ReportingService(
            $this->preAdviceRequestRepository,
            $this->terminalRepository,
            $this->terminalSlotRepository
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up database
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $connection->executeStatement('TRUNCATE TABLE pre_advice_requests');
        $connection->executeStatement('TRUNCATE TABLE terminal_slots');
        $connection->executeStatement('TRUNCATE TABLE terminals');
        $connection->executeStatement('TRUNCATE TABLE containers');
        $connection->executeStatement('TRUNCATE TABLE truckers');
        $connection->executeStatement('TRUNCATE TABLE terminal_team_users');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        
        $this->entityManager->close();
    }

    public function testGeneratePreAdviceStatisticsWithVariousStatuses(): void
    {
        // Create test data
        $terminal = $this->createTestTerminal('CY Terminal', TerminalType::CY);
        $trucker = $this->createTestTrucker('test@trucker.com');
        $container = $this->createTestContainer('CONT123456');
        $terminalTeamUser = $this->createTestTerminalTeamUser('team@terminal.com');

        // Create pre-advice requests with different statuses
        $this->createTestPreAdviceRequest($trucker, $container, $terminal, PreAdviceStatus::PENDING);
        $this->createTestPreAdviceRequest($trucker, $container, $terminal, PreAdviceStatus::VERIFIED, $terminalTeamUser);
        $this->createTestPreAdviceRequest($trucker, $container, $terminal, PreAdviceStatus::REJECTED, $terminalTeamUser);
        $this->createTestPreAdviceRequest($trucker, $container, $terminal, PreAdviceStatus::COMPLETED, $terminalTeamUser);

        $this->entityManager->flush();

        // Generate statistics
        $startDate = new \DateTime('-1 day');
        $endDate = new \DateTime('+1 day');
        $statistics = $this->reportingService->generatePreAdviceStatistics($startDate, $endDate);

        // Verify statistics structure
        $this->assertArrayHasKey('period', $statistics);
        $this->assertArrayHasKey('summary', $statistics);
        $this->assertArrayHasKey('approval_metrics', $statistics);
        $this->assertArrayHasKey('processing_times', $statistics);

        // Verify summary counts
        $summary = $statistics['summary'];
        $this->assertEquals(4, $summary['total_requests']);
        $this->assertEquals(1, $summary['pending_requests']);
        $this->assertEquals(1, $summary['verified_requests']);
        $this->assertEquals(1, $summary['rejected_requests']);
        $this->assertEquals(1, $summary['completed_requests']);

        // Verify approval metrics
        $approvalMetrics = $statistics['approval_metrics'];
        $this->assertEquals(3, $approvalMetrics['total_processed']); // verified + rejected + completed
        $this->assertEquals(2, $approvalMetrics['approved_count']); // verified + completed
        $this->assertEquals(1, $approvalMetrics['rejected_count']);
        $this->assertEquals(66.67, $approvalMetrics['approval_rate_percentage']); // 2/3 * 100
    }

    public function testGenerateTerminalUtilizationReport(): void
    {
        // Create test terminals
        $cyTerminal = $this->createTestTerminal('CY Terminal', TerminalType::CY, 100);
        $atiTerminal = $this->createTestTerminal('ATI Terminal', TerminalType::ATI, 50);
        
        $trucker = $this->createTestTrucker('test@trucker.com');
        $container = $this->createTestContainer('CONT123456');
        $terminalTeamUser = $this->createTestTerminalTeamUser('team@terminal.com');

        // Create requests for different terminals
        $this->createTestPreAdviceRequest($trucker, $container, $cyTerminal, PreAdviceStatus::COMPLETED, $terminalTeamUser);
        $this->createTestPreAdviceRequest($trucker, $container, $cyTerminal, PreAdviceStatus::VERIFIED, $terminalTeamUser);
        $this->createTestPreAdviceRequest($trucker, $container, $atiTerminal, PreAdviceStatus::COMPLETED, $terminalTeamUser);

        $this->entityManager->flush();

        // Generate utilization report
        $startDate = new \DateTime('-1 day');
        $endDate = new \DateTime('+1 day');
        $utilization = $this->reportingService->generateTerminalUtilizationReport($startDate, $endDate);

        // Verify report structure
        $this->assertArrayHasKey('period', $utilization);
        $this->assertArrayHasKey('terminal_utilization', $utilization);
        $this->assertArrayHasKey('terminal_type_statistics', $utilization);

        // Verify terminal utilization data
        $terminalUtilization = $utilization['terminal_utilization'];
        $this->assertCount(2, $terminalUtilization);

        // Find CY terminal data
        $cyData = null;
        foreach ($terminalUtilization as $data) {
            if ($data['terminal_name'] === 'CY Terminal') {
                $cyData = $data;
                break;
            }
        }

        $this->assertNotNull($cyData);
        $this->assertEquals('CY', $cyData['terminal_type']);
        $this->assertEquals(2, $cyData['total_requests']);
        $this->assertEquals(2, $cyData['completed_requests']); // verified + completed
        $this->assertEquals(100, $cyData['daily_capacity']);
        $this->assertEquals(2.0, $cyData['utilization_rate_percentage']); // 2/100 * 100
    }

    public function testGenerateApprovalRateAnalytics(): void
    {
        // Create test data
        $terminal = $this->createTestTerminal('Test Terminal', TerminalType::CY);
        $trucker = $this->createTestTrucker('test@trucker.com');
        $container = $this->createTestContainer('CONT123456');
        $terminalTeamUser = $this->createTestTerminalTeamUser('team@terminal.com');

        // Create requests with different dates
        $request1 = $this->createTestPreAdviceRequest($trucker, $container, $terminal, PreAdviceStatus::VERIFIED, $terminalTeamUser);
        $request1->setCreatedAt(new \DateTime('-2 days'));
        
        $request2 = $this->createTestPreAdviceRequest($trucker, $container, $terminal, PreAdviceStatus::REJECTED, $terminalTeamUser);
        $request2->setCreatedAt(new \DateTime('-1 day'));

        $this->entityManager->flush();

        // Generate approval analytics
        $startDate = new \DateTime('-3 days');
        $endDate = new \DateTime('+1 day');
        $analytics = $this->reportingService->generateApprovalRateAnalytics($startDate, $endDate);

        // Verify analytics structure
        $this->assertArrayHasKey('period', $analytics);
        $this->assertArrayHasKey('overall_metrics', $analytics);
        $this->assertArrayHasKey('daily_trends', $analytics);

        // Verify overall metrics
        $overallMetrics = $analytics['overall_metrics'];
        $this->assertEquals(2, $overallMetrics['total_processed']);
        $this->assertEquals(1, $overallMetrics['approved_count']);
        $this->assertEquals(1, $overallMetrics['rejected_count']);
        $this->assertEquals(50.0, $overallMetrics['approval_rate_percentage']);

        // Verify daily trends exist
        $dailyTrends = $analytics['daily_trends'];
        $this->assertIsArray($dailyTrends);
        $this->assertGreaterThan(0, count($dailyTrends));
    }

    public function testGetQuickMetrics(): void
    {
        // Create test data for different time periods
        $terminal = $this->createTestTerminal('Test Terminal', TerminalType::CY);
        $trucker = $this->createTestTrucker('test@trucker.com');
        $container = $this->createTestContainer('CONT123456');

        // Today's request
        $todayRequest = $this->createTestPreAdviceRequest($trucker, $container, $terminal, PreAdviceStatus::PENDING);
        $todayRequest->setCreatedAt(new \DateTime('today'));

        // Week old request
        $weekRequest = $this->createTestPreAdviceRequest($trucker, $container, $terminal, PreAdviceStatus::VERIFIED);
        $weekRequest->setCreatedAt(new \DateTime('-5 days'));

        // Month old request
        $monthRequest = $this->createTestPreAdviceRequest($trucker, $container, $terminal, PreAdviceStatus::COMPLETED);
        $monthRequest->setCreatedAt(new \DateTime('-15 days'));

        $this->entityManager->flush();

        // Get quick metrics
        $metrics = $this->reportingService->getQuickMetrics();

        // Verify metrics structure
        $this->assertArrayHasKey('today', $metrics);
        $this->assertArrayHasKey('last_7_days', $metrics);
        $this->assertArrayHasKey('last_30_days', $metrics);

        // Verify today's metrics
        $today = $metrics['today'];
        $this->assertEquals(1, $today['total_requests']);
        $this->assertEquals(1, $today['pending_requests']);

        // Verify 7-day metrics include today and week requests
        $lastWeek = $metrics['last_7_days'];
        $this->assertEquals(2, $lastWeek['total_requests']);

        // Verify 30-day metrics include all requests
        $lastMonth = $metrics['last_30_days'];
        $this->assertEquals(3, $lastMonth['total_requests']);
    }

    public function testGenerateDashboardMetrics(): void
    {
        // Create minimal test data
        $terminal = $this->createTestTerminal('Test Terminal', TerminalType::CY);
        $trucker = $this->createTestTrucker('test@trucker.com');
        $container = $this->createTestContainer('CONT123456');

        $this->createTestPreAdviceRequest($trucker, $container, $terminal, PreAdviceStatus::PENDING);

        $this->entityManager->flush();

        // Generate dashboard metrics
        $startDate = new \DateTime('-1 day');
        $endDate = new \DateTime('+1 day');
        $dashboardMetrics = $this->reportingService->generateDashboardMetrics($startDate, $endDate);

        // Verify comprehensive structure
        $this->assertArrayHasKey('generated_at', $dashboardMetrics);
        $this->assertArrayHasKey('period', $dashboardMetrics);
        $this->assertArrayHasKey('pre_advice_statistics', $dashboardMetrics);
        $this->assertArrayHasKey('terminal_utilization', $dashboardMetrics);
        $this->assertArrayHasKey('approval_analytics', $dashboardMetrics);

        // Verify generated timestamp format
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $dashboardMetrics['generated_at']);
    }

    // Helper methods for creating test data

    private function createTestTerminal(string $name, TerminalType $type, int $capacity = 50): Terminal
    {
        $terminal = new Terminal();
        $terminal->setName($name);
        $terminal->setType($type);
        $terminal->setLocation('Test Location');
        $terminal->setDailyCapacity($capacity);
        $terminal->setIsActive(true);

        $this->entityManager->persist($terminal);
        return $terminal;
    }

    private function createTestTrucker(string $email): Trucker
    {
        $trucker = new Trucker();
        $trucker->setEmail($email);
        $trucker->setPasswordHash('hashed_password');
        $trucker->setCompanyName('Test Trucking Co');
        $trucker->setContactPerson('Test Driver');
        $trucker->setPhoneNumber('+1234567890');

        $this->entityManager->persist($trucker);
        return $trucker;
    }

    private function createTestContainer(string $containerNumber): Container
    {
        $container = new Container();
        $container->setContainerNumber($containerNumber);
        $container->setSize('40ft');
        $container->setType('Dry');
        $container->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN);
        $container->setCurrentLocation('Test Port');
        $container->setExpectedReturnDate(new \DateTime('+7 days'));

        $this->entityManager->persist($container);
        return $container;
    }

    private function createTestTerminalTeamUser(string $email): TerminalTeamUser
    {
        $terminalTeamUser = new TerminalTeamUser();
        $terminalTeamUser->setEmail($email);
        $terminalTeamUser->setPasswordHash('hashed_password');
        $terminalTeamUser->setFirstName('Terminal');
        $terminalTeamUser->setLastName('Team');

        $this->entityManager->persist($terminalTeamUser);
        return $terminalTeamUser;
    }

    private function createTestPreAdviceRequest(
        Trucker $trucker,
        Container $container,
        Terminal $terminal,
        PreAdviceStatus $status,
        ?TerminalTeamUser $verifiedBy = null
    ): PreAdviceRequest {
        $request = new PreAdviceRequest();
        $request->setTrucker($trucker);
        $request->setContainer($container);
        $request->setSelectedTerminal($terminal);
        $request->setStatus($status);
        $request->setPaymentReference('PAY' . uniqid());

        if ($verifiedBy && in_array($status, [PreAdviceStatus::VERIFIED, PreAdviceStatus::REJECTED, PreAdviceStatus::COMPLETED])) {
            $request->setVerifiedBy($verifiedBy);
            $request->setVerifiedAt(new \DateTime());
        }

        $this->entityManager->persist($request);
        return $request;
    }
}