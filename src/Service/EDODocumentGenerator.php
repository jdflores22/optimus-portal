<?php

namespace App\Service;

use App\Entity\ElectronicDeliveryOrder;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service for generating eDO PDF documents
 */
class EDODocumentGenerator
{
    public function __construct(
        private FileStorageServiceInterface $fileStorageService,
        private DompdfFactory $dompdfFactory
    ) {
    }

    /**
     * Generate PDF document for multiple eDOs (bulk generation)
     * All eDOs will share the same PDF file
     * 
     * @param array $edos Array of ElectronicDeliveryOrder entities
     * @return string Path to generated PDF file
     */
    public function generateBulkPDF(array $edos): string
    {
        if (empty($edos)) {
            throw new \InvalidArgumentException('Cannot generate PDF for empty eDO array');
        }

        // Generate HTML content with all containers
        $html = $this->generateBulkHTML($edos);

        // Create PDF using Dompdf
        $dompdf = $this->dompdfFactory->create();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        // Generate filename using first eDO number
        $firstEdo = $edos[0];
        $filename = sprintf('EDO_BULK_%s_%s.pdf', $firstEdo->getManifest()->getManifestNumber(), date('YmdHis'));

        // Save PDF to temporary file
        $tempPath = sys_get_temp_dir() . '/' . $filename;
        file_put_contents($tempPath, $dompdf->output());

        // Upload to storage
        $filePath = $this->fileStorageService->uploadFile(
            new UploadedFile(
                $tempPath,
                $filename,
                'application/pdf',
                null,
                true
            ),
            'documents',
            'edo'
        );

        // Clean up temporary file
        @unlink($tempPath);

        return $filePath;
    }

    /**
     * Generate PDF document for eDO
     * 
     * @param ElectronicDeliveryOrder $edo The eDO entity
     * @return string Path to generated PDF file
     */
    public function generatePDF(ElectronicDeliveryOrder $edo): string
    {
        // Generate HTML content
        $html = $this->generateHTML($edo);

        // Create PDF using Dompdf
        $dompdf = $this->dompdfFactory->create();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        // Generate filename
        $filename = sprintf('EDO_%s_%s.pdf', $edo->getEdoNumber(), date('YmdHis'));

        // Save PDF to temporary file
        $tempPath = sys_get_temp_dir() . '/' . $filename;
        file_put_contents($tempPath, $dompdf->output());

        // Upload to storage
        $filePath = $this->fileStorageService->uploadFile(
            new UploadedFile(
                $tempPath,
                $filename,
                'application/pdf',
                null,
                true
            ),
            'documents',
            'edo'
        );

        // Clean up temporary file
        @unlink($tempPath);

        return $filePath;
    }

    /**
     * Generate HTML content for eDO PDF
     * 
     * @param ElectronicDeliveryOrder $edo The eDO entity
     * @return string HTML content
     */
    private function generateHTML(ElectronicDeliveryOrder $edo): string
    {
        $container = $edo->getContainer();
        $manifest = $edo->getManifest();
        $noa = $manifest->getNoa();
        $shippingLine = $edo->getShippingLine();
        $consignee = $manifest->getConsignee();
        $broker = $manifest->getBroker();
        
        // Get CY allocation details
        $cyAllocation = $container->getCyAllocation();
        $returnLocation = 'N/A';
        if ($cyAllocation !== null) {
            $terminal = $cyAllocation->getTerminal();
            $returnLocation = htmlspecialchars($terminal->getName());
        }
        
        // Format demurrage validity (expiration date)
        $demurrageValidity = $edo->getExpiresAt()->format('d-M-Y');
        
        // Get container details
        $containerNumber = htmlspecialchars($container->getContainerNumber());
        $containerSize = $container->getContainerSize() ? $container->getContainerSize()->getName() : 'N/A';
        $containerType = $container->getContainerType() ? $container->getContainerType()->getCode() : 'N/A';
        
        // Hauler and plate information (not yet implemented in system)
        $haulerName = '';
        $plateNumber = '';
        
        // Get consignee/notify party
        $consigneeName = htmlspecialchars($consignee->getBusinessName() ?? $consignee->getEmail());
        
        // Get shipping line name
        $shippingLineName = htmlspecialchars($shippingLine->getBrandName());
        
        // Get registry number (NOA number)
        $registryNumber = htmlspecialchars($noa->getNoaNumber());
        
        // Get vessel and voyage
        $vesselName = htmlspecialchars($noa->getVesselNumber());
        $voyageNumber = $manifest->getVoyageNumber() ? htmlspecialchars($manifest->getVoyageNumber()) : 'N/A';
        
        // Get BL number
        $blNumber = htmlspecialchars($noa->getBlNumber());
        
        // Get broker name
        $brokerName = $broker ? htmlspecialchars($broker->getFullName()) : 'N/A';
        
        // OPTIMUS reference number (eDO number)
        $optimusRefNo = htmlspecialchars($edo->getEdoNumber());
        
        // Seal status (not yet implemented in system)
        $sealStatus = '';
        
        // Current date/time for signature
        $currentDateTime = date('d-M-Y H:i');
        
        // Authorized by (can be made dynamic)
        $authorizedBy = 'Authorized Representative';
        $authorizedByCompany = htmlspecialchars($shippingLine->getBrandName());

        $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>OPTIMUS | Electronic Delivery Order</title>
<style>
@page {
    margin: 0;
}
body {
    font-family: Arial, sans-serif;
    background-color: #ffffff;
    margin: 0;
    padding: 0;
    color: #334155;
}
.order-card {
    width: 100%;
    max-width: 100%;
    margin: 0;
    background: #ffffff;
}
/* Header */
.header {
    background-color: #0f172a;
    padding: 25px 35px;
    color: white;
}
.header table {
    width: 100%;
    border-collapse: collapse;
}
.brand-box {
    width: 50%;
    vertical-align: middle;
}
.brand-box h1 {
    margin: 0;
    font-size: 20px;
    letter-spacing: 3px;
    font-weight: 900;
}
.brand-box span {
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #3b82f6;
}
.doc-label {
    width: 50%;
    text-align: right;
    vertical-align: middle;
}
.doc-label h2 {
    margin: 0 0 3px 0;
    font-size: 14px;
    font-weight: 400;
    opacity: 0.9;
    text-transform: uppercase;
}
/* Summary Bar */
.summary-bar {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 12px 35px;
}
.summary-bar table {
    width: 100%;
    border-collapse: collapse;
}
.summary-item {
    width: 33.33%;
    vertical-align: top;
}
.summary-item.center {
    text-align: center;
}
.summary-item.right {
    text-align: right;
}
.summary-label {
    font-size: 8px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    display: block;
}
.summary-val {
    font-size: 11px;
    font-weight: 600;
    color: #0f172a;
}
/* Content */
.content {
    padding: 25px 35px;
}
.info-section {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 25px;
}
.info-section td {
    border-bottom: 1px solid #f1f5f9;
    padding: 8px 10px 8px 0;
    vertical-align: top;
}
.info-section td.highlight {
    background: #f8fafc;
    padding: 10px;
    border-bottom: 2px solid #0f172a;
}
.info-label {
    font-size: 9px;
    color: #94a3b8;
    font-weight: 600;
    text-transform: uppercase;
    display: block;
    margin-bottom: 2px;
}
.info-val {
    font-size: 12px;
    color: #0f172a;
    font-weight: 500;
    text-transform: uppercase;
}
.info-val-large {
    font-size: 15px;
    font-weight: 800;
}
/* Table */
.table-container {
    border: 1px solid #000;
}
table.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 10px;
}
.data-table th {
    background: #f2f2f2;
    text-align: center;
    padding: 10px 4px;
    color: #000;
    font-weight: 700;
    text-transform: uppercase;
    border: 1px solid #000;
}
.data-table td {
    padding: 10px 4px;
    border: 1px solid #000;
    color: #000;
    text-align: center;
}
.bold-data {
    font-weight: 700;
}
.accent-data {
    color: #3b82f6;
    font-weight: 700;
}
/* Footer */
.footer {
    background: #f8fafc;
    padding: 25px 35px;
    border-top: 1px solid #e2e8f0;
}
.note-box {
    font-size: 10px;
    line-height: 1.6;
    color: #475569;
    margin-bottom: 25px;
}
.sig-row {
    width: 100%;
    border-collapse: collapse;
    margin-top: 35px;
}
.sig-col {
    width: 33.33%;
    border-top: 2px solid #0f172a;
    padding-top: 8px;
    text-align: center;
    font-size: 9px;
    font-weight: 700;
    color: #0f172a;
    text-transform: uppercase;
}
</style>
</head>
<body>
<div class="order-card">
    <div class="header">
        <table>
            <tr>
                <td class="brand-box">
                    <h1>OPTIMUS</h1>
                    <span>Maritime Logistics Platform</span>
                </td>
                <td class="doc-label">
                    <h2>Electronic Delivery Order</h2>
                    <h2>Container Release Order</h2>
                </td>
            </tr>
        </table>
    </div>
    
    <div class="summary-bar">
        <table>
            <tr>
                <td class="summary-item">
                    <span class="summary-label">BL Number</span>
                    <span class="summary-val">' . $blNumber . '</span>
                </td>
                <td class="summary-item center">
                    <span class="summary-label">Registry Number</span>
                    <span class="summary-val">' . $registryNumber . '</span>
                </td>
                <td class="summary-item right">
                    <span class="summary-label">Reference No.</span>
                    <span class="summary-val">' . $optimusRefNo . '</span>
                </td>
            </tr>
        </table>
    </div>
    
    <div class="content">
        <table class="info-section">
            <tr>
                <td colspan="3" class="highlight">
                    <span class="info-label">Consignee/Notify Party</span>
                    <div class="info-val info-val-large">' . $consigneeName . '</div>
                </td>
            </tr>
            <tr>
                <td style="width: 33.33%;">
                    <span class="info-label">Shipping Line/Carrier</span>
                    <div class="info-val">' . $shippingLineName . '</div>
                </td>
                <td style="width: 33.33%;">
                    <span class="info-label">Vessel/Voyage Number</span>
                    <div class="info-val">' . $vesselName . ' / ' . $voyageNumber . '</div>
                </td>
                <td style="width: 33.33%;">
                    <span class="info-label">Return Empty To</span>
                    <div class="info-val">' . $returnLocation . '</div>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="info-label">Name of Broker</span>
                    <div class="info-val">' . $brokerName . '</div>
                </td>
                <td>
                    <span class="info-label">Document Status</span>
                    <div class="info-val">ELECTRONIC RELEASE</div>
                </td>
                <td>
                    <span class="info-label">Print Date/Time</span>
                    <div class="info-val">' . strtoupper($currentDateTime) . '</div>
                </td>
            </tr>
        </table>
        
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 35px;">No.</th>
                        <th>Container Number</th>
                        <th style="width: 45px;">Size</th>
                        <th style="width: 45px;">Type</th>
                        <th style="width: 60px;">Seal</th>
                        <th>Name of Hauler</th>
                        <th style="width: 80px;">Plate No.</th>
                        <th>OPTIMUS Ref No.</th>
                        <th>Demurrage Validity</th>
                        <th>Return Empty To</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>1</td>
                        <td class="bold-data">' . $containerNumber . '</td>
                        <td>' . $containerSize . '</td>
                        <td>' . $containerType . '</td>
                        <td>' . ($sealStatus ?: '-') . '</td>
                        <td>' . ($haulerName ?: '-') . '</td>
                        <td>' . ($plateNumber ?: '-') . '</td>
                        <td class="bold-data">' . $optimusRefNo . '</td>
                        <td class="accent-data">' . strtoupper($demurrageValidity) . '</td>
                        <td>' . $returnLocation . '</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="footer">
        <div class="note-box">
            <strong>PORT OPERATIONS DIRECTIVE:</strong><br>
            Please release the above cargo container to the Consignee/Broker/Hauler. Free Demurrage time is valid until 2400H of the specified validity date. Pre-advise notice is mandatory for container returns to MICT/ATI to prevent shut out fees.
        </div>
        
        <table class="sig-row">
            <tr>
                <td class="sig-col">Authorized Representative<br>' . $authorizedByCompany . '</td>
                <td class="sig-col">Prepared By<br>OPTIMUS SYSTEM</td>
                <td class="sig-col">Date/Time Received</td>
            </tr>
        </table>
    </div>
</div>
</body>
</html>';

        return $html;
    }

    /**
     * Generate HTML content for bulk eDO PDF (multiple containers)
     * 
     * @param array $edos Array of ElectronicDeliveryOrder entities
     * @return string HTML content
     */
    private function generateBulkHTML(array $edos): string
    {
        $firstEdo = $edos[0];
        $manifest = $firstEdo->getManifest();
        $noa = $manifest->getNoa();
        $shippingLine = $firstEdo->getShippingLine();
        $consignee = $manifest->getConsignee();
        $broker = $manifest->getBroker();
        
        // Get common information
        $consigneeName = htmlspecialchars($consignee->getBusinessName() ?? $consignee->getEmail());
        $shippingLineName = htmlspecialchars($shippingLine->getBrandName());
        $registryNumber = htmlspecialchars($noa->getNoaNumber());
        $vesselName = htmlspecialchars($noa->getVesselNumber());
        $voyageNumber = $manifest->getVoyageNumber() ? htmlspecialchars($manifest->getVoyageNumber()) : 'N/A';
        $blNumber = htmlspecialchars($noa->getBlNumber());
        $brokerName = $broker ? htmlspecialchars($broker->getFullName()) : 'N/A';
        $currentDateTime = date('d-M-Y H:i');
        $authorizedBy = 'Authorized Representative';
        $authorizedByCompany = htmlspecialchars($shippingLine->getBrandName());

        $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>OPTIMUS | Electronic Delivery Order (Bulk)</title>
<style>
@page {
    margin: 0;
}
body {
    font-family: Arial, sans-serif;
    background-color: #ffffff;
    margin: 0;
    padding: 0;
    color: #334155;
}
.order-card {
    width: 100%;
    max-width: 100%;
    margin: 0;
    background: #ffffff;
}
/* Header */
.header {
    background-color: #0f172a;
    padding: 25px 35px;
    color: white;
}
.header table {
    width: 100%;
    border-collapse: collapse;
}
.brand-box {
    width: 50%;
    vertical-align: middle;
}
.brand-box h1 {
    margin: 0;
    font-size: 20px;
    letter-spacing: 3px;
    font-weight: 900;
}
.brand-box span {
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #3b82f6;
}
.doc-label {
    width: 50%;
    text-align: right;
    vertical-align: middle;
}
.doc-label h2 {
    margin: 0 0 3px 0;
    font-size: 14px;
    font-weight: 400;
    opacity: 0.9;
    text-transform: uppercase;
}
/* Summary Bar */
.summary-bar {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 12px 35px;
}
.summary-bar table {
    width: 100%;
    border-collapse: collapse;
}
.summary-item {
    width: 33.33%;
    vertical-align: top;
}
.summary-item.center {
    text-align: center;
}
.summary-item.right {
    text-align: right;
}
.summary-label {
    font-size: 8px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    display: block;
}
.summary-val {
    font-size: 11px;
    font-weight: 600;
    color: #0f172a;
}
/* Content */
.content {
    padding: 25px 35px;
}
.info-section {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 25px;
}
.info-section td {
    border-bottom: 1px solid #f1f5f9;
    padding: 8px 10px 8px 0;
    vertical-align: top;
}
.info-section td.highlight {
    background: #f8fafc;
    padding: 10px;
    border-bottom: 2px solid #0f172a;
}
.info-label {
    font-size: 9px;
    color: #94a3b8;
    font-weight: 600;
    text-transform: uppercase;
    display: block;
    margin-bottom: 2px;
}
.info-val {
    font-size: 12px;
    color: #0f172a;
    font-weight: 500;
    text-transform: uppercase;
}
.info-val-large {
    font-size: 15px;
    font-weight: 800;
}
/* Table */
.table-container {
    border: 1px solid #000;
}
table.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 10px;
}
.data-table th {
    background: #f2f2f2;
    text-align: center;
    padding: 10px 4px;
    color: #000;
    font-weight: 700;
    text-transform: uppercase;
    border: 1px solid #000;
}
.data-table td {
    padding: 10px 4px;
    border: 1px solid #000;
    color: #000;
    text-align: center;
}
.bold-data {
    font-weight: 700;
}
.accent-data {
    color: #3b82f6;
    font-weight: 700;
}
/* Footer */
.footer {
    background: #f8fafc;
    padding: 25px 35px;
    border-top: 1px solid #e2e8f0;
}
.note-box {
    font-size: 10px;
    line-height: 1.6;
    color: #475569;
    margin-bottom: 25px;
}
.sig-row {
    width: 100%;
    border-collapse: collapse;
    margin-top: 35px;
}
.sig-col {
    width: 33.33%;
    border-top: 2px solid #0f172a;
    padding-top: 8px;
    text-align: center;
    font-size: 9px;
    font-weight: 700;
    color: #0f172a;
    text-transform: uppercase;
}
</style>
</head>
<body>
<div class="order-card">
    <div class="header">
        <table>
            <tr>
                <td class="brand-box">
                    <h1>OPTIMUS</h1>
                    <span>Maritime Logistics Platform</span>
                </td>
                <td class="doc-label">
                    <h2>Electronic Delivery Order</h2>
                    <h2>Container Release Order</h2>
                </td>
            </tr>
        </table>
    </div>
    
    <div class="summary-bar">
        <table>
            <tr>
                <td class="summary-item">
                    <span class="summary-label">BL Number</span>
                    <span class="summary-val">' . $blNumber . '</span>
                </td>
                <td class="summary-item center">
                    <span class="summary-label">Registry Number</span>
                    <span class="summary-val">' . $registryNumber . '</span>
                </td>
                <td class="summary-item right">
                    <span class="summary-label">Manifest Number</span>
                    <span class="summary-val">' . $manifest->getManifestNumber() . '</span>
                </td>
            </tr>
        </table>
    </div>
    
    <div class="content">
        <table class="info-section">
            <tr>
                <td colspan="3" class="highlight">
                    <span class="info-label">Consignee/Notify Party</span>
                    <div class="info-val info-val-large">' . $consigneeName . '</div>
                </td>
            </tr>
            <tr>
                <td style="width: 33.33%;">
                    <span class="info-label">Shipping Line/Carrier</span>
                    <div class="info-val">' . $shippingLineName . '</div>
                </td>
                <td style="width: 33.33%;">
                    <span class="info-label">Vessel/Voyage Number</span>
                    <div class="info-val">' . $vesselName . ' / ' . $voyageNumber . '</div>
                </td>
                <td style="width: 33.33%;">
                    <span class="info-label">Name of Broker</span>
                    <div class="info-val">' . $brokerName . '</div>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="info-label">Document Status</span>
                    <div class="info-val">ELECTRONIC RELEASE</div>
                </td>
                <td colspan="2">
                    <span class="info-label">Print Date/Time</span>
                    <div class="info-val">' . strtoupper($currentDateTime) . '</div>
                </td>
            </tr>
        </table>
        
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 35px;">No.</th>
                        <th>Container Number</th>
                        <th style="width: 45px;">Size</th>
                        <th style="width: 45px;">Type</th>
                        <th style="width: 60px;">Seal</th>
                        <th>Name of Hauler</th>
                        <th style="width: 80px;">Plate No.</th>
                        <th>OPTIMUS Ref No.</th>
                        <th>Demurrage Validity</th>
                        <th>Return Empty To</th>
                    </tr>
                </thead>
                <tbody>';
        
        // Add a row for each container
        $rowNumber = 1;
        foreach ($edos as $edo) {
            $container = $edo->getContainer();
            $cyAllocation = $container->getCyAllocation();
            
            $containerNumber = htmlspecialchars($container->getContainerNumber());
            $containerSize = $container->getContainerSize() ? $container->getContainerSize()->getName() : 'N/A';
            $containerType = $container->getContainerType() ? $container->getContainerType()->getCode() : 'N/A';
            $returnLocation = 'N/A';
            if ($cyAllocation !== null) {
                $terminal = $cyAllocation->getTerminal();
                $returnLocation = htmlspecialchars($terminal->getName());
            }
            $demurrageValidity = strtoupper($edo->getExpiresAt()->format('d-M-Y'));
            $optimusRefNo = htmlspecialchars($edo->getEdoNumber());
            
            // Hauler and seal information (not yet implemented in system)
            $haulerName = '-';
            $plateNumber = '-';
            $sealStatus = '-';
            
            $html .= '
                    <tr>
                        <td>' . $rowNumber . '</td>
                        <td class="bold-data">' . $containerNumber . '</td>
                        <td>' . $containerSize . '</td>
                        <td>' . $containerType . '</td>
                        <td>' . $sealStatus . '</td>
                        <td>' . $haulerName . '</td>
                        <td>' . $plateNumber . '</td>
                        <td class="bold-data">' . $optimusRefNo . '</td>
                        <td class="accent-data">' . $demurrageValidity . '</td>
                        <td>' . $returnLocation . '</td>
                    </tr>';
            
            $rowNumber++;
        }
        
        $html .= '
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="footer">
        <div class="note-box">
            <strong>PORT OPERATIONS DIRECTIVE:</strong><br>
            Please release the above cargo container(s) to the Consignee/Broker/Hauler. Free Demurrage time is valid until 2400H of the specified validity date. Pre-advise notice is mandatory for container returns to MICT/ATI to prevent shut out fees.
        </div>
        
        <table class="sig-row">
            <tr>
                <td class="sig-col">Authorized Representative<br>' . $authorizedByCompany . '</td>
                <td class="sig-col">Prepared By<br>OPTIMUS SYSTEM</td>
                <td class="sig-col">Date/Time Received</td>
            </tr>
        </table>
    </div>
</div>
</body>
</html>';

        return $html;
    }
}
