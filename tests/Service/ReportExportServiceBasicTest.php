<?php

namespace App\Tests\Service;

use App\Service\ReportingService;
use App\Service\ReportExportService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Basic unit tests for ReportExportService without database dependencies
 */
class ReportExportServiceBasicTest extends TestCase
{
    private ReportExportService $reportExportService;
    private MockObject $reportingService;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->reportingService = $this->createMock(ReportingService::class);
        $this->tempDir = sys_get_temp_dir() . '/report_export_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        
        $this->reportExportService = new ReportExportService(
            $this->reportingService,
            $this->tempDir
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    }

    public function testExportPreAdviceStatisticsToPDFCreatesFile(): void
    {
        // Mock reporting service response
        $mockStatistics = [
            'period' => [
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31'
            ],
            'summary' => [
                'total_requests' => 100,
                'pending_requests' => 10,
                'verified_requests' => 70,
                'rejected_requests' => 15,
                'completed_requests' => 5
            ],
            'approval_metrics' => [
                'total_processed' => 90,
                'approved_count' => 75,
                'rejected_count' => 15,
                'approval_rate_percentage' => 83.33
            ],
            'processing_times' => [
                'average_hours' => 24.5,
                'minimum_hours' => 2.0,
                'maximum_hours' => 72.0
            ]
        ];

        $this->reportingService
            ->expects($this->once())
            ->method('generatePreAdviceStatistics')
            ->willReturn($mockStatistics);

        // Export to PDF
        $startDate = new \DateTime('2024-01-01');
        $endDate = new \DateTime('2024-01-31');
        $filePath = $this->reportExportService->exportPreAdviceStatisticsToPDF($startDate, $endDate);

        // Verify file was created
        $this->assertFileExists($filePath);
        $this->assertStringEndsWith('.pdf', $filePath);
        $this->assertStringContainsString('pre_advice_statistics_2024-01-01_to_2024-01-31.pdf', $filePath);
        
        // Verify file has content
        $this->assertGreaterThan(0, filesize($filePath));
        
        // Verify file is a valid PDF (starts with PDF header)
        $fileContent = file_get_contents($filePath);
        $this->assertStringStartsWith('%PDF', $fileContent);
    }

    public function testExportPreAdviceStatisticsToCSVCreatesFile(): void
    {
        // Mock reporting service responses
        $mockStatistics = [
            'period' => [
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31'
            ],
            'summary' => [
                'total_requests' => 100,
                'pending_requests' => 10,
                'verified_requests' => 70,
                'rejected_requests' => 15,
                'completed_requests' => 5
            ],
            'approval_metrics' => [
                'total_processed' => 90,
                'approved_count' => 75,
                'rejected_count' => 15,
                'approval_rate_percentage' => 83.33
            ]
        ];

        $mockApprovalAnalytics = [
            'daily_trends' => [
                [
                    'date' => '2024-01-15',
                    'total_requests' => 5,
                    'verified_requests' => 4,
                    'rejected_requests' => 1,
                    'approval_rate_percentage' => 80.0
                ]
            ]
        ];

        $this->reportingService
            ->expects($this->once())
            ->method('generatePreAdviceStatistics')
            ->willReturn($mockStatistics);

        $this->reportingService
            ->expects($this->once())
            ->method('generateApprovalRateAnalytics')
            ->willReturn($mockApprovalAnalytics);

        // Export to CSV
        $startDate = new \DateTime('2024-01-01');
        $endDate = new \DateTime('2024-01-31');
        $filePath = $this->reportExportService->exportPreAdviceStatisticsToCSV($startDate, $endDate);

        // Verify file was created
        $this->assertFileExists($filePath);
        $this->assertStringEndsWith('.csv', $filePath);
        $this->assertStringContainsString('pre_advice_statistics_2024-01-01_to_2024-01-31.csv', $filePath);
        
        // Verify file has content
        $this->assertGreaterThan(0, filesize($filePath));
        
        // Verify CSV structure
        $csvContent = file_get_contents($filePath);
        $this->assertStringContainsString('Pre-Advice Statistics Report', $csvContent);
        $this->assertStringContainsString('Summary Statistics', $csvContent);
        $this->assertStringContainsString('Approval Metrics', $csvContent);
        $this->assertStringContainsString('Daily Trends', $csvContent);
        $this->assertStringContainsString('100', $csvContent); // total_requests
        $this->assertStringContainsString('83.33', $csvContent); // approval_rate_percentage
    }

    public function testExportTerminalUtilizationToPDFCreatesFile(): void
    {
        // Mock reporting service response
        $mockUtilization = [
            'period' => [
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31'
            ],
            'terminal_utilization' => [
                [
                    'terminal_name' => 'CY Terminal',
                    'terminal_type' => 'CY',
                    'total_requests' => 50,
                    'completed_requests' => 45,
                    'daily_capacity' => 100,
                    'utilization_rate_percentage' => 45.0
                ]
            ],
            'terminal_type_statistics' => [
                [
                    'terminal_type' => 'CY',
                    'total_requests' => 50,
                    'verified_requests' => 45,
                    'rejected_requests' => 5,
                    'approval_rate_percentage' => 90.0
                ]
            ]
        ];

        $this->reportingService
            ->expects($this->once())
            ->method('generateTerminalUtilizationReport')
            ->willReturn($mockUtilization);

        // Export to PDF
        $startDate = new \DateTime('2024-01-01');
        $endDate = new \DateTime('2024-01-31');
        $filePath = $this->reportExportService->exportTerminalUtilizationToPDF($startDate, $endDate);

        // Verify file was created
        $this->assertFileExists($filePath);
        $this->assertStringEndsWith('.pdf', $filePath);
        $this->assertStringContainsString('terminal_utilization_2024-01-01_to_2024-01-31.pdf', $filePath);
        
        // Verify file has content
        $this->assertGreaterThan(0, filesize($filePath));
        
        // Verify file is a valid PDF
        $fileContent = file_get_contents($filePath);
        $this->assertStringStartsWith('%PDF', $fileContent);
    }

    public function testGenerateComprehensiveReportPDF(): void
    {
        // Mock reporting service response
        $mockDashboardMetrics = [
            'generated_at' => '2024-01-31 12:00:00',
            'period' => [
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31'
            ],
            'pre_advice_statistics' => [
                'summary' => ['total_requests' => 100]
            ],
            'terminal_utilization' => [
                'terminal_utilization' => []
            ],
            'approval_analytics' => [
                'overall_metrics' => ['approval_rate_percentage' => 85.0]
            ]
        ];

        $this->reportingService
            ->expects($this->once())
            ->method('generateDashboardMetrics')
            ->willReturn($mockDashboardMetrics);

        // Generate comprehensive PDF report
        $startDate = new \DateTime('2024-01-01');
        $endDate = new \DateTime('2024-01-31');
        $filePath = $this->reportExportService->generateComprehensiveReport($startDate, $endDate, 'pdf');

        // Verify file was created
        $this->assertFileExists($filePath);
        $this->assertStringEndsWith('.pdf', $filePath);
        $this->assertStringContainsString('comprehensive_report_2024-01-01_to_2024-01-31.pdf', $filePath);
        
        // Verify file has content
        $this->assertGreaterThan(0, filesize($filePath));
        
        // Verify file is a valid PDF
        $fileContent = file_get_contents($filePath);
        $this->assertStringStartsWith('%PDF', $fileContent);
    }

    public function testCleanupOldReports(): void
    {
        // Create test files with different ages
        $reportsDir = $this->tempDir . '/var/reports';
        mkdir($reportsDir, 0755, true);

        // Create a recent file (should not be deleted)
        $recentFile = $reportsDir . '/recent_report.pdf';
        file_put_contents($recentFile, 'test content');
        touch($recentFile, time() - (7 * 24 * 3600)); // 7 days old

        // Create an old file (should be deleted)
        $oldFile = $reportsDir . '/old_report.pdf';
        file_put_contents($oldFile, 'test content');
        touch($oldFile, time() - (35 * 24 * 3600)); // 35 days old

        // Run cleanup
        $deletedCount = $this->reportExportService->cleanupOldReports();

        // Verify results
        $this->assertEquals(1, $deletedCount);
        $this->assertFileExists($recentFile);
        $this->assertFileDoesNotExist($oldFile);
    }

    public function testDirectoryCreationWhenNotExists(): void
    {
        // Remove reports directory if it exists
        $reportsDir = $this->tempDir . '/var/reports';
        if (is_dir($reportsDir)) {
            rmdir($reportsDir);
        }

        // Mock reporting service
        $this->reportingService
            ->expects($this->once())
            ->method('generatePreAdviceStatistics')
            ->willReturn([
                'period' => ['start_date' => '2024-01-01', 'end_date' => '2024-01-31'],
                'summary' => ['total_requests' => 1],
                'approval_metrics' => ['approval_rate_percentage' => 100],
                'processing_times' => ['average_hours' => 1]
            ]);

        // Export report (should create directory)
        $startDate = new \DateTime('2024-01-01');
        $endDate = new \DateTime('2024-01-31');
        $filePath = $this->reportExportService->exportPreAdviceStatisticsToPDF($startDate, $endDate);

        // Verify directory was created
        $this->assertDirectoryExists($reportsDir);
        $this->assertFileExists($filePath);
    }
}