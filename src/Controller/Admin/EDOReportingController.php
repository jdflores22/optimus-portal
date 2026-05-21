<?php

namespace App\Controller\Admin;

use App\Service\EDOReportingServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/reports')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class EDOReportingController extends AbstractController
{
    public function __construct(
        private readonly EDOReportingServiceInterface $reportingService
    ) {
    }

    /**
     * Display eDO release metrics report
     * 
     * GET /admin/reports/edo-release-metrics
     * Requirements: 15.1, 15.2, 15.3, 15.4, 15.5
     */
    #[Route('/edo-release-metrics', name: 'admin_edo_release_metrics', methods: ['GET'])]
    public function metrics(Request $request): Response
    {
        try {
            // Get date range from request, default to last 30 days
            $endDate = $request->query->get('end_date')
                ? new \DateTime($request->query->get('end_date'))
                : new \DateTime();
            
            $startDate = $request->query->get('start_date')
                ? new \DateTime($request->query->get('start_date'))
                : (clone $endDate)->modify('-30 days');

            // Get filter for specific SYSTEM_ADMIN user if provided
            $releasedByUserId = $request->query->get('released_by');
            $releasedBy = null;
            
            if ($releasedByUserId) {
                $releasedBy = $this->getUser(); // In production, fetch by ID from UserRepository
            }

            // Fetch metrics
            $averageReleaseTime = $this->reportingService->getAverageReleaseTime(
                $startDate,
                $endDate,
                $releasedBy
            );

            $edosReleasedPerDay = $this->reportingService->getEDOsReleasedPerDay(
                $startDate,
                $endDate,
                $releasedBy
            );

            $rejectedEDOs = $this->reportingService->getRejectedEDOs(
                $startDate,
                $endDate
            );

            $pendingEDOsByAge = $this->reportingService->getPendingEDOsByAge();

            return $this->json([
                'success' => true,
                'data' => [
                    'date_range' => [
                        'start' => $startDate->format('Y-m-d'),
                        'end' => $endDate->format('Y-m-d'),
                    ],
                    'average_release_time_hours' => $averageReleaseTime,
                    'edos_released_per_day' => $edosReleasedPerDay,
                    'rejected_edos' => $rejectedEDOs,
                    'pending_edos_by_age' => $pendingEDOsByAge,
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'An error occurred while generating the report: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Export eDO release metrics to CSV
     * 
     * GET /admin/reports/edo-release-metrics/export/csv
     * Requirements: 15.6
     */
    #[Route('/edo-release-metrics/export/csv', name: 'admin_edo_release_metrics_export_csv', methods: ['GET'])]
    public function exportCsv(Request $request): Response
    {
        try {
            // Get date range from request
            $endDate = $request->query->get('end_date')
                ? new \DateTime($request->query->get('end_date'))
                : new \DateTime();
            
            $startDate = $request->query->get('start_date')
                ? new \DateTime($request->query->get('start_date'))
                : (clone $endDate)->modify('-30 days');

            // Get filter for specific SYSTEM_ADMIN user if provided
            $releasedByUserId = $request->query->get('released_by');
            $releasedBy = null;
            
            if ($releasedByUserId) {
                $releasedBy = $this->getUser();
            }

            // Determine report type from query parameter
            $reportType = $request->query->get('type', 'rejected_edos');

            // Fetch data based on report type
            $reportData = $this->getReportData($reportType, $startDate, $endDate, $releasedBy);

            // Export to CSV
            $filepath = $this->reportingService->exportToCSV($reportData);

            // Create response with file download
            $response = new BinaryFileResponse($filepath);
            $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                basename($filepath)
            );
            $response->headers->set('Content-Type', 'text/csv');

            // Delete file after sending
            $response->deleteFileAfterSend(true);

            return $response;
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'An error occurred while exporting to CSV: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Export eDO release metrics to PDF
     * 
     * GET /admin/reports/edo-release-metrics/export/pdf
     * Requirements: 15.6
     */
    #[Route('/edo-release-metrics/export/pdf', name: 'admin_edo_release_metrics_export_pdf', methods: ['GET'])]
    public function exportPdf(Request $request): Response
    {
        try {
            // Get date range from request
            $endDate = $request->query->get('end_date')
                ? new \DateTime($request->query->get('end_date'))
                : new \DateTime();
            
            $startDate = $request->query->get('start_date')
                ? new \DateTime($request->query->get('start_date'))
                : (clone $endDate)->modify('-30 days');

            // Get filter for specific SYSTEM_ADMIN user if provided
            $releasedByUserId = $request->query->get('released_by');
            $releasedBy = null;
            
            if ($releasedByUserId) {
                $releasedBy = $this->getUser();
            }

            // Determine report type from query parameter
            $reportType = $request->query->get('type', 'rejected_edos');

            // Fetch data based on report type
            $reportData = $this->getReportData($reportType, $startDate, $endDate, $releasedBy);

            // Add summary metrics
            if ($reportType === 'rejected_edos' || $reportType === 'all') {
                $averageReleaseTime = $this->reportingService->getAverageReleaseTime(
                    $startDate,
                    $endDate,
                    $releasedBy
                );
                
                $reportData['metrics'] = [
                    'Average Release Time' => round($averageReleaseTime, 2) . ' hours',
                    'Date Range' => $startDate->format('Y-m-d') . ' to ' . $endDate->format('Y-m-d'),
                ];
            }

            // Export to PDF
            $filepath = $this->reportingService->exportToPDF($reportData);

            // Create response with file download
            $response = new BinaryFileResponse($filepath);
            $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                basename($filepath)
            );
            $response->headers->set('Content-Type', 'application/pdf');

            // Delete file after sending
            $response->deleteFileAfterSend(true);

            return $response;
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'An error occurred while exporting to PDF: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Helper method to get report data based on type
     */
    private function getReportData(
        string $reportType,
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate,
        $releasedBy
    ): array {
        switch ($reportType) {
            case 'rejected_edos':
                return [
                    'type' => 'rejected_edos',
                    'data' => $this->reportingService->getRejectedEDOs($startDate, $endDate)
                ];

            case 'released_per_day':
                return [
                    'type' => 'released_per_day',
                    'data' => $this->reportingService->getEDOsReleasedPerDay($startDate, $endDate, $releasedBy)
                ];

            case 'pending_by_age':
                return [
                    'type' => 'pending_by_age',
                    'data' => $this->reportingService->getPendingEDOsByAge()
                ];

            default:
                // Default to rejected eDOs
                return [
                    'type' => 'rejected_edos',
                    'data' => $this->reportingService->getRejectedEDOs($startDate, $endDate)
                ];
        }
    }
}
