<?php

namespace App\Service;

use App\Entity\ContainerAllocationAudit;
use App\Entity\ShippingLine;
use App\Entity\Terminal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Service for CY utilization reporting and analytics
 * 
 * Requirements: 12.1, 12.2, 12.4, 12.5
 */
class CYUtilizationReportService implements CYUtilizationReportServiceInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ParameterBagInterface $params
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function generateReport(
        \DateTime $startDate,
        \DateTime $endDate,
        ?int $shippingLineId = null,
        ?int $terminalId = null
    ): array {
        // Query container allocations within date range
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('
                audit,
                container,
                newAllocation,
                terminal,
                shippingLine
            ')
            ->from(ContainerAllocationAudit::class, 'audit')
            ->join('audit.container', 'container')
            ->join('audit.newAllocation', 'newAllocation')
            ->join('newAllocation.terminal', 'terminal')
            ->join('newAllocation.shippingLine', 'shippingLine')
            ->where('audit.changedAt >= :startDate')
            ->andWhere('audit.changedAt <= :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate);

        // Apply shipping line filter
        if ($shippingLineId !== null) {
            $qb->andWhere('shippingLine.id = :shippingLineId')
                ->setParameter('shippingLineId', $shippingLineId);
        }

        // Apply terminal filter
        if ($terminalId !== null) {
            $qb->andWhere('terminal.id = :terminalId')
                ->setParameter('terminalId', $terminalId);
        }

        $qb->orderBy('audit.changedAt', 'ASC');

        $auditRecords = $qb->getQuery()->getResult();

        // Calculate utilization metrics per CY location
        $utilizationByLocation = $this->calculateUtilizationMetrics($auditRecords);

        // Calculate utilization trends over time
        $utilizationTrends = $this->calculateUtilizationTrends($auditRecords, $startDate, $endDate);

        // Group by shipping line and terminal
        $groupedData = $this->groupByShippingLineAndTerminal($auditRecords);

        // Include Pre-Forecast vs Allocated breakdown
        $statusBreakdown = $this->calculateStatusBreakdown($auditRecords);

        return [
            'date_range' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
            ],
            'filters' => [
                'shipping_line_id' => $shippingLineId,
                'terminal_id' => $terminalId,
            ],
            'utilization_by_location' => $utilizationByLocation,
            'utilization_trends' => $utilizationTrends,
            'grouped_data' => $groupedData,
            'status_breakdown' => $statusBreakdown,
        ];
    }

    /**
     * Calculate utilization metrics per CY location
     * 
     * Requirements: 12.1, 12.5, 10.1, 10.2, 10.3
     */
    private function calculateUtilizationMetrics(array $auditRecords): array
    {
        $metrics = [];

        foreach ($auditRecords as $audit) {
            $allocation = $audit->getNewAllocation();
            $terminal = $allocation->getTerminal();
            $shippingLine = $allocation->getShippingLine();
            $container = $audit->getContainer();

            $key = sprintf('%d_%d', $terminal->getId(), $shippingLine->getId());

            if (!isset($metrics[$key])) {
                $metrics[$key] = [
                    'terminal_id' => $terminal->getId(),
                    'terminal_name' => $terminal->getName(),
                    'terminal_location' => $terminal->getLocation(),
                    'shipping_line_id' => $shippingLine->getId(),
                    'shipping_line_name' => $shippingLine->getBrandName(),
                    // TEU-based (backward compatibility)
                    'total_capacity_teu' => (float) $allocation->getAllocatedCapacity(),
                    'allocated_teu' => 0.0,
                    'container_count' => 0,
                    // Size-specific capacity
                    'capacity_20ft' => $allocation->getCapacity20ft() ?? 0,
                    'capacity_40ft' => $allocation->getCapacity40ft() ?? 0,
                    // Size-specific allocated counts
                    'allocated_20ft' => 0,
                    'allocated_40ft' => 0,
                    // Store allocation reference for later queries
                    '_allocation' => $allocation,
                ];
            }

            $teuValue = $container->getContainerSize()->getTeuValue();
            $metrics[$key]['allocated_teu'] += $teuValue;
            $metrics[$key]['container_count']++;
            
            // Count by size
            if ($teuValue == 1.0) {
                $metrics[$key]['allocated_20ft']++;
            } elseif ($teuValue == 2.0) {
                $metrics[$key]['allocated_40ft']++;
            }
        }

        // Calculate available capacity and utilization percentages
        foreach ($metrics as &$metric) {
            // TEU-based calculations (backward compatibility)
            $metric['available_teu'] = $metric['total_capacity_teu'] - $metric['allocated_teu'];
            $metric['utilization_percentage'] = $metric['total_capacity_teu'] > 0
                ? ($metric['allocated_teu'] / $metric['total_capacity_teu']) * 100
                : 0.0;
            
            // 20ft calculations
            $metric['available_20ft'] = max(0, $metric['capacity_20ft'] - $metric['allocated_20ft']);
            $metric['utilization_percentage_20ft'] = $metric['capacity_20ft'] > 0
                ? ($metric['allocated_20ft'] / $metric['capacity_20ft']) * 100
                : 0.0;
            
            // 40ft calculations
            $metric['available_40ft'] = max(0, $metric['capacity_40ft'] - $metric['allocated_40ft']);
            $metric['utilization_percentage_40ft'] = $metric['capacity_40ft'] > 0
                ? ($metric['allocated_40ft'] / $metric['capacity_40ft']) * 100
                : 0.0;
            
            // Remove internal allocation reference
            unset($metric['_allocation']);
        }

        return array_values($metrics);
    }

    /**
     * Calculate utilization trends over time
     * 
     * Requirements: 12.3
     */
    private function calculateUtilizationTrends(
        array $auditRecords,
        \DateTime $startDate,
        \DateTime $endDate
    ): array {
        $trends = [];

        // Group allocations by date
        $allocationsByDate = [];
        foreach ($auditRecords as $audit) {
            $date = $audit->getChangedAt()->format('Y-m-d');
            if (!isset($allocationsByDate[$date])) {
                $allocationsByDate[$date] = [];
            }
            $allocationsByDate[$date][] = $audit;
        }

        // Calculate cumulative utilization for each date
        $cumulativeTEU = 0.0;
        $currentDate = clone $startDate;
        
        while ($currentDate <= $endDate) {
            $dateStr = $currentDate->format('Y-m-d');
            
            if (isset($allocationsByDate[$dateStr])) {
                foreach ($allocationsByDate[$dateStr] as $audit) {
                    $container = $audit->getContainer();
                    $cumulativeTEU += $container->getContainerSize()->getTeuValue();
                }
            }

            $trends[] = [
                'date' => $dateStr,
                'allocated_teu' => $cumulativeTEU,
            ];

            $currentDate->modify('+1 day');
        }

        return $trends;
    }

    /**
     * Group data by shipping line and terminal
     * 
     * Requirements: 12.1, 12.2
     */
    private function groupByShippingLineAndTerminal(array $auditRecords): array
    {
        $grouped = [];

        foreach ($auditRecords as $audit) {
            $allocation = $audit->getNewAllocation();
            $terminal = $allocation->getTerminal();
            $shippingLine = $allocation->getShippingLine();
            $container = $audit->getContainer();

            $slKey = $shippingLine->getId();
            $termKey = $terminal->getId();

            if (!isset($grouped[$slKey])) {
                $grouped[$slKey] = [
                    'shipping_line_id' => $shippingLine->getId(),
                    'shipping_line_name' => $shippingLine->getBrandName(),
                    'terminals' => [],
                ];
            }

            if (!isset($grouped[$slKey]['terminals'][$termKey])) {
                $grouped[$slKey]['terminals'][$termKey] = [
                    'terminal_id' => $terminal->getId(),
                    'terminal_name' => $terminal->getName(),
                    'terminal_location' => $terminal->getLocation(),
                    'allocated_teu' => 0.0,
                    'container_count' => 0,
                ];
            }

            $grouped[$slKey]['terminals'][$termKey]['allocated_teu'] += 
                $container->getContainerSize()->getTeuValue();
            $grouped[$slKey]['terminals'][$termKey]['container_count']++;
        }

        // Convert terminals to array
        foreach ($grouped as &$slData) {
            $slData['terminals'] = array_values($slData['terminals']);
        }

        return array_values($grouped);
    }

    /**
     * Calculate Pre-Forecast vs Allocated breakdown
     * 
     * Requirements: 12.5
     */
    private function calculateStatusBreakdown(array $auditRecords): array
    {
        $breakdown = [
            'pre_forecast' => [
                'count' => 0,
                'teu' => 0.0,
            ],
            'allocated' => [
                'count' => 0,
                'teu' => 0.0,
            ],
        ];

        foreach ($auditRecords as $audit) {
            $container = $audit->getContainer();
            $status = $container->getAllocationStatus()->value;
            $teu = $container->getContainerSize()->getTeuValue();

            if ($status === 'pre_forecast') {
                $breakdown['pre_forecast']['count']++;
                $breakdown['pre_forecast']['teu'] += $teu;
            } elseif ($status === 'allocated') {
                $breakdown['allocated']['count']++;
                $breakdown['allocated']['teu'] += $teu;
            }
        }

        return $breakdown;
    }

    /**
     * {@inheritdoc}
     */
    public function exportToCSV(
        array $reportData,
        \DateTime $startDate,
        \DateTime $endDate
    ): string {
        $tmpDir = $this->params->get('kernel.project_dir') . '/var/tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0777, true);
        }

        $filename = sprintf(
            'cy_utilization_report_%s_to_%s.csv',
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d')
        );
        $filepath = $tmpDir . '/' . $filename;

        $handle = fopen($filepath, 'w');

        // Write metadata header
        fputcsv($handle, ['CY Utilization Report']);
        fputcsv($handle, ['Date Range', $startDate->format('Y-m-d') . ' to ' . $endDate->format('Y-m-d')]);
        fputcsv($handle, []);

        // Write utilization by location
        fputcsv($handle, ['Utilization by Location']);
        fputcsv($handle, [
            'Terminal',
            'Location',
            'Shipping Line',
            // TEU-based (backward compatibility)
            'Total Capacity (TEU)',
            'Allocated (TEU)',
            'Available (TEU)',
            'Utilization (%)',
            // 20ft specific
            'Capacity 20ft',
            'Allocated 20ft',
            'Available 20ft',
            'Utilization 20ft (%)',
            // 40ft specific
            'Capacity 40ft',
            'Allocated 40ft',
            'Available 40ft',
            'Utilization 40ft (%)',
            'Container Count'
        ]);

        foreach ($reportData['utilization_by_location'] as $location) {
            fputcsv($handle, [
                $location['terminal_name'],
                $location['terminal_location'],
                $location['shipping_line_name'],
                // TEU-based
                number_format($location['total_capacity_teu'], 1),
                number_format($location['allocated_teu'], 1),
                number_format($location['available_teu'], 1),
                number_format($location['utilization_percentage'], 2),
                // 20ft specific
                $location['capacity_20ft'],
                $location['allocated_20ft'],
                $location['available_20ft'],
                number_format($location['utilization_percentage_20ft'], 2),
                // 40ft specific
                $location['capacity_40ft'],
                $location['allocated_40ft'],
                $location['available_40ft'],
                number_format($location['utilization_percentage_40ft'], 2),
                $location['container_count']
            ]);
        }

        fputcsv($handle, []);

        // Write status breakdown
        fputcsv($handle, ['Allocation Status Breakdown']);
        fputcsv($handle, ['Status', 'Container Count', 'TEU']);
        fputcsv($handle, [
            'Pre-Forecast',
            $reportData['status_breakdown']['pre_forecast']['count'],
            number_format($reportData['status_breakdown']['pre_forecast']['teu'], 1)
        ]);
        fputcsv($handle, [
            'Allocated',
            $reportData['status_breakdown']['allocated']['count'],
            number_format($reportData['status_breakdown']['allocated']['teu'], 1)
        ]);

        fclose($handle);

        return $filepath;
    }

    /**
     * {@inheritdoc}
     */
    public function exportToPDF(
        array $reportData,
        \DateTime $startDate,
        \DateTime $endDate
    ): string {
        $tmpDir = $this->params->get('kernel.project_dir') . '/var/tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0777, true);
        }

        $filename = sprintf(
            'cy_utilization_report_%s_to_%s.pdf',
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d')
        );
        $filepath = $tmpDir . '/' . $filename;

        // Create PDF using TCPDF
        $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8');
        
        // Set document information
        $pdf->SetCreator('Optimus System');
        $pdf->SetAuthor('Optimus System');
        $pdf->SetTitle('CY Utilization Report');
        $pdf->SetSubject('Container Yard Utilization Report');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);

        // Add a page
        $pdf->AddPage();

        // Set font
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'CY Utilization Report', 0, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, sprintf(
            'Date Range: %s to %s',
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d')
        ), 0, 1, 'C');
        $pdf->Ln(5);

        // Utilization by Location table
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Utilization by Location', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(230, 230, 230);
        
        // Table header - TEU-based section
        $pdf->Cell(35, 7, 'Terminal', 1, 0, 'L', true);
        $pdf->Cell(30, 7, 'Shipping Line', 1, 0, 'L', true);
        $pdf->Cell(20, 7, 'Cap (TEU)', 1, 0, 'R', true);
        $pdf->Cell(20, 7, 'Alloc (TEU)', 1, 0, 'R', true);
        $pdf->Cell(20, 7, 'Util %', 1, 0, 'R', true);
        // 20ft section
        $pdf->Cell(18, 7, 'Cap 20ft', 1, 0, 'R', true);
        $pdf->Cell(18, 7, 'Alloc 20ft', 1, 0, 'R', true);
        $pdf->Cell(18, 7, 'Util 20%', 1, 0, 'R', true);
        // 40ft section
        $pdf->Cell(18, 7, 'Cap 40ft', 1, 0, 'R', true);
        $pdf->Cell(18, 7, 'Alloc 40ft', 1, 0, 'R', true);
        $pdf->Cell(18, 7, 'Util 40%', 1, 1, 'R', true);

        // Table data
        $pdf->SetFont('helvetica', '', 7);
        foreach ($reportData['utilization_by_location'] as $location) {
            $pdf->Cell(35, 6, $location['terminal_name'], 1, 0, 'L');
            $pdf->Cell(30, 6, $location['shipping_line_name'], 1, 0, 'L');
            // TEU-based
            $pdf->Cell(20, 6, number_format($location['total_capacity_teu'], 1), 1, 0, 'R');
            $pdf->Cell(20, 6, number_format($location['allocated_teu'], 1), 1, 0, 'R');
            $pdf->Cell(20, 6, number_format($location['utilization_percentage'], 1) . '%', 1, 0, 'R');
            // 20ft
            $pdf->Cell(18, 6, $location['capacity_20ft'], 1, 0, 'R');
            $pdf->Cell(18, 6, $location['allocated_20ft'], 1, 0, 'R');
            $pdf->Cell(18, 6, number_format($location['utilization_percentage_20ft'], 1) . '%', 1, 0, 'R');
            // 40ft
            $pdf->Cell(18, 6, $location['capacity_40ft'], 1, 0, 'R');
            $pdf->Cell(18, 6, $location['allocated_40ft'], 1, 0, 'R');
            $pdf->Cell(18, 6, number_format($location['utilization_percentage_40ft'], 1) . '%', 1, 1, 'R');
        }

        $pdf->Ln(10);

        // Status breakdown
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Allocation Status Breakdown', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(80, 7, 'Status', 1, 0, 'L', true);
        $pdf->Cell(50, 7, 'Container Count', 1, 0, 'R', true);
        $pdf->Cell(50, 7, 'TEU', 1, 1, 'R', true);

        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(80, 6, 'Pre-Forecast', 1, 0, 'L');
        $pdf->Cell(50, 6, $reportData['status_breakdown']['pre_forecast']['count'], 1, 0, 'R');
        $pdf->Cell(50, 6, number_format($reportData['status_breakdown']['pre_forecast']['teu'], 1), 1, 1, 'R');

        $pdf->Cell(80, 6, 'Allocated', 1, 0, 'L');
        $pdf->Cell(50, 6, $reportData['status_breakdown']['allocated']['count'], 1, 0, 'R');
        $pdf->Cell(50, 6, number_format($reportData['status_breakdown']['allocated']['teu'], 1), 1, 1, 'R');

        // Output PDF to file
        $pdf->Output($filepath, 'F');

        return $filepath;
    }
}
