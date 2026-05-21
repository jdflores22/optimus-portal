<?php

namespace App\Service;

use App\Entity\NOA;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Service for generating Manifest/BL PDF documents
 */
class ManifestBLDocumentGenerator
{
    public function __construct(
        private FileStorageServiceInterface $fileStorageService
    ) {
    }

    /**
     * Generate Manifest/BL PDF document for NOA
     * 
     * @param NOA $noa The NOA entity
     * @param string $manifestNumber The manifest/BL number to include in the PDF
     * @return string Path to generated PDF file
     */
    public function generatePDF(NOA $noa, string $manifestNumber): string
    {
        try {
            error_log('ManifestBLDocumentGenerator: Starting PDF generation');
            
            // Generate HTML content
            error_log('ManifestBLDocumentGenerator: Generating HTML');
            $html = $this->generateHTML($noa, $manifestNumber);
            error_log('ManifestBLDocumentGenerator: HTML generated, length: ' . strlen($html));

            // Create PDF using Dompdf
            error_log('ManifestBLDocumentGenerator: Creating Dompdf instance');
            $dompdf = DompdfFactory::create();
            error_log('ManifestBLDocumentGenerator: Dompdf created');
            
            error_log('ManifestBLDocumentGenerator: Loading HTML');
            $dompdf->loadHtml($html);
            error_log('ManifestBLDocumentGenerator: Setting paper size');
            $dompdf->setPaper('A4', 'portrait');
            error_log('ManifestBLDocumentGenerator: Rendering PDF');
            $dompdf->render();
            error_log('ManifestBLDocumentGenerator: PDF rendered');

            // Generate filename
            $filename = sprintf('MANIFEST_BL_%s_%s.pdf', $noa->getBlNumber(), date('YmdHis'));
            error_log('ManifestBLDocumentGenerator: Filename: ' . $filename);

            // Save PDF to temporary file
            $tempPath = sys_get_temp_dir() . '/' . $filename;
            error_log('ManifestBLDocumentGenerator: Temp path: ' . $tempPath);
            
            file_put_contents($tempPath, $dompdf->output());
            error_log('ManifestBLDocumentGenerator: PDF saved to temp file, size: ' . filesize($tempPath));

            // Create UploadedFile from temporary file
            error_log('ManifestBLDocumentGenerator: Creating UploadedFile');
            $uploadedFile = new \Symfony\Component\HttpFoundation\File\UploadedFile(
                $tempPath,
                $filename,
                'application/pdf',
                null,
                true
            );
            error_log('ManifestBLDocumentGenerator: UploadedFile created');

            // Upload to storage
            error_log('ManifestBLDocumentGenerator: Uploading to storage');
            $filePath = $this->fileStorageService->uploadFile(
                $uploadedFile,
                'documents',
                'manifest'
            );
            error_log('ManifestBLDocumentGenerator: File uploaded, path: ' . $filePath);

            // Save PDF path to NOA entity
            error_log('ManifestBLDocumentGenerator: Setting manifest PDF path on NOA entity');
            $noa->setManifestPdfPath($filePath);
            error_log('ManifestBLDocumentGenerator: Manifest PDF path set');

            // Clean up temporary file
            @unlink($tempPath);
            error_log('ManifestBLDocumentGenerator: Temp file cleaned up');

            return $filePath;
        } catch (\Exception $e) {
            error_log('ManifestBLDocumentGenerator ERROR: ' . $e->getMessage());
            error_log('ManifestBLDocumentGenerator ERROR File: ' . $e->getFile() . ' Line: ' . $e->getLine());
            error_log('ManifestBLDocumentGenerator ERROR Stack: ' . $e->getTraceAsString());
            throw $e;
        }
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
                <div class="info-label">CY Location:</div>
                <div class="info-value">' . htmlspecialchars($noa->getCyLocation()) . '</div>
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
                    <td>' . htmlspecialchars($noa->getCyLocation()) . '</td>
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
