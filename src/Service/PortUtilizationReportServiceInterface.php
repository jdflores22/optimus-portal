<?php

namespace App\Service;

interface PortUtilizationReportServiceInterface
{
    public function generateReport(
        \DateTime $startDate,
        \DateTime $endDate,
        ?int $shippingLineId = null,
        ?int $terminalId = null
    ): array;

    public function exportToCSV(
        array $reportData,
        \DateTime $startDate,
        \DateTime $endDate
    ): string;

    public function exportToPDF(
        array $reportData,
        \DateTime $startDate,
        \DateTime $endDate
    ): string;
}
