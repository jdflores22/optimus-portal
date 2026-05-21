<?php

namespace App\Tests\Service;

use App\Service\ReportingService;
use App\Repository\PreAdviceRequestRepository;
use App\Repository\TerminalRepository;
use App\Repository\TerminalSlotRepository;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Basic unit tests for ReportingService without database dependencies
 */
class ReportingServiceBasicTest extends TestCase
{
    private ReportingService $reportingService;
    private MockObject $preAdviceRequestRepository;
    private MockObject $terminalRepository;
    private MockObject $terminalSlotRepository;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->preAdviceRequestRepository = $this->createMock(PreAdviceRequestRepository::class);
        $this->terminalRepository = $this->createMock(TerminalRepository::class);
        $this->terminalSlotRepository = $this->createMock(TerminalSlotRepository::class);
        
        $this->reportingService = new ReportingService(
            $this->preAdviceRequestRepository,
            $this->terminalRepository,
            $this->terminalSlotRepository
        );
    }

    public function testGeneratePreAdviceStatisticsStructure(): void
    {
        // Mock repository responses
        $this->preAdviceRequestRepository
            ->expects($this->once())
            ->method('getStatistics')
            ->willReturn([
                'total_requests' => 10,
                'pending_requests' => 2,
                'verified_requests' => 5,
                'rejected_requests' => 2,
                'completed_requests' => 1
            ]);

        $this->preAdviceRequestRepository
            ->expects($this->once())
            ->method('getApprovalRates')
            ->willReturn([
                'total_processed' => 8,
                'approved_count' => 6,
                'rejected_count' => 2
            ]);

        $this->preAdviceRequestRepository
            ->expects($this->once())
            ->method('getProcessingTimeStats')
            ->willReturn([
                'avg_processing_hours' => 24.5,
                'min_processing_hours' => 2.0,
                'max_processing_hours' => 72.0
            ]);

        // Generate statistics
        $startDate = new \DateTime('-1 day');
        $endDate = new \DateTime('+1 day');
        $statistics = $this->reportingService->generatePreAdviceStatistics($startDate, $endDate);

        // Verify structure
        $this->assertArrayHasKey('period', $statistics);
        $this->assertArrayHasKey('summary', $statistics);
        $this->assertArrayHasKey('approval_metrics', $statistics);
        $this->assertArrayHasKey('processing_times', $statistics);

        // Verify period
        $this->assertEquals($startDate->format('Y-m-d'), $statistics['period']['start_date']);
        $this->assertEquals($endDate->format('Y-m-d'), $statistics['period']['end_date']);

        // Verify summary
        $summary = $statistics['summary'];
        $this->assertEquals(10, $summary['total_requests']);
        $this->assertEquals(2, $summary['pending_requests']);
        $this->assertEquals(5, $summary['verified_requests']);
        $this->assertEquals(2, $summary['rejected_requests']);
        $this->assertEquals(1, $summary['completed_requests']);

        // Verify approval metrics
        $approvalMetrics = $statistics['approval_metrics'];
        $this->assertEquals(8, $approvalMetrics['total_processed']);
        $this->assertEquals(6, $approvalMetrics['approved_count']);
        $this->assertEquals(2, $approvalMetrics['rejected_count']);
        $this->assertEquals(75.0, $approvalMetrics['approval_rate_percentage']); // 6/8 * 100
    }

    public function testGenerateTerminalUtilizationReportStructure(): void
    {
        // Mock repository responses
        $this->preAdviceRequestRepository
            ->expects($this->once())
            ->method('getTerminalUtilization')
            ->willReturn([
                [
                    'terminal_name' => 'CY Terminal',
                    'terminal_type' => \App\Entity\Enum\TerminalType::CY,
                    'request_count' => 15,
                    'completed_count' => 12
                ]
            ]);

        $this->preAdviceRequestRepository
            ->expects($this->once())
            ->method('getRequestsByTerminalType')
            ->willReturn([
                [
                    'terminal_type' => \App\Entity\Enum\TerminalType::CY,
                    'request_count' => 15,
                    'verified_count' => 12,
                    'rejected_count' => 3
                ]
            ]);

        // Mock terminal repository
        $mockTerminal = $this->createMock(\App\Entity\Terminal::class);
        $mockTerminal->method('getName')->willReturn('CY Terminal');
        $mockTerminal->method('getDailyCapacity')->willReturn(50);

        $this->terminalRepository
            ->expects($this->once())
            ->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$mockTerminal]);

        // Generate utilization report
        $startDate = new \DateTime('-1 day');
        $endDate = new \DateTime('+1 day');
        $utilization = $this->reportingService->generateTerminalUtilizationReport($startDate, $endDate);

        // Verify structure
        $this->assertArrayHasKey('period', $utilization);
        $this->assertArrayHasKey('terminal_utilization', $utilization);
        $this->assertArrayHasKey('terminal_type_statistics', $utilization);

        // Verify terminal utilization data
        $terminalUtilization = $utilization['terminal_utilization'];
        $this->assertCount(1, $terminalUtilization);
        
        $terminalData = $terminalUtilization[0];
        $this->assertEquals('CY Terminal', $terminalData['terminal_name']);
        $this->assertEquals('CY', $terminalData['terminal_type']);
        $this->assertEquals(15, $terminalData['total_requests']);
        $this->assertEquals(12, $terminalData['completed_requests']);
        $this->assertEquals(50, $terminalData['daily_capacity']);
        $this->assertEquals(24.0, $terminalData['utilization_rate_percentage']); // 12/50 * 100
    }

    public function testGenerateApprovalRateAnalyticsStructure(): void
    {
        // Mock repository responses
        $this->preAdviceRequestRepository
            ->expects($this->once())
            ->method('getDailyTrends')
            ->willReturn([
                [
                    'date' => '2024-01-15',
                    'total_requests' => 5,
                    'verified_requests' => 3,
                    'rejected_requests' => 2
                ]
            ]);

        $this->preAdviceRequestRepository
            ->expects($this->once())
            ->method('getApprovalRates')
            ->willReturn([
                'total_processed' => 10,
                'approved_count' => 7,
                'rejected_count' => 3
            ]);

        // Generate approval analytics
        $startDate = new \DateTime('-1 day');
        $endDate = new \DateTime('+1 day');
        $analytics = $this->reportingService->generateApprovalRateAnalytics($startDate, $endDate);

        // Verify structure
        $this->assertArrayHasKey('period', $analytics);
        $this->assertArrayHasKey('overall_metrics', $analytics);
        $this->assertArrayHasKey('daily_trends', $analytics);

        // Verify overall metrics
        $overallMetrics = $analytics['overall_metrics'];
        $this->assertEquals(10, $overallMetrics['total_processed']);
        $this->assertEquals(7, $overallMetrics['approved_count']);
        $this->assertEquals(3, $overallMetrics['rejected_count']);
        $this->assertEquals(70.0, $overallMetrics['approval_rate_percentage']); // 7/10 * 100

        // Verify daily trends
        $dailyTrends = $analytics['daily_trends'];
        $this->assertCount(1, $dailyTrends);
        
        $trendData = $dailyTrends[0];
        $this->assertEquals('2024-01-15', $trendData['date']);
        $this->assertEquals(5, $trendData['total_requests']);
        $this->assertEquals(3, $trendData['verified_requests']);
        $this->assertEquals(2, $trendData['rejected_requests']);
        $this->assertEquals(60.0, $trendData['approval_rate_percentage']); // 3/5 * 100
    }

    public function testApprovalRateCalculationWithZeroDivision(): void
    {
        // Mock repository responses with zero processed requests
        $this->preAdviceRequestRepository
            ->expects($this->once())
            ->method('getStatistics')
            ->willReturn([
                'total_requests' => 5,
                'pending_requests' => 5,
                'verified_requests' => 0,
                'rejected_requests' => 0,
                'completed_requests' => 0
            ]);

        $this->preAdviceRequestRepository
            ->expects($this->once())
            ->method('getApprovalRates')
            ->willReturn([
                'total_processed' => 0,
                'approved_count' => 0,
                'rejected_count' => 0
            ]);

        $this->preAdviceRequestRepository
            ->expects($this->once())
            ->method('getProcessingTimeStats')
            ->willReturn([
                'avg_processing_hours' => null,
                'min_processing_hours' => null,
                'max_processing_hours' => null
            ]);

        // Generate statistics
        $startDate = new \DateTime('-1 day');
        $endDate = new \DateTime('+1 day');
        $statistics = $this->reportingService->generatePreAdviceStatistics($startDate, $endDate);

        // Verify approval rate is 0 when no requests are processed
        $approvalMetrics = $statistics['approval_metrics'];
        $this->assertEquals(0, $approvalMetrics['approval_rate_percentage']);

        // Verify processing times handle null values
        $processingTimes = $statistics['processing_times'];
        $this->assertNull($processingTimes['average_hours']);
        $this->assertNull($processingTimes['minimum_hours']);
        $this->assertNull($processingTimes['maximum_hours']);
    }
}