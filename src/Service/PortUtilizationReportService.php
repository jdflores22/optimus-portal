<?php

namespace App\Service;

use App\Entity\Enum\AllocationStatus;
use App\Entity\Enum\TerminalType;
use App\Entity\NOA;
use App\Entity\Terminal;
use App\Repository\TerminalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Port/terminal utilization for projected laden container arrivals (from NOA port_location).
 */
class PortUtilizationReportService implements PortUtilizationReportServiceInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TerminalRepository $terminalRepository,
        private readonly ParameterBagInterface $params,
    ) {
    }

    public function generateReport(
        \DateTime $startDate,
        \DateTime $endDate,
        ?int $shippingLineId = null,
        ?int $terminalId = null
    ): array {
        $portTerminals = $this->indexPortTerminals();
        $portFilterCode = null;
        if ($terminalId !== null) {
            $filtered = $this->terminalRepository->find($terminalId);
            if ($filtered && $filtered->getType() !== TerminalType::CY) {
                $portFilterCode = $filtered->getCode();
            }
        }

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('noa')
            ->from(NOA::class, 'noa')
            ->innerJoin('noa.containers', 'container')
            ->leftJoin('container.shippingLine', 'shippingLine')
            ->addSelect('container', 'shippingLine')
            ->where('noa.eta >= :startDate')
            ->andWhere('noa.eta <= :endDate')
            ->andWhere('noa.portLocation IS NOT NULL')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate);

        if ($shippingLineId !== null) {
            $qb->andWhere('shippingLine.id = :shippingLineId')
                ->setParameter('shippingLineId', $shippingLineId);
        }

        if ($portFilterCode !== null) {
            $qb->andWhere('noa.portLocation = :portCode')
                ->setParameter('portCode', $portFilterCode);
        }

        $qb->orderBy('noa.eta', 'ASC');

        /** @var NOA[] $noas */
        $noas = $qb->getQuery()->getResult();

        $utilizationByLocation = $this->calculateUtilizationMetrics($noas, $portTerminals);
        $utilizationTrends = $this->calculateUtilizationTrends($noas, $startDate, $endDate);
        $statusBreakdown = $this->calculateStatusBreakdown($noas);

        return [
            'date_range' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
            ],
            'filters' => [
                'shipping_line_id' => $shippingLineId,
                'terminal_id' => $terminalId,
            ],
            'utilization_by_location' => array_values($utilizationByLocation),
            'utilization_trends' => $utilizationTrends,
            'status_breakdown' => $statusBreakdown,
        ];
    }

    /**
     * @return array<string, Terminal>
     */
    private function indexPortTerminals(): array
    {
        $indexed = [];
        foreach ($this->terminalRepository->findActivePorts() as $terminal) {
            $indexed[$terminal->getCode()] = $terminal;
        }

        return $indexed;
    }

    /**
     * @param NOA[] $noas
     * @param array<string, Terminal> $portTerminals
     */
    private function calculateUtilizationMetrics(array $noas, array $portTerminals): array
    {
        $metrics = [];

        foreach ($noas as $noa) {
            $portCode = $noa->getPortLocation();
            if (!$portCode) {
                continue;
            }

            $terminal = $portTerminals[$portCode] ?? null;

            foreach ($noa->getContainers() as $container) {
                $shippingLine = $container->getShippingLine();
                $slId = $shippingLine?->getId() ?? 0;
                $slName = $shippingLine?->getBrandName() ?? 'Unassigned';

                $key = sprintf('%s_%d', $portCode, $slId);

                if (!isset($metrics[$key])) {
                    $dailyCapacity = $terminal?->getDailyCapacity() ?? 0;
                    $metrics[$key] = [
                        'terminal_id' => $terminal?->getId(),
                        'terminal_name' => $terminal?->getName() ?? $portCode,
                        'terminal_code' => $portCode,
                        'terminal_location' => $terminal?->getLocation() ?? $portCode,
                        'shipping_line_id' => $slId ?: null,
                        'shipping_line_name' => $slName,
                        'total_capacity_teu' => (float) $dailyCapacity,
                        'allocated_teu' => 0.0,
                        'container_count' => 0,
                        'capacity_20ft' => 0,
                        'capacity_40ft' => 0,
                        'allocated_20ft' => 0,
                        'allocated_40ft' => 0,
                    ];
                }

                $teu = $container->getContainerSize()->getTeuValue();
                $metrics[$key]['allocated_teu'] += $teu;
                $metrics[$key]['container_count']++;

                if ($teu == 1.0) {
                    $metrics[$key]['allocated_20ft']++;
                } elseif ($teu >= 2.0) {
                    $metrics[$key]['allocated_40ft']++;
                }
            }
        }

        foreach ($metrics as &$metric) {
            $metric['available_teu'] = max(0, $metric['total_capacity_teu'] - $metric['allocated_teu']);
            $metric['utilization_percentage'] = $metric['total_capacity_teu'] > 0
                ? ($metric['allocated_teu'] / $metric['total_capacity_teu']) * 100
                : 0.0;
            $metric['available_20ft'] = 0;
            $metric['available_40ft'] = 0;
            $metric['utilization_percentage_20ft'] = 0.0;
            $metric['utilization_percentage_40ft'] = 0.0;
        }

        return $metrics;
    }

    /**
     * @param NOA[] $noas
     */
    private function calculateUtilizationTrends(
        array $noas,
        \DateTime $startDate,
        \DateTime $endDate
    ): array {
        $teuByDate = [];
        foreach ($noas as $noa) {
            $date = $noa->getEta()->format('Y-m-d');
            foreach ($noa->getContainers() as $container) {
                $teuByDate[$date] = ($teuByDate[$date] ?? 0) + $container->getContainerSize()->getTeuValue();
            }
        }

        $trends = [];
        $cumulative = 0.0;
        $current = clone $startDate;
        while ($current <= $endDate) {
            $dateStr = $current->format('Y-m-d');
            $cumulative += $teuByDate[$dateStr] ?? 0;
            $trends[] = [
                'date' => $dateStr,
                'allocated_teu' => $cumulative,
            ];
            $current->modify('+1 day');
        }

        return $trends;
    }

    /**
     * @param NOA[] $noas
     */
    private function calculateStatusBreakdown(array $noas): array
    {
        $breakdown = [
            'pre_forecast' => ['count' => 0, 'teu' => 0.0],
            'allocated' => ['count' => 0, 'teu' => 0.0],
            'pending' => ['count' => 0, 'teu' => 0.0],
        ];

        foreach ($noas as $noa) {
            foreach ($noa->getContainers() as $container) {
                $teu = $container->getContainerSize()->getTeuValue();
                $status = $container->getAllocationStatus();

                if ($status === AllocationStatus::PRE_FORECAST) {
                    $breakdown['pre_forecast']['count']++;
                    $breakdown['pre_forecast']['teu'] += $teu;
                } elseif ($status === AllocationStatus::ALLOCATED) {
                    $breakdown['allocated']['count']++;
                    $breakdown['allocated']['teu'] += $teu;
                } else {
                    $breakdown['pending']['count']++;
                    $breakdown['pending']['teu'] += $teu;
                }
            }
        }

        return $breakdown;
    }

    public function exportToCSV(array $reportData, \DateTime $startDate, \DateTime $endDate): string
    {
        $tmpDir = $this->params->get('kernel.project_dir') . '/var/tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0777, true);
        }

        $filepath = $tmpDir . '/' . sprintf(
            'port_utilization_report_%s_to_%s.csv',
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d')
        );

        $handle = fopen($filepath, 'w');
        fputcsv($handle, ['Port Utilization Report']);
        fputcsv($handle, ['Date Range', $startDate->format('Y-m-d') . ' to ' . $endDate->format('Y-m-d')]);
        fputcsv($handle, []);
        fputcsv($handle, ['Projected Utilization by Port']);
        fputcsv($handle, [
            'Port', 'Code', 'Location', 'Shipping Line',
            'Daily Capacity (TEU)', 'Projected (TEU)', 'Available (TEU)', 'Utilization (%)',
            'Containers 20ft', 'Containers 40ft', 'Total Containers',
        ]);

        foreach ($reportData['utilization_by_location'] as $location) {
            fputcsv($handle, [
                $location['terminal_name'],
                $location['terminal_code'],
                $location['terminal_location'],
                $location['shipping_line_name'],
                number_format($location['total_capacity_teu'], 1),
                number_format($location['allocated_teu'], 1),
                number_format($location['available_teu'], 1),
                number_format($location['utilization_percentage'], 2),
                $location['allocated_20ft'],
                $location['allocated_40ft'],
                $location['container_count'],
            ]);
        }

        fclose($handle);

        return $filepath;
    }

    public function exportToPDF(array $reportData, \DateTime $startDate, \DateTime $endDate): string
    {
        $tmpDir = $this->params->get('kernel.project_dir') . '/var/tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0777, true);
        }

        $filepath = $tmpDir . '/' . sprintf(
            'port_utilization_report_%s_to_%s.pdf',
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d')
        );

        $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('Optimus System');
        $pdf->SetTitle('Port Utilization Report');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Port Utilization Report', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, sprintf('ETA Range: %s to %s', $startDate->format('Y-m-d'), $endDate->format('Y-m-d')), 0, 1, 'C');
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Projected Utilization by Port', 0, 1);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell(40, 7, 'Port', 1, 0, 'L', true);
        $pdf->Cell(35, 7, 'Shipping Line', 1, 0, 'L', true);
        $pdf->Cell(25, 7, 'Capacity', 1, 0, 'R', true);
        $pdf->Cell(25, 7, 'Projected', 1, 0, 'R', true);
        $pdf->Cell(20, 7, 'Util %', 1, 0, 'R', true);
        $pdf->Cell(20, 7, '20ft', 1, 0, 'R', true);
        $pdf->Cell(20, 7, '40ft', 1, 0, 'R', true);
        $pdf->Cell(20, 7, 'Total', 1, 1, 'R', true);

        $pdf->SetFont('helvetica', '', 7);
        foreach ($reportData['utilization_by_location'] as $location) {
            $pdf->Cell(40, 6, $location['terminal_name'], 1, 0, 'L');
            $pdf->Cell(35, 6, $location['shipping_line_name'], 1, 0, 'L');
            $pdf->Cell(25, 6, number_format($location['total_capacity_teu'], 1), 1, 0, 'R');
            $pdf->Cell(25, 6, number_format($location['allocated_teu'], 1), 1, 0, 'R');
            $pdf->Cell(20, 6, number_format($location['utilization_percentage'], 1) . '%', 1, 0, 'R');
            $pdf->Cell(20, 6, (string) $location['allocated_20ft'], 1, 0, 'R');
            $pdf->Cell(20, 6, (string) $location['allocated_40ft'], 1, 0, 'R');
            $pdf->Cell(20, 6, (string) $location['container_count'], 1, 1, 'R');
        }

        $pdf->Output($filepath, 'F');

        return $filepath;
    }
}
