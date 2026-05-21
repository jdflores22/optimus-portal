<?php

namespace App\Service;

use App\Entity\Enum\EDOStatus;
use App\Entity\User;
use App\Repository\ElectronicDeliveryOrderRepository;
use Doctrine\ORM\EntityManagerInterface;

class EDOReportingService implements EDOReportingServiceInterface
{
    public function __construct(
        private readonly ElectronicDeliveryOrderRepository $edoRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $reportsDirectory
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getAverageReleaseTime(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate,
        ?User $releasedBy = null
    ): float {
        $qb = $this->edoRepository->createQueryBuilder('edo')
            ->select('AVG(TIMESTAMPDIFF(SECOND, edo.generatedAt, edo.releasedAt)) as avgSeconds')
            ->where('edo.status = :status')
            ->andWhere('edo.releasedAt IS NOT NULL')
            ->andWhere('edo.releasedAt BETWEEN :startDate AND :endDate')
            ->setParameter('status', EDOStatus::RELEASED)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate);

        if ($releasedBy !== null) {
            $qb->andWhere('edo.releasedBy = :releasedBy')
                ->setParameter('releasedBy', $releasedBy);
        }

        $result = $qb->getQuery()->getSingleScalarResult();

        // Convert seconds to hours
        return $result ? round($result / 3600, 2) : 0.0;
    }

    /**
     * {@inheritdoc}
     */
    public function getEDOsReleasedPerDay(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate,
        ?User $releasedBy = null
    ): array {
        $qb = $this->edoRepository->createQueryBuilder('edo')
            ->select('DATE(edo.releasedAt) as releaseDate, COUNT(edo.id) as count')
            ->where('edo.status = :status')
            ->andWhere('edo.releasedAt IS NOT NULL')
            ->andWhere('edo.releasedAt BETWEEN :startDate AND :endDate')
            ->setParameter('status', EDOStatus::RELEASED)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->groupBy('releaseDate')
            ->orderBy('releaseDate', 'ASC');

        if ($releasedBy !== null) {
            $qb->andWhere('edo.releasedBy = :releasedBy')
                ->setParameter('releasedBy', $releasedBy);
        }

        $results = $qb->getQuery()->getResult();

        return array_map(function ($row) {
            return [
                'date' => $row['releaseDate']->format('Y-m-d'),
                'count' => (int) $row['count']
            ];
        }, $results);
    }

    /**
     * {@inheritdoc}
     */
    public function getRejectedEDOs(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate
    ): array {
        $qb = $this->edoRepository->createQueryBuilder('edo')
            ->select('edo', 'm', 'rb')
            ->leftJoin('edo.manifest', 'm')
            ->leftJoin('edo.releasedBy', 'rb')
            ->where('edo.status = :status')
            ->andWhere('edo.generatedAt BETWEEN :startDate AND :endDate')
            ->setParameter('status', EDOStatus::REJECTED)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('edo.generatedAt', 'DESC');

        $edos = $qb->getQuery()->getResult();

        return array_map(function ($edo) {
            return [
                'edo_number' => $edo->getEdoNumber(),
                'manifest_number' => $edo->getManifest()->getManifestNumber(),
                'rejection_reason' => $edo->getRejectionReason(),
                'rejected_by' => $edo->getReleasedBy()?->getFullName(),
                'generated_at' => $edo->getGeneratedAt()->format('Y-m-d H:i:s'),
            ];
        }, $edos);
    }

    /**
     * {@inheritdoc}
     */
    public function getPendingEDOsByAge(): array {
        $now = new \DateTime();
        
        $qb = $this->edoRepository->createQueryBuilder('edo')
            ->select('edo.generatedAt')
            ->where('edo.status = :status')
            ->setParameter('status', EDOStatus::PENDING_RELEASE);

        $edos = $qb->getQuery()->getResult();

        $buckets = [
            '0-24h' => 0,
            '24-48h' => 0,
            '48h+' => 0
        ];

        foreach ($edos as $row) {
            $generatedAt = $row['generatedAt'];
            $ageInHours = ($now->getTimestamp() - $generatedAt->getTimestamp()) / 3600;

            if ($ageInHours <= 24) {
                $buckets['0-24h']++;
            } elseif ($ageInHours <= 48) {
                $buckets['24-48h']++;
            } else {
                $buckets['48h+']++;
            }
        }

        return $buckets;
    }

    /**
     * {@inheritdoc}
     */
    public function exportToCSV(array $reportData): string
    {
        $filename = 'edo_report_' . date('YmdHis') . '.csv';
        $filepath = $this->reportsDirectory . '/' . $filename;

        // Ensure directory exists
        if (!is_dir($this->reportsDirectory)) {
            mkdir($this->reportsDirectory, 0755, true);
        }

        $handle = fopen($filepath, 'w');

        if ($handle === false) {
            throw new \RuntimeException('Failed to create CSV file');
        }

        // Write headers based on report type
        if (isset($reportData['type'])) {
            switch ($reportData['type']) {
                case 'rejected_edos':
                    fputcsv($handle, ['eDO Number', 'Manifest Number', 'Rejection Reason', 'Rejected By', 'Generated At']);
                    foreach ($reportData['data'] as $row) {
                        fputcsv($handle, [
                            $row['edo_number'],
                            $row['manifest_number'],
                            $row['rejection_reason'],
                            $row['rejected_by'],
                            $row['generated_at']
                        ]);
                    }
                    break;

                case 'released_per_day':
                    fputcsv($handle, ['Date', 'Count']);
                    foreach ($reportData['data'] as $row) {
                        fputcsv($handle, [$row['date'], $row['count']]);
                    }
                    break;

                case 'pending_by_age':
                    fputcsv($handle, ['Age Bucket', 'Count']);
                    foreach ($reportData['data'] as $bucket => $count) {
                        fputcsv($handle, [$bucket, $count]);
                    }
                    break;

                default:
                    // Generic export
                    if (!empty($reportData['data'])) {
                        $firstRow = reset($reportData['data']);
                        fputcsv($handle, array_keys($firstRow));
                        foreach ($reportData['data'] as $row) {
                            fputcsv($handle, $row);
                        }
                    }
            }
        }

        fclose($handle);

        return $filepath;
    }

    /**
     * {@inheritdoc}
     */
    public function exportToPDF(array $reportData): string
    {
        $filename = 'edo_report_' . date('YmdHis') . '.pdf';
        $filepath = $this->reportsDirectory . '/' . $filename;

        // Ensure directory exists
        if (!is_dir($this->reportsDirectory)) {
            mkdir($this->reportsDirectory, 0755, true);
        }

        // Create new PDF document
        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        // Set document information
        $pdf->SetCreator('OPTIMUS Shipping Portal');
        $pdf->SetAuthor('System Administrator');
        $pdf->SetTitle('eDO Release Report');
        $pdf->SetSubject('eDO Release Metrics');

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
        $pdf->Cell(0, 10, 'eDO Release Report', 0, 1, 'C');
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 5, 'Generated: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
        $pdf->Ln(10);

        // Add report content based on type
        if (isset($reportData['type'])) {
            $pdf->SetFont('helvetica', 'B', 12);
            
            switch ($reportData['type']) {
                case 'rejected_edos':
                    $pdf->Cell(0, 8, 'Rejected eDOs', 0, 1, 'L');
                    $pdf->Ln(3);
                    $pdf->SetFont('helvetica', '', 9);
                    
                    // Table header
                    $pdf->SetFillColor(230, 230, 230);
                    $pdf->Cell(35, 7, 'eDO Number', 1, 0, 'L', true);
                    $pdf->Cell(35, 7, 'Manifest', 1, 0, 'L', true);
                    $pdf->Cell(60, 7, 'Rejection Reason', 1, 0, 'L', true);
                    $pdf->Cell(30, 7, 'Rejected By', 1, 0, 'L', true);
                    $pdf->Cell(30, 7, 'Generated At', 1, 1, 'L', true);
                    
                    // Table data
                    foreach ($reportData['data'] as $row) {
                        $pdf->Cell(35, 6, $row['edo_number'], 1, 0, 'L');
                        $pdf->Cell(35, 6, $row['manifest_number'], 1, 0, 'L');
                        $pdf->Cell(60, 6, substr($row['rejection_reason'], 0, 40), 1, 0, 'L');
                        $pdf->Cell(30, 6, $row['rejected_by'] ?? 'N/A', 1, 0, 'L');
                        $pdf->Cell(30, 6, $row['generated_at'], 1, 1, 'L');
                    }
                    break;

                case 'released_per_day':
                    $pdf->Cell(0, 8, 'eDOs Released Per Day', 0, 1, 'L');
                    $pdf->Ln(3);
                    $pdf->SetFont('helvetica', '', 9);
                    
                    // Table header
                    $pdf->SetFillColor(230, 230, 230);
                    $pdf->Cell(95, 7, 'Date', 1, 0, 'L', true);
                    $pdf->Cell(95, 7, 'Count', 1, 1, 'L', true);
                    
                    // Table data
                    foreach ($reportData['data'] as $row) {
                        $pdf->Cell(95, 6, $row['date'], 1, 0, 'L');
                        $pdf->Cell(95, 6, $row['count'], 1, 1, 'L');
                    }
                    break;

                case 'pending_by_age':
                    $pdf->Cell(0, 8, 'Pending eDOs By Age', 0, 1, 'L');
                    $pdf->Ln(3);
                    $pdf->SetFont('helvetica', '', 9);
                    
                    // Table header
                    $pdf->SetFillColor(230, 230, 230);
                    $pdf->Cell(95, 7, 'Age Bucket', 1, 0, 'L', true);
                    $pdf->Cell(95, 7, 'Count', 1, 1, 'L', true);
                    
                    // Table data
                    foreach ($reportData['data'] as $bucket => $count) {
                        $pdf->Cell(95, 6, $bucket, 1, 0, 'L');
                        $pdf->Cell(95, 6, $count, 1, 1, 'L');
                    }
                    break;
            }
        }

        // Add summary metrics if provided
        if (isset($reportData['metrics'])) {
            $pdf->Ln(10);
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 8, 'Summary Metrics', 0, 1, 'L');
            $pdf->Ln(3);
            $pdf->SetFont('helvetica', '', 10);
            
            foreach ($reportData['metrics'] as $label => $value) {
                $pdf->Cell(0, 6, $label . ': ' . $value, 0, 1, 'L');
            }
        }

        // Output PDF to file
        $pdf->Output($filepath, 'F');

        return $filepath;
    }
}
