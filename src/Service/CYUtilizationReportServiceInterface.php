<?php

namespace App\Service;

/**
 * Interface for CY utilization reporting and analytics
 * 
 * Requirements: 12.1, 12.2, 12.4, 12.5
 */
interface CYUtilizationReportServiceInterface
{
    /**
     * Generate CY utilization report data
     * 
     * @param \DateTime $startDate Start date for report
     * @param \DateTime $endDate End date for report
     * @param int|null $shippingLineId Optional shipping line filter
     * @param int|null $terminalId Optional terminal filter
     * @return array Report data with utilization metrics and trends
     */
    public function generateReport(
        \DateTime $startDate,
        \DateTime $endDate,
        ?int $shippingLineId = null,
        ?int $terminalId = null
    ): array;

    /**
     * Export report data to CSV format
     * 
     * @param array $reportData Report data to export
     * @param \DateTime $startDate Start date for metadata
     * @param \DateTime $endDate End date for metadata
     * @return string Path to generated CSV file
     */
    public function exportToCSV(
        array $reportData,
        \DateTime $startDate,
        \DateTime $endDate
    ): string;

    /**
     * Export report data to PDF format
     * 
     * @param array $reportData Report data to export
     * @param \DateTime $startDate Start date for metadata
     * @param \DateTime $endDate End date for metadata
     * @return string Path to generated PDF file
     */
    public function exportToPDF(
        array $reportData,
        \DateTime $startDate,
        \DateTime $endDate
    ): string;
}
