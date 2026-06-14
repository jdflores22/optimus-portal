<?php

namespace App\Controller\Admin;

use App\Repository\ShippingLineRepository;
use App\Repository\TerminalRepository;
use App\Service\PortUtilizationReportServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/reports/port-utilization')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class PortUtilizationReportController extends AbstractController
{
    public function __construct(
        private readonly PortUtilizationReportServiceInterface $reportService
    ) {
    }

    #[Route('', name: 'admin_port_utilization_report', methods: ['GET'])]
    public function index(
        ShippingLineRepository $shippingLineRepository,
        TerminalRepository $terminalRepository,
    ): Response {
        return $this->render('admin/port_utilization/report.html.twig', [
            'shippingLines' => $shippingLineRepository->findActive(),
            'ports' => $terminalRepository->findActivePorts(),
            'defaultStartDate' => (new \DateTime('-30 days'))->format('Y-m-d'),
            'defaultEndDate' => (new \DateTime())->format('Y-m-d'),
        ]);
    }

    #[Route('/data', name: 'admin_port_utilization_report_data', methods: ['GET'])]
    public function getData(Request $request): Response
    {
        try {
            [$startDate, $endDate, $shippingLineId, $terminalId] = $this->parseReportFilters($request);

            $reportData = $this->reportService->generateReport(
                $startDate,
                $endDate,
                $shippingLineId,
                $terminalId
            );

            return $this->json(['success' => true, 'data' => $reportData]);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => 'An error occurred while generating the report: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/export/csv', name: 'admin_port_utilization_export_csv', methods: ['GET'])]
    public function exportCsv(Request $request): Response
    {
        try {
            [$startDate, $endDate, $shippingLineId, $terminalId] = $this->parseReportFilters($request);
            $reportData = $this->reportService->generateReport($startDate, $endDate, $shippingLineId, $terminalId);
            $filepath = $this->reportService->exportToCSV($reportData, $startDate, $endDate);

            $response = new BinaryFileResponse($filepath);
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($filepath));
            $response->headers->set('Content-Type', 'text/csv');
            $response->deleteFileAfterSend(true);

            return $response;
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'An error occurred while exporting to CSV: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/export/pdf', name: 'admin_port_utilization_export_pdf', methods: ['GET'])]
    public function exportPdf(Request $request): Response
    {
        try {
            [$startDate, $endDate, $shippingLineId, $terminalId] = $this->parseReportFilters($request);
            $reportData = $this->reportService->generateReport($startDate, $endDate, $shippingLineId, $terminalId);
            $filepath = $this->reportService->exportToPDF($reportData, $startDate, $endDate);

            $response = new BinaryFileResponse($filepath);
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($filepath));
            $response->headers->set('Content-Type', 'application/pdf');
            $response->deleteFileAfterSend(true);

            return $response;
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'An error occurred while exporting to PDF: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @return array{0: \DateTime, 1: \DateTime, 2: ?int, 3: ?int}
     */
    private function parseReportFilters(Request $request): array
    {
        $endDate = $request->query->get('end_date')
            ? new \DateTime($request->query->get('end_date'))
            : new \DateTime();
        $endDate->setTime(23, 59, 59);

        $startDate = $request->query->get('start_date')
            ? new \DateTime($request->query->get('start_date'))
            : (clone $endDate)->modify('-30 days');
        $startDate->setTime(0, 0, 0);

        $shippingLineId = $request->query->get('shipping_line_id');
        $terminalId = $request->query->get('terminal_id');

        return [
            $startDate,
            $endDate,
            ($shippingLineId !== null && $shippingLineId !== '') ? (int) $shippingLineId : null,
            ($terminalId !== null && $terminalId !== '') ? (int) $terminalId : null,
        ];
    }
}
