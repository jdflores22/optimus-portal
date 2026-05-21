<?php

namespace App\Controller\Admin;

use App\Service\CYUtilizationReportServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller for CY utilization reporting and analytics
 * 
 * Requirements: 12.1, 12.2, 12.3, 12.4, 12.5
 */
#[Route('/admin/reports/cy-utilization')]
#[IsGranted('ROLE_SHIPPING_LINE_MANAGER')]
class CYUtilizationReportController extends AbstractController
{
    public function __construct(
        private readonly CYUtilizationReportServiceInterface $reportService
    ) {
    }

    /**
     * Display CY utilization report view
     * 
     * GET /admin/reports/cy-utilization
     * Requirements: 12.1, 12.2, 12.3
     */
    #[Route('', name: 'admin_cy_utilization_report', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/cy_utilization/report.html.twig');
    }

    /**
     * Get CY utilization report data (AJAX endpoint)
     * 
     * GET /admin/reports/cy-utilization/data
     * Requirements: 12.1, 12.2, 12.5
     */
    #[Route('/data', name: 'admin_cy_utilization_report_data', methods: ['GET'])]
    public function getData(Request $request): Response
    {
        try {
            // Get date range from request, default to last 30 days
            $endDate = $request->query->get('end_date')
                ? new \DateTime($request->query->get('end_date'))
                : new \DateTime();
            
            $startDate = $request->query->get('start_date')
                ? new \DateTime($request->query->get('start_date'))
                : (clone $endDate)->modify('-30 days');

            // Get optional filters
            $shippingLineId = $request->query->get('shipping_line_id');
            $terminalId = $request->query->get('terminal_id');

            // Generate report data
            $reportData = $this->reportService->generateReport(
                $startDate,
                $endDate,
                $shippingLineId,
                $terminalId
            );

            return $this->json([
                'success' => true,
                'data' => $reportData
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'An error occurred while generating the report: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Export CY utilization report to CSV
     * 
     * GET /admin/reports/cy-utilization/export/csv
     * Requirements: 12.4
     */
    #[Route('/export/csv', name: 'admin_cy_utilization_export_csv', methods: ['GET'])]
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

            // Get optional filters
            $shippingLineId = $request->query->get('shipping_line_id');
            $terminalId = $request->query->get('terminal_id');

            // Generate report data
            $reportData = $this->reportService->generateReport(
                $startDate,
                $endDate,
                $shippingLineId,
                $terminalId
            );

            // Export to CSV
            $filepath = $this->reportService->exportToCSV($reportData, $startDate, $endDate);

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
     * Export CY utilization report to PDF
     * 
     * GET /admin/reports/cy-utilization/export/pdf
     * Requirements: 12.4
     */
    #[Route('/export/pdf', name: 'admin_cy_utilization_export_pdf', methods: ['GET'])]
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

            // Get optional filters
            $shippingLineId = $request->query->get('shipping_line_id');
            $terminalId = $request->query->get('terminal_id');

            // Generate report data
            $reportData = $this->reportService->generateReport(
                $startDate,
                $endDate,
                $shippingLineId,
                $terminalId
            );

            // Export to PDF
            $filepath = $this->reportService->exportToPDF($reportData, $startDate, $endDate);

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
}
