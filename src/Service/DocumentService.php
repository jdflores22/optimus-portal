<?php

namespace App\Service;

use App\Entity\Manifest;
use App\Entity\Billing;
use App\Entity\ElectronicDeliveryOrder;
use TCPDF;

class DocumentService implements DocumentServiceInterface
{
    public function __construct(
        private string $projectDir,
        private EDODocumentGenerator $edoDocumentGenerator,
    ) {
    }

    public function generateNOAPDF(Manifest $manifest, array $data): string
    {
        $pdf = new TCPDF();
        $pdf->SetCreator('OPTIMUS Shipping Portal');
        $pdf->SetAuthor('Shipping Line Staff');
        $pdf->SetTitle('Notice of Arrival');
        $pdf->SetSubject('NOA Document');

        $pdf->AddPage();
        
        // Header
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 15, 'NOTICE OF ARRIVAL', 0, 1, 'C');
        
        $pdf->Ln(5);
        
        // NOA Details
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(50, 8, 'NOA Number:', 0, 0);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 8, $data['noaNumber'] ?? 'N/A', 0, 1);
        
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(50, 8, 'Manifest Number:', 0, 0);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 8, $manifest->getManifestNumber(), 0, 1);
        
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(50, 8, 'Arrival Date:', 0, 0);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 8, $data['arrivalDate'], 0, 1);
        
        $pdf->Ln(5);
        
        // Vessel Information
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'Vessel Information', 0, 1);
        
        $vesselInfo = $data['vesselInfo'];
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(50, 7, 'Vessel Name:', 0, 0);
        $pdf->SetFont('helvetica', '', 11);
        $pdf->Cell(0, 7, $vesselInfo['name'] ?? $manifest->getVesselName(), 0, 1);
        
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(50, 7, 'Voyage Number:', 0, 0);
        $pdf->SetFont('helvetica', '', 11);
        $pdf->Cell(0, 7, $vesselInfo['voyage'] ?? $manifest->getVoyageNumber(), 0, 1);
        
        if (isset($vesselInfo['lloydsNumber'])) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(50, 7, 'Lloyd\'s Number:', 0, 0);
            $pdf->SetFont('helvetica', '', 11);
            $pdf->Cell(0, 7, $vesselInfo['lloydsNumber'], 0, 1);
        }
        
        $pdf->Ln(5);
        
        // Consignee Information
        if ($manifest->getConsignee()) {
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->Cell(0, 10, 'Consignee Information', 0, 1);
            
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(50, 7, 'Business Name:', 0, 0);
            $pdf->SetFont('helvetica', '', 11);
            $pdf->Cell(0, 7, $manifest->getConsignee()->getBusinessName(), 0, 1);
        }
        
        $pdf->Ln(10);
        
        // Footer
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->Cell(0, 10, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 1);

        // Save PDF
        $filename = 'noa_' . $manifest->getManifestNumber() . '_' . time() . '.pdf';
        $filepath = $this->projectDir . '/var/documents/noa/' . $filename;
        
        $this->ensureDirectoryExists(dirname($filepath));
        $pdf->Output($filepath, 'F');

        return $filepath;
    }

    public function generateBillingPDF(Billing $billing): string
    {
        $manifest = $billing->getManifest();
        $shippingLine = $manifest->getShippingLine();

        $pdf = new TCPDF();
        $pdf->SetCreator('OPTIMUS Shipping Portal');
        $pdf->SetAuthor($shippingLine ? $shippingLine->getBrandName() : 'Shipping Line');
        $pdf->SetTitle('Billing Invoice');
        $pdf->SetSubject('Shipping Billing');
        $pdf->SetMargins(15, 15, 15);

        $pdf->AddPage();

        // Get shipping line portal config for company details
        $portalConfig = $shippingLine ? $shippingLine->getPortalConfig() : null;
        $companyName = $portalConfig['branding']['companyName'] ?? ($shippingLine ? $shippingLine->getBrandName() : 'Shipping Line');
        $companyAddress = $portalConfig['contact']['address'] ?? 'Port of Manila, South Harbor, Manila, Philippines';
        $companyPhone = $portalConfig['contact']['phone'] ?? null;
        $companyEmail = $portalConfig['contact']['email'] ?? null;

        // Header Section with two columns
        $pdf->SetFont('helvetica', 'B', 24);
        $pdf->SetTextColor(41, 98, 255); // Blue color
        $pdf->Cell(95, 10, 'Invoice', 0, 0, 'L');

        // Company name box with border - right aligned with proper padding
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetLineWidth(0.5);
        $pdf->SetDrawColor(0, 0, 0); // Black border
        
        // Calculate position for bordered box
        $boxWidth = 85; // Width of the company box
        $boxX = 195 - $boxWidth; // Right align with page margin
        $boxY = $pdf->GetY();
        
        // Draw the bordered box
        $pdf->Rect($boxX, $boxY, $boxWidth, 10, 'D');
        
        // Add company name inside the box
        $pdf->SetXY($boxX, $boxY);
        $pdf->Cell($boxWidth, 10, $companyName, 0, 1, 'C');
        
        $pdf->SetLineWidth(0.2); // Reset line width
        $pdf->SetDrawColor(0, 0, 0); // Reset to black

        // Invoice details (left) and Company details (right)
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(95, 6, 'INVOICE No: ' . str_pad($billing->getId(), 5, '0', STR_PAD_LEFT), 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 9);

        // Company address - align with the bordered box above
        $boxWidth = 85;
        $boxX = 195 - $boxWidth;
        
        // Split address into multiple lines if needed
        $addressLines = explode(',', $companyAddress);
        if (count($addressLines) > 0) {
            $currentY = $pdf->GetY();
            $pdf->SetXY($boxX, $currentY);
            $pdf->Cell($boxWidth, 6, trim($addressLines[0]), 0, 1, 'R');
        }

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(95, 6, 'DATE: ' . $billing->getCreatedAt()->format('M d, Y'), 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 9);
        if (count($addressLines) > 1) {
            $currentY = $pdf->GetY();
            $pdf->SetXY($boxX, $currentY);
            $pdf->Cell($boxWidth, 6, trim(implode(', ', array_slice($addressLines, 1))), 0, 1, 'R');
        } else {
            $pdf->Cell(95, 6, '', 0, 1, 'R');
        }

        $dueDate = clone $billing->getCreatedAt();
        $dueDate->modify('+30 days');
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(95, 6, 'INVOICE DUE DATE: ' . $dueDate->format('M d, Y'), 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 9);
        if ($companyPhone) {
            $currentY = $pdf->GetY();
            $pdf->SetXY($boxX, $currentY);
            $pdf->Cell($boxWidth, 6, 'Tel: ' . $companyPhone, 0, 1, 'R');
        } else {
            $pdf->Cell(95, 6, '', 0, 1, 'R');
        }

        // Add email if available
        if ($companyEmail) {
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell(95, 6, '', 0, 0, 'L');
            $currentY = $pdf->GetY();
            $pdf->SetXY($boxX, $currentY);
            $pdf->Cell($boxWidth, 6, 'Email: ' . $companyEmail, 0, 1, 'R');
        }

        $pdf->Ln(3);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(5);

        // Bill To and Amount Section — billing is issued to the consignee company
        $pdf->SetFont('helvetica', 'B', 10);
        $consignee = $manifest->getConsignee();
        $billToName = $consignee ? $consignee->getBusinessName() : 'N/A';
        $pdf->Cell(95, 6, 'BILL TO: ' . $billToName, 0, 0, 'L');

        // Amount display with USD on top, PHP below (stacked format)
        $pdf->Cell(95, 6, '', 0, 0, 'L'); // Left side spacer
        
        $amountX = 110;
        $amountY = $pdf->GetY();
        
        if ($billing->getOriginalCurrency() === 'USD' && $billing->getTotalAmountUsd()) {
            // USD in normal font
            $pdf->SetXY($amountX, $amountY);
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->Cell(85, 5, 'Amount: $' . number_format($billing->getTotalAmountUsd(), 2), 0, 2, 'R');
            // PHP in smaller font
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(85, 4, '(P' . number_format($billing->getTotalAmount(), 2) . ')', 0, 0, 'R');
        } else {
            $pdf->SetXY($amountX, $amountY);
            $pdf->SetFont('helvetica', 'B', 16);
            $pdf->Cell(85, 10, 'Amount: P' . number_format($billing->getTotalAmount(), 2), 0, 0, 'R');
        }
        
        $pdf->Ln(10);
        
        // Add UNPAID indicator - align with company box above
        $boxWidth = 85;
        $boxX = 195 - $boxWidth;
        
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetTextColor(255, 255, 255); // White text
        $pdf->SetFillColor(220, 53, 69); // Red background
        $pdf->SetLineWidth(0.5);
        $pdf->SetDrawColor(220, 53, 69); // Red border
        
        $currentY = $pdf->GetY();
        $pdf->SetXY($boxX, $currentY);
        $pdf->Cell($boxWidth, 8, 'UNPAID', 1, 1, 'C', true);
        
        $pdf->SetTextColor(0, 0, 0); // Reset to black
        $pdf->SetLineWidth(0.2); // Reset line width
        $pdf->SetDrawColor(0, 0, 0); // Reset to black

        $pdf->SetFont('helvetica', '', 9);
        if ($consignee) {
            $pdf->Cell(95, 5, 'Email: ' . $consignee->getEmail(), 0, 1, 'L');
        }

        $pdf->Ln(3);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(5);

        // Manifest Information
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(60, 6, 'Manifest Number:', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, $manifest->getManifestNumber(), 0, 1);

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(60, 6, 'BL Number:', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, $manifest->getBlNumber() ?? 'N/A', 0, 1);

        if ($billing->getOriginalCurrency() === 'USD' && $billing->getExchangeRate()) {
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(60, 6, 'Exchange Rate:', 0, 0);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(0, 6, '1 USD = P' . number_format($billing->getExchangeRate(), 4), 0, 1);
        }

        $pdf->Ln(5);

        // Items Table Header
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(10, 8, '#', 1, 0, 'C', true);
        $pdf->Cell(100, 8, 'DESCRIPTION OF CHARGES', 1, 0, 'L', true);
        $pdf->Cell(50, 8, 'AMOUNT', 1, 0, 'R', true);
        $pdf->Cell(20, 8, 'TAX', 1, 1, 'C', true);

        // Items
        $itemNum = 1;

        // Freight Charges
        $pdf->Cell(10, 10, $itemNum++, 1, 0, 'C');
        $pdf->Cell(100, 10, 'Freight Charges', 1, 0, 'L');

        // Amount cell with USD and PHP
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $pdf->Cell(50, 10, '', 1, 0, 'R'); // Border cell

        if ($billing->getOriginalCurrency() === 'USD' && $billing->getFreightChargesUsd()) {
            // USD in normal font
            $pdf->SetXY($x, $y + 2);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell(50, 3, '$' . number_format($billing->getFreightChargesUsd(), 2), 0, 2, 'R');
            // PHP in smaller font
            $pdf->SetFont('helvetica', '', 7);
            $pdf->Cell(50, 3, 'P' . number_format($billing->getFreightCharges(), 2), 0, 0, 'R');
        } else {
            $pdf->SetXY($x, $y + 3);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell(50, 4, 'P' . number_format($billing->getFreightCharges(), 2), 0, 0, 'R');
        }

        $pdf->SetXY($x + 50, $y);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(20, 10, '0%', 1, 1, 'C');

        // THC Charges
        $pdf->Cell(10, 10, $itemNum++, 1, 0, 'C');
        $pdf->Cell(100, 10, 'Terminal Handling Charges (THC)', 1, 0, 'L');

        // Amount cell with USD and PHP
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $pdf->Cell(50, 10, '', 1, 0, 'R'); // Border cell

        if ($billing->getOriginalCurrency() === 'USD' && $billing->getThcChargesUsd()) {
            // USD in normal font
            $pdf->SetXY($x, $y + 2);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell(50, 3, '$' . number_format($billing->getThcChargesUsd(), 2), 0, 2, 'R');
            // PHP in smaller font
            $pdf->SetFont('helvetica', '', 7);
            $pdf->Cell(50, 3, 'P' . number_format($billing->getThcCharges(), 2), 0, 0, 'R');
        } else {
            $pdf->SetXY($x, $y + 3);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell(50, 4, 'P' . number_format($billing->getThcCharges(), 2), 0, 0, 'R');
        }

        $pdf->SetXY($x + 50, $y);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(20, 10, '0%', 1, 1, 'C');

        // Additional charges
        if ($billing->getAdditionalCharges()) {
            foreach ($billing->getAdditionalCharges() as $charge) {
                $chargeAmount = $charge['amount'];

                $pdf->Cell(10, 10, $itemNum++, 1, 0, 'C');
                $pdf->Cell(100, 10, $charge['description'], 1, 0, 'L');

                // Amount cell - additional charges are already in PHP
                $x = $pdf->GetX();
                $y = $pdf->GetY();
                $pdf->Cell(50, 10, '', 1, 0, 'R'); // Border cell

                $pdf->SetXY($x, $y + 3);
                $pdf->SetFont('helvetica', '', 9);
                $pdf->Cell(50, 4, 'P' . number_format($chargeAmount, 2), 0, 0, 'R');

                $pdf->SetXY($x + 50, $y);
                $pdf->SetFont('helvetica', '', 9);
                $pdf->Cell(20, 10, '0%', 1, 1, 'C');
            }
        }

        $pdf->Ln(8);

        // Notes and Total Section
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(100, 6, 'NOTE:', 0, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 6, 'TOTAL', 0, 1, 'R');

        $pdf->SetFont('helvetica', '', 9);
        $noteText = 'Payment due within 30 days. ';
        if ($billing->getOriginalCurrency() === 'USD') {
            $noteText .= 'Original charges in USD converted to PHP at rate: 1 USD = P' . number_format($billing->getExchangeRate(), 4);
        }

        // Calculate note height
        $noteHeight = $pdf->getStringHeight(100, $noteText);
        $pdf->MultiCell(100, 5, $noteText, 0, 'L');

        // Position total amount properly
        $currentY = $pdf->GetY();
        $pdf->SetXY(110, $currentY - $noteHeight - 5);
        $pdf->SetFont('helvetica', 'B', 24);
        $pdf->Cell(0, 15, 'P' . number_format($billing->getTotalAmount(), 2), 0, 1, 'R');

        if ($billing->getOriginalCurrency() === 'USD' && $billing->getTotalAmountUsd()) {
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(0, 5, '(Original: $' . number_format($billing->getTotalAmountUsd(), 2) . ')', 0, 1, 'R');
        }

        $pdf->Ln(10);

        // Footer
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->Cell(0, 5, 'Generated by: ' . $billing->getGeneratedBy()->getFullName(), 0, 1, 'L');
        $pdf->Cell(0, 5, 'Generated on: ' . $billing->getCreatedAt()->format('F d, Y h:i A'), 0, 1, 'L');

        // Save PDF
        $filename = 'billing_' . $manifest->getManifestNumber() . '_' . time() . '.pdf';
        $filepath = $this->projectDir . '/var/documents/billing/' . $filename;

        $this->ensureDirectoryExists(dirname($filepath));
        $pdf->Output($filepath, 'F');

        return $filepath;
    }


    public function generateEDOPDF(ElectronicDeliveryOrder $edo): string
    {
        return $this->edoDocumentGenerator->generatePDF($edo);
    }

    public function addDigitalSignature(string $pdfPath): void
    {
        // Generate SHA-256 hash of the PDF file
        // In a production system, this would use proper digital signature with certificates
        // For now, we just compute the hash which is stored in the EDO entity
        if (!file_exists($pdfPath)) {
            throw new \InvalidArgumentException('PDF file not found');
        }
        
        // The hash is computed and stored by the calling service
        // This method is a placeholder for future implementation of proper digital signatures
    }

    /**
     * Add a RENEWAL watermark to an existing PDF
     * 
     * Note: This is a simplified version that just copies the PDF.
     * Full watermarking would require FPDI library which may not be installed.
     * The renewal status is indicated in the eDO's additional_notes field.
     * 
     * @param string $pdfPath Path to the PDF file
     * @param string $expiredEdoNumber The expired eDO number to display
     * @throws \Exception If PDF manipulation fails
     */
    public function addRenewalWatermark(string $pdfPath, string $expiredEdoNumber): void
    {
        if (!file_exists($pdfPath)) {
            throw new \InvalidArgumentException('PDF file not found: ' . $pdfPath);
        }

        // For now, we just verify the PDF exists
        // The watermark is indicated in the database via additional_notes field
        // Full PDF watermarking would require FPDI library
        
        // No action needed - the renewal status is stored in the eDO's additional_notes
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
