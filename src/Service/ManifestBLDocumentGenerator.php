<?php

namespace App\Service;

use App\Entity\Enum\DocumentTemplateType;
use App\Entity\Manifest;
use App\Entity\NOA;

/**
 * Service for generating Manifest/BL PDF documents
 */
class ManifestBLDocumentGenerator
{
    public function __construct(
        private FileStorageServiceInterface $fileStorageService,
        private DocumentTemplateBuilderService $templateBuilderService,
        private DocumentTemplatePdfGenerator $pdfGenerator,
        private DocumentTemplateContextBuilder $contextBuilder,
        private DocumentVerificationService $documentVerificationService,
    ) {
    }

    /**
     * @return string Path to generated PDF file
     */
    public function generatePDF(NOA $noa, string $manifestNumber, ?Manifest $manifest = null): string
    {
        $activeTemplate = $this->templateBuilderService->getActiveTemplate(DocumentTemplateType::MANIFEST_BL);
        if (!$activeTemplate) {
            throw new \RuntimeException('No active Manifest/BL document template found. Please activate a MANIFEST_BL template.');
        }

        $noaId = $noa->getId();
        if ($noaId === null) {
            throw new \InvalidArgumentException('NOA must be persisted before generating a manifest verification QR code.');
        }

        $context = $this->contextBuilder->buildManifestBlContext($noa, $manifestNumber, $manifest);
        $documentNumber = $context['manifest']['number'] ?? $manifestNumber;
        $context = $this->documentVerificationService->appendVerificationContext(
            $context,
            DocumentTemplateType::MANIFEST_BL,
            'manifest_bl',
            $noaId,
            $documentNumber,
            $this->buildVerificationSummary($context),
        );
        $pdfContent = $this->pdfGenerator->generatePdf($activeTemplate, $context);

        $filename = sprintf('MANIFEST_BL_%s_%s.pdf', $noa->getBlNumber(), date('YmdHis'));
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
            'manifest'
        );

        $noa->setManifestPdfPath($filePath);
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
            'document_number' => $context['manifest']['number'] ?? '',
            'bl_number' => $context['manifest']['bl_number'] ?? $context['noa']['bl_number'] ?? '',
            'vessel_number' => $context['manifest']['vessel_name'] ?? $context['noa']['vessel_number'] ?? '',
            'eta' => $context['manifest']['arrival_date'] ?? $context['noa']['eta'] ?? '',
            'port_location' => $context['noa']['port_location'] ?? '',
            'consignee_name' => $context['consignee']['name'] ?? '',
            'container_count' => $context['manifest']['container_count'] ?? $context['noa']['container_count'] ?? '',
            'company_name' => $context['company']['name'] ?? '',
            'generated_at' => $context['generated']['date'] ?? date('Y-m-d H:i:s'),
        ];
    }

    private function renderLegacyPdf(NOA $noa, string $manifestNumber): string
    {
        $html = $this->generateHTML($noa, $manifestNumber);
        $dompdf = DompdfFactory::create();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Generate HTML content for Manifest/BL PDF
     * 
     * @param NOA $noa The NOA entity
     * @param string $manifestNumber The manifest/BL number
     * @return string HTML content
     */
    private function generateHTML(NOA $noa, string $manifestNumber): string
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manifest/Bill of Lading - ' . htmlspecialchars($noa->getBlNumber()) . '</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #000;
            margin: 0;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 3px solid #000;
            padding-bottom: 10px;
        }
        .header h1 {
            margin: 0 0 5px 0;
            font-size: 20px;
            font-weight: bold;
        }
        .header h2 {
            margin: 0;
            font-size: 16px;
            font-weight: normal;
        }
        .section {
            margin-bottom: 15px;
        }
        .section-title {
            font-weight: bold;
            font-size: 12px;
            margin-bottom: 8px;
            background-color: #f0f0f0;
            padding: 5px;
            border-left: 4px solid #333;
        }
        .info-grid {
            display: table;
            width: 100%;
            margin-bottom: 10px;
        }
        .info-row {
            display: table-row;
        }
        .info-label {
            display: table-cell;
            font-weight: bold;
            width: 150px;
            padding: 3px 5px;
            border-bottom: 1px solid #ddd;
        }
        .info-value {
            display: table-cell;
            padding: 3px 5px;
            border-bottom: 1px solid #ddd;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        table th, table td {
            border: 1px solid #333;
            padding: 6px;
            text-align: left;
        }
        table th {
            background-color: #333;
            color: white;
            font-weight: bold;
            font-size: 10px;
        }
        table td {
            font-size: 10px;
        }
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 2px solid #333;
            font-size: 9px;
            color: #666;
        }
        .signature-section {
            margin-top: 40px;
            display: table;
            width: 100%;
        }
        .signature-box {
            display: table-cell;
            width: 50%;
            padding: 10px;
        }
        .signature-line {
            border-top: 1px solid #000;
            margin-top: 50px;
            padding-top: 5px;
            text-align: center;
        }
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 80px;
            color: rgba(200, 200, 200, 0.3);
            z-index: -1;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>MANIFEST / BILL OF LADING</h1>
        <h2>Container Shipment Documentation</h2>
    </div>

    <div class="section">
        <div class="section-title">Document Information</div>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Manifest/BL Number:</div>
                <div class="info-value"><strong>' . htmlspecialchars($manifestNumber) . '</strong></div>
            </div>
            <div class="info-row">
                <div class="info-label">NOA Number:</div>
                <div class="info-value">' . htmlspecialchars($noa->getNoaNumber()) . '</div>
            </div>
            <div class="info-row">
                <div class="info-label">B/L Number:</div>
                <div class="info-value">' . htmlspecialchars($noa->getBlNumber()) . '</div>
            </div>
            <div class="info-row">
                <div class="info-label">Issue Date:</div>
                <div class="info-value">' . date('F j, Y') . '</div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Vessel Information</div>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Vessel Number:</div>
                <div class="info-value">' . htmlspecialchars($noa->getVesselNumber()) . '</div>
            </div>
            <div class="info-row">
                <div class="info-label">ETA:</div>
                <div class="info-value">' . $noa->getEta()->format('F j, Y H:i') . '</div>
            </div>
            <div class="info-row">
                <div class="info-label">Port Location:</div>
                <div class="info-value">' . htmlspecialchars($noa->getDischargeLocation()) . '</div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Consignee Information</div>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Business Name:</div>
                <div class="info-value">' . htmlspecialchars($noa->getConsignee()->getBusinessName()) . '</div>
            </div>
            <div class="info-row">
                <div class="info-label">Email:</div>
                <div class="info-value">' . htmlspecialchars($noa->getConsignee()->getEmail()) . '</div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Container Details</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 25%;">Container Number</th>
                    <th style="width: 20%;">Type</th>
                    <th style="width: 15%;">Size</th>
                    <th style="width: 10%;">TEU</th>
                    <th style="width: 25%;">CY Allocation</th>
                </tr>
            </thead>
            <tbody>';

        $index = 1;
        $totalTEU = 0;
        foreach ($noa->getContainers() as $container) {
            $teu = $container->getContainerSize()->getTeuValue();
            $totalTEU += $teu;
            
            $html .= '<tr>
                    <td style="text-align: center;">' . $index++ . '</td>
                    <td><strong>' . htmlspecialchars($container->getContainerNumber()) . '</strong></td>
                    <td>' . htmlspecialchars($container->getContainerType()->getName()) . '</td>
                    <td>' . htmlspecialchars($container->getContainerSize()->getName()) . '</td>
                    <td style="text-align: center;">' . number_format($teu, 1) . '</td>
                    <td>' . htmlspecialchars($noa->getDischargeLocation()) . '</td>
                </tr>';
        }

        $html .= '
                <tr style="background-color: #f0f0f0; font-weight: bold;">
                    <td colspan="4" style="text-align: right;">TOTAL:</td>
                    <td style="text-align: center;">' . number_format($totalTEU, 1) . '</td>
                    <td>' . count($noa->getContainers()) . ' Container(s)</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="signature-section">
        <div class="signature-box">
            <div class="signature-line">
                <strong>Shipping Line Representative</strong><br>
                Name & Signature
            </div>
        </div>
        <div class="signature-box">
            <div class="signature-line">
                <strong>Consignee Representative</strong><br>
                Name & Signature
            </div>
        </div>
    </div>

    <div class="footer">
        <p><strong>Document Generated:</strong> ' . date('F j, Y \a\t H:i:s') . '</p>
        <p><strong>Generated By:</strong> ' . htmlspecialchars($noa->getCreatedBy()->getFullName()) . ' (' . htmlspecialchars($noa->getCreatedBy()->getEmail()) . ')</p>
        <p style="margin-top: 10px; font-size: 8px;">
            This is an automatically generated document. Any alterations or modifications without proper authorization are strictly prohibited.
            This document serves as official proof of shipment and container allocation.
        </p>
    </div>
</body>
</html>';

        return $html;
    }
}
