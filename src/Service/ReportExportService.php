<?php

namespace App\Service;

use TCPDF;

class ReportExportService
{
    public function __construct(
        private ReportingService $reportingService,
        private string $projectDir
    ) {}

    /**
     * Export FREE-ADVICE statistics to PDF
     */
    public function exportPreAdviceStatisticsToPDF(\DateTime $startDate, \DateTime $endDate): string
    {
        $statistics = $this->reportingService->generatePreAdviceStatistics($startDate, $endDate);
        
        $pdf = new TCPDF();
        $pdf->SetCreator('Optimus Shipping Portal');
        $pdf->SetAuthor('Terminal Team');
        $pdf->SetTitle('FREE-ADVICE Statistics Report');
        $pdf->SetSubject('FREE-ADVICE Statistics');
        
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'FREE-ADVICE Statistics Report', 0, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Ln(5);
        $pdf->Cell(0, 10, 'Period: ' . $statistics['period']['start_date'] . ' to ' . $statistics['period']['end_date'], 0, 1);
        $pdf->Cell(0, 10, 'Generated: ' . date('Y-m-d H:i:s'), 0, 1);
        
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'Summary Statistics', 0, 1);
        
        $pdf->SetFont('helvetica', '', 11);
        $summary = $statistics['summary'];
        $pdf->Cell(60, 8, 'Total Requests:', 1, 0);
        $pdf->Cell(40, 8, $summary['total_requests'], 1, 1);
        $pdf->Cell(60, 8, 'Pending Requests:', 1, 0);
        $pdf->Cell(40, 8, $summary['pending_requests'], 1, 1);
        $pdf->Cell(60, 8, 'Verified Requests:', 1, 0);
        $pdf->Cell(40, 8, $summary['verified_requests'], 1, 1);
        $pdf->Cell(60, 8, 'Rejected Requests:', 1, 0);
        $pdf->Cell(40, 8, $summary['rejected_requests'], 1, 1);
        $pdf->Cell(60, 8, 'Completed Requests:', 1, 0);
        $pdf->Cell(40, 8, $summary['completed_requests'], 1, 1);
        
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'Approval Metrics', 0, 1);
        
        $pdf->SetFont('helvetica', '', 11);
        $approval = $statistics['approval_metrics'];
        $pdf->Cell(60, 8, 'Total Processed:', 1, 0);
        $pdf->Cell(40, 8, $approval['total_processed'], 1, 1);
        $pdf->Cell(60, 8, 'Approved Count:', 1, 0);
        $pdf->Cell(40, 8, $approval['approved_count'], 1, 1);
        $pdf->Cell(60, 8, 'Rejected Count:', 1, 0);
        $pdf->Cell(40, 8, $approval['rejected_count'], 1, 1);
        $pdf->Cell(60, 8, 'Approval Rate:', 1, 0);
        $pdf->Cell(40, 8, $approval['approval_rate_percentage'] . '%', 1, 1);
        
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'Processing Times', 0, 1);
        
        $pdf->SetFont('helvetica', '', 11);
        $processing = $statistics['processing_times'];
        $pdf->Cell(60, 8, 'Average Hours:', 1, 0);
        $pdf->Cell(40, 8, $processing['average_hours'] ?? 'N/A', 1, 1);
        $pdf->Cell(60, 8, 'Minimum Hours:', 1, 0);
        $pdf->Cell(40, 8, $processing['minimum_hours'] ?? 'N/A', 1, 1);
        $pdf->Cell(60, 8, 'Maximum Hours:', 1, 0);
        $pdf->Cell(40, 8, $processing['maximum_hours'] ?? 'N/A', 1, 1);
        
        $filename = 'pre_advice_statistics_' . $startDate->format('Y-m-d') . '_to_' . $endDate->format('Y-m-d') . '.pdf';
        $filepath = $this->projectDir . '/var/reports/' . $filename;
        
        // Ensure directory exists
        $this->ensureDirectoryExists(dirname($filepath));
        
        $pdf->Output($filepath, 'F');
        
        return $filepath;
    }

    /**
     * Export terminal utilization report to PDF
     */
    public function exportTerminalUtilizationToPDF(\DateTime $startDate, \DateTime $endDate): string
    {
        $utilization = $this->reportingService->generateTerminalUtilizationReport($startDate, $endDate);
        
        $pdf = new TCPDF();
        $pdf->SetCreator('Optimus Shipping Portal');
        $pdf->SetAuthor('Terminal Team');
        $pdf->SetTitle('Terminal Utilization Report');
        $pdf->SetSubject('Terminal Utilization');
        
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Terminal Utilization Report', 0, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Ln(5);
        $pdf->Cell(0, 10, 'Period: ' . $utilization['period']['start_date'] . ' to ' . $utilization['period']['end_date'], 0, 1);
        $pdf->Cell(0, 10, 'Generated: ' . date('Y-m-d H:i:s'), 0, 1);
        
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'Terminal Utilization', 0, 1);
        
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(40, 8, 'Terminal', 1, 0, 'C');
        $pdf->Cell(20, 8, 'Type', 1, 0, 'C');
        $pdf->Cell(25, 8, 'Requests', 1, 0, 'C');
        $pdf->Cell(25, 8, 'Completed', 1, 0, 'C');
        $pdf->Cell(25, 8, 'Capacity', 1, 0, 'C');
        $pdf->Cell(25, 8, 'Utilization', 1, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 9);
        foreach ($utilization['terminal_utilization'] as $terminal) {
            $pdf->Cell(40, 6, $terminal['terminal_name'], 1, 0);
            $pdf->Cell(20, 6, $terminal['terminal_type'], 1, 0, 'C');
            $pdf->Cell(25, 6, $terminal['total_requests'], 1, 0, 'C');
            $pdf->Cell(25, 6, $terminal['completed_requests'], 1, 0, 'C');
            $pdf->Cell(25, 6, $terminal['daily_capacity'], 1, 0, 'C');
            $pdf->Cell(25, 6, $terminal['utilization_rate_percentage'] . '%', 1, 1, 'C');
        }
        
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'Terminal Type Statistics', 0, 1);
        
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(30, 8, 'Type', 1, 0, 'C');
        $pdf->Cell(30, 8, 'Requests', 1, 0, 'C');
        $pdf->Cell(30, 8, 'Verified', 1, 0, 'C');
        $pdf->Cell(30, 8, 'Rejected', 1, 0, 'C');
        $pdf->Cell(30, 8, 'Approval Rate', 1, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 9);
        foreach ($utilization['terminal_type_statistics'] as $typeStats) {
            $pdf->Cell(30, 6, $typeStats['terminal_type'], 1, 0, 'C');
            $pdf->Cell(30, 6, $typeStats['total_requests'], 1, 0, 'C');
            $pdf->Cell(30, 6, $typeStats['verified_requests'], 1, 0, 'C');
            $pdf->Cell(30, 6, $typeStats['rejected_requests'], 1, 0, 'C');
            $pdf->Cell(30, 6, $typeStats['approval_rate_percentage'] . '%', 1, 1, 'C');
        }
        
        $filename = 'terminal_utilization_' . $startDate->format('Y-m-d') . '_to_' . $endDate->format('Y-m-d') . '.pdf';
        $filepath = $this->projectDir . '/var/reports/' . $filename;
        
        // Ensure directory exists
        $this->ensureDirectoryExists(dirname($filepath));
        
        $pdf->Output($filepath, 'F');
        
        return $filepath;
    }

    /**
     * Export data to CSV format (Excel compatible)
     */
    public function exportPreAdviceStatisticsToCSV(\DateTime $startDate, \DateTime $endDate): string
    {
        $statistics = $this->reportingService->generatePreAdviceStatistics($startDate, $endDate);
        $approvalAnalytics = $this->reportingService->generateApprovalRateAnalytics($startDate, $endDate);
        
        $filename = 'pre_advice_statistics_' . $startDate->format('Y-m-d') . '_to_' . $endDate->format('Y-m-d') . '.csv';
        $filepath = $this->projectDir . '/var/reports/' . $filename;
        
        // Ensure directory exists
        $this->ensureDirectoryExists(dirname($filepath));
        
        $file = fopen($filepath, 'w');
        
        // Write header
        fputcsv($file, ['FREE-ADVICE Statistics Report']);
        fputcsv($file, ['Period', $statistics['period']['start_date'] . ' to ' . $statistics['period']['end_date']]);
        fputcsv($file, ['Generated', date('Y-m-d H:i:s')]);
        fputcsv($file, []); // Empty row
        
        // Summary statistics
        fputcsv($file, ['Summary Statistics']);
        fputcsv($file, ['Metric', 'Value']);
        fputcsv($file, ['Total Requests', $statistics['summary']['total_requests']]);
        fputcsv($file, ['Pending Requests', $statistics['summary']['pending_requests']]);
        fputcsv($file, ['Verified Requests', $statistics['summary']['verified_requests']]);
        fputcsv($file, ['Rejected Requests', $statistics['summary']['rejected_requests']]);
        fputcsv($file, ['Completed Requests', $statistics['summary']['completed_requests']]);
        fputcsv($file, []); // Empty row
        
        // Approval metrics
        fputcsv($file, ['Approval Metrics']);
        fputcsv($file, ['Metric', 'Value']);
        fputcsv($file, ['Total Processed', $statistics['approval_metrics']['total_processed']]);
        fputcsv($file, ['Approved Count', $statistics['approval_metrics']['approved_count']]);
        fputcsv($file, ['Rejected Count', $statistics['approval_metrics']['rejected_count']]);
        fputcsv($file, ['Approval Rate (%)', $statistics['approval_metrics']['approval_rate_percentage']]);
        fputcsv($file, []); // Empty row
        
        // Daily trends
        fputcsv($file, ['Daily Trends']);
        fputcsv($file, ['Date', 'Total Requests', 'Verified Requests', 'Rejected Requests', 'Approval Rate (%)']);
        foreach ($approvalAnalytics['daily_trends'] as $trend) {
            fputcsv($file, [
                $trend['date'],
                $trend['total_requests'],
                $trend['verified_requests'],
                $trend['rejected_requests'],
                $trend['approval_rate_percentage']
            ]);
        }
        
        fclose($file);
        
        return $filepath;
    }

    /**
     * Export terminal utilization to CSV format
     */
    public function exportTerminalUtilizationToCSV(\DateTime $startDate, \DateTime $endDate): string
    {
        $utilization = $this->reportingService->generateTerminalUtilizationReport($startDate, $endDate);
        
        $filename = 'terminal_utilization_' . $startDate->format('Y-m-d') . '_to_' . $endDate->format('Y-m-d') . '.csv';
        $filepath = $this->projectDir . '/var/reports/' . $filename;
        
        // Ensure directory exists
        $this->ensureDirectoryExists(dirname($filepath));
        
        $file = fopen($filepath, 'w');
        
        // Write header
        fputcsv($file, ['Terminal Utilization Report']);
        fputcsv($file, ['Period', $utilization['period']['start_date'] . ' to ' . $utilization['period']['end_date']]);
        fputcsv($file, ['Generated', date('Y-m-d H:i:s')]);
        fputcsv($file, []); // Empty row
        
        // Terminal utilization
        fputcsv($file, ['Terminal Utilization']);
        fputcsv($file, ['Terminal Name', 'Type', 'Total Requests', 'Completed Requests', 'Daily Capacity', 'Utilization Rate (%)']);
        foreach ($utilization['terminal_utilization'] as $terminal) {
            fputcsv($file, [
                $terminal['terminal_name'],
                $terminal['terminal_type'],
                $terminal['total_requests'],
                $terminal['completed_requests'],
                $terminal['daily_capacity'],
                $terminal['utilization_rate_percentage']
            ]);
        }
        fputcsv($file, []); // Empty row
        
        // Terminal type statistics
        fputcsv($file, ['Terminal Type Statistics']);
        fputcsv($file, ['Terminal Type', 'Total Requests', 'Verified Requests', 'Rejected Requests', 'Approval Rate (%)']);
        foreach ($utilization['terminal_type_statistics'] as $typeStats) {
            fputcsv($file, [
                $typeStats['terminal_type'],
                $typeStats['total_requests'],
                $typeStats['verified_requests'],
                $typeStats['rejected_requests'],
                $typeStats['approval_rate_percentage']
            ]);
        }
        
        fclose($file);
        
        return $filepath;
    }

    /**
     * Generate comprehensive dashboard report with all metrics
     */
    public function generateComprehensiveReport(\DateTime $startDate, \DateTime $endDate, string $format = 'pdf'): string
    {
        $dashboardMetrics = $this->reportingService->generateDashboardMetrics($startDate, $endDate);
        
        if ($format === 'csv') {
            return $this->generateComprehensiveCSVReport($dashboardMetrics, $startDate, $endDate);
        }
        
        return $this->generateComprehensivePDFReport($dashboardMetrics, $startDate, $endDate);
    }

    /**
     * Generate comprehensive PDF report
     */
    private function generateComprehensivePDFReport(array $dashboardMetrics, \DateTime $startDate, \DateTime $endDate): string
    {
        $pdf = new TCPDF();
        $pdf->SetCreator('Optimus Shipping Portal');
        $pdf->SetAuthor('Terminal Team');
        $pdf->SetTitle('Comprehensive FREE-ADVICE Report');
        $pdf->SetSubject('FREE-ADVICE Comprehensive Report');
        
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 15, 'Comprehensive FREE-ADVICE Report', 0, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Ln(5);
        $pdf->Cell(0, 10, 'Period: ' . $startDate->format('Y-m-d') . ' to ' . $endDate->format('Y-m-d'), 0, 1);
        $pdf->Cell(0, 10, 'Generated: ' . $dashboardMetrics['generated_at'], 0, 1);
        
        // Add all sections from dashboard metrics
        $this->addStatisticsSectionToPDF($pdf, $dashboardMetrics['pre_advice_statistics']);
        $this->addUtilizationSectionToPDF($pdf, $dashboardMetrics['terminal_utilization']);
        $this->addApprovalAnalyticsSectionToPDF($pdf, $dashboardMetrics['approval_analytics']);
        
        $filename = 'comprehensive_report_' . $startDate->format('Y-m-d') . '_to_' . $endDate->format('Y-m-d') . '.pdf';
        $filepath = $this->projectDir . '/var/reports/' . $filename;
        
        // Ensure directory exists
        $this->ensureDirectoryExists(dirname($filepath));
        
        $pdf->Output($filepath, 'F');
        
        return $filepath;
    }

    /**
     * Generate comprehensive CSV report
     */
    private function generateComprehensiveCSVReport(array $dashboardMetrics, \DateTime $startDate, \DateTime $endDate): string
    {
        $filename = 'comprehensive_report_' . $startDate->format('Y-m-d') . '_to_' . $endDate->format('Y-m-d') . '.csv';
        $filepath = $this->projectDir . '/var/reports/' . $filename;
        
        // Ensure directory exists
        $this->ensureDirectoryExists(dirname($filepath));
        
        $file = fopen($filepath, 'w');
        
        // Write comprehensive report data
        fputcsv($file, ['Comprehensive FREE-ADVICE Report']);
        fputcsv($file, ['Period', $startDate->format('Y-m-d') . ' to ' . $endDate->format('Y-m-d')]);
        fputcsv($file, ['Generated', $dashboardMetrics['generated_at']]);
        fputcsv($file, []); // Empty row
        
        // Add all sections
        $this->addStatisticsSectionToCSV($file, $dashboardMetrics['pre_advice_statistics']);
        $this->addUtilizationSectionToCSV($file, $dashboardMetrics['terminal_utilization']);
        $this->addApprovalAnalyticsSectionToCSV($file, $dashboardMetrics['approval_analytics']);
        
        fclose($file);
        
        return $filepath;
    }

    /**
     * Helper methods for PDF sections
     */
    private function addStatisticsSectionToPDF(TCPDF $pdf, array $statistics): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'FREE-ADVICE Statistics', 0, 1);
        
        // Add statistics content (similar to exportPreAdviceStatisticsToPDF)
        $pdf->SetFont('helvetica', '', 11);
        $summary = $statistics['summary'];
        $pdf->Cell(60, 8, 'Total Requests:', 1, 0);
        $pdf->Cell(40, 8, $summary['total_requests'], 1, 1);
        // ... add other statistics
    }

    private function addUtilizationSectionToPDF(TCPDF $pdf, array $utilization): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Terminal Utilization', 0, 1);
        
        // Add utilization content (similar to exportTerminalUtilizationToPDF)
    }

    private function addApprovalAnalyticsSectionToPDF(TCPDF $pdf, array $analytics): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Approval Analytics', 0, 1);
        
        // Add approval analytics content
    }

    /**
     * Helper methods for CSV sections
     */
    private function addStatisticsSectionToCSV($file, array $statistics): void
    {
        fputcsv($file, ['FREE-ADVICE Statistics']);
        fputcsv($file, ['Metric', 'Value']);
        fputcsv($file, ['Total Requests', $statistics['summary']['total_requests']]);
        // ... add other statistics
        fputcsv($file, []); // Empty row
    }

    private function addUtilizationSectionToCSV($file, array $utilization): void
    {
        fputcsv($file, ['Terminal Utilization']);
        fputcsv($file, ['Terminal Name', 'Type', 'Total Requests', 'Completed Requests', 'Daily Capacity', 'Utilization Rate (%)']);
        foreach ($utilization['terminal_utilization'] as $terminal) {
            fputcsv($file, [
                $terminal['terminal_name'],
                $terminal['terminal_type'],
                $terminal['total_requests'],
                $terminal['completed_requests'],
                $terminal['daily_capacity'],
                $terminal['utilization_rate_percentage']
            ]);
        }
        fputcsv($file, []); // Empty row
    }

    private function addApprovalAnalyticsSectionToCSV($file, array $analytics): void
    {
        fputcsv($file, ['Approval Analytics']);
        fputcsv($file, ['Overall Approval Rate (%)', $analytics['overall_metrics']['approval_rate_percentage']]);
        fputcsv($file, []); // Empty row
        
        fputcsv($file, ['Daily Trends']);
        fputcsv($file, ['Date', 'Total Requests', 'Verified Requests', 'Rejected Requests', 'Approval Rate (%)']);
        foreach ($analytics['daily_trends'] as $trend) {
            fputcsv($file, [
                $trend['date'],
                $trend['total_requests'],
                $trend['verified_requests'],
                $trend['rejected_requests'],
                $trend['approval_rate_percentage']
            ]);
        }
        fputcsv($file, []); // Empty row
    }

    /**
     * Ensure directory exists
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    /**
     * Clean up old report files (older than 30 days)
     */
    public function cleanupOldReports(): int
    {
        $reportsDir = $this->projectDir . '/var/reports';
        if (!is_dir($reportsDir)) {
            return 0;
        }

        $cutoffDate = new \DateTime('-30 days');
        $deletedCount = 0;

        $files = glob($reportsDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                $fileTime = new \DateTime('@' . filemtime($file));
                if ($fileTime < $cutoffDate) {
                    unlink($file);
                    $deletedCount++;
                }
            }
        }

        return $deletedCount;
    }
}