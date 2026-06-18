<?php

namespace App\Service;

use App\Entity\Enum\DocumentTemplateType;
use App\Entity\NOA;
/**
 * Service for generating NOA PDF documents
 */
class NOADocumentGenerator
{
    public function __construct(
        private FileStorageServiceInterface $fileStorageService,
        private DompdfFactory $dompdfFactory,
        private DocumentTemplateBuilderService $templateBuilderService,
        private DocumentTemplatePdfGenerator $pdfGenerator,
        private DocumentTemplateContextBuilder $contextBuilder,
        private DocumentVerificationService $documentVerificationService,
    ) {
    }

    /**
     * @return string Path to generated PDF file
     */
    public function generatePDF(NOA $noa): string
    {
        $activeTemplate = $this->templateBuilderService->getActiveTemplate(DocumentTemplateType::NOA);

        if ($activeTemplate) {
            $noaId = $noa->getId();
            if ($noaId === null) {
                throw new \InvalidArgumentException('NOA must be persisted before generating a verification QR code.');
            }

            $context = $this->contextBuilder->buildNoaContext($noa);
            $context = $this->documentVerificationService->appendVerificationContext(
                $context,
                DocumentTemplateType::NOA,
                'noa',
                $noaId,
                $noa->getNoaNumber(),
                $this->buildVerificationSummary($context),
            );
            $pdfContent = $this->pdfGenerator->generatePdf($activeTemplate, $context);
        } else {
            $pdfContent = $this->renderLegacyPdf($noa);
        }

        $filename = sprintf('NOA_%s_%s.pdf', $noa->getNoaNumber(), date('YmdHis'));
        $tempPath = sys_get_temp_dir() . '/' . $filename;
        file_put_contents($tempPath, $pdfContent);

        $filePath = $this->fileStorageService->uploadFile(
            new \Symfony\Component\HttpFoundation\File\UploadedFile(
                $tempPath,
                $filename,
                'application/pdf',
                null,
                true
            ),
            'documents',
            'noa'
        );

        $noa->setPdfPath($filePath);
        @unlink($tempPath);

        return $filePath;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildVerificationSummary(array $context): array
    {
        return [
            'document_number' => $context['noa']['number'] ?? '',
            'bl_number' => $context['noa']['bl_number'] ?? '',
            'vessel_number' => $context['noa']['vessel_number'] ?? '',
            'eta' => $context['noa']['eta'] ?? '',
            'port_location' => $context['noa']['port_location'] ?? '',
            'consignee_name' => $context['consignee']['name'] ?? '',
            'container_count' => $context['noa']['container_count'] ?? '',
            'company_name' => $context['company']['name'] ?? '',
            'generated_at' => $context['generated']['date'] ?? date('Y-m-d H:i:s'),
        ];
    }

    private function renderLegacyPdf(NOA $noa): string
    {
        $html = $this->generateLegacyHTML($noa);
        $dompdf = $this->dompdfFactory->create();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function generateLegacyHTML(NOA $noa): string
    {
        $cyAllocationData = $this->getCYAllocationData($noa);

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Notice of Arrival - ' . htmlspecialchars($noa->getNoaNumber()) . '</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.6; color: #333; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        .header h1 { margin: 0; font-size: 24px; }
        .section { margin-bottom: 20px; }
        .section-title { font-weight: bold; font-size: 14px; margin-bottom: 10px; border-bottom: 1px solid #ccc; padding-bottom: 5px; }
        .info-row { margin-bottom: 5px; }
        .label { font-weight: bold; display: inline-block; width: 150px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table th, table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        table th { background-color: #f2f2f2; font-weight: bold; }
        .footer { margin-top: 40px; text-align: center; font-size: 10px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h1>NOTICE OF ARRIVAL</h1>
        <p>NOA Number: ' . htmlspecialchars($noa->getNoaNumber()) . '</p>
    </div>
    <div class="section">
        <div class="section-title">Shipment Information</div>
        <div class="info-row"><span class="label">BL Number:</span><span>' . htmlspecialchars($noa->getBlNumber()) . '</span></div>
        <div class="info-row"><span class="label">Vessel Number:</span><span>' . htmlspecialchars($noa->getVesselNumber()) . '</span></div>
        <div class="info-row"><span class="label">ETA:</span><span>' . $noa->getEta()->format('Y-m-d H:i') . '</span></div>
        <div class="info-row"><span class="label">Port Location:</span><span>' . htmlspecialchars($noa->getDischargeLocation()) . '</span></div>
    </div>
    <div class="section">
        <div class="section-title">Consignee Information</div>
        <div class="info-row"><span class="label">Consignee:</span><span>' . htmlspecialchars($noa->getConsignee()->getEmail()) . '</span></div>
    </div>
    <div class="section">
        <div class="section-title">Container Details</div>
        <table>
            <thead><tr><th>#</th><th>Container Number</th><th>Type</th><th>Size</th><th>TEU</th></tr></thead>
            <tbody>';

        $index = 1;
        foreach ($noa->getContainers() as $container) {
            $html .= '<tr>
                <td>' . $index++ . '</td>
                <td>' . htmlspecialchars($container->getContainerNumber()) . '</td>
                <td>' . htmlspecialchars($container->getContainerType()->getName()) . '</td>
                <td>' . htmlspecialchars($container->getContainerSize()->getName()) . '</td>
                <td>' . number_format($container->getContainerSize()->getTeuValue(), 1) . '</td>
            </tr>';
        }

        $html .= '</tbody></table></div>
    <div class="section">
        <div class="section-title">CY Allocation</div>
        <div class="info-row"><span class="label">Required TEU:</span><span>' . number_format($cyAllocationData['requiredTEU'], 1) . '</span></div>
        <div class="info-row"><span class="label">Available TEU:</span><span>' . number_format($cyAllocationData['availableTEU'], 1) . '</span></div>
        <div class="info-row"><span class="label">Remaining TEU:</span><span>' . number_format($cyAllocationData['remainingTEU'], 1) . '</span></div>
    </div>
    <div class="footer">
        <p>Generated on ' . date('Y-m-d H:i:s') . '</p>
        <p>This is an automatically generated document.</p>
    </div>
</body>
</html>';

        return $html;
    }

    private function getCYAllocationData(NOA $noa): array
    {
        $totalTEU = 0.0;
        foreach ($noa->getContainers() as $container) {
            $totalTEU += $container->getContainerSize()->getTeuValue();
        }

        $cyCapacities = [
            'CY-A' => 1000.0,
            'CY-B' => 1500.0,
            'CY-C' => 2000.0,
            'CY-NORTH' => 3000.0,
            'CY-SOUTH' => 2500.0,
        ];

        $availableTEU = $cyCapacities[$noa->getDischargeLocation()] ?? 0.0;

        return [
            'requiredTEU' => $totalTEU,
            'availableTEU' => $availableTEU,
            'remainingTEU' => $availableTEU - $totalTEU,
        ];
    }
}
