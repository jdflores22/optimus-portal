<?php

namespace App\Service;

use App\Entity\EDOPayment;
use TCPDF;

/**
 * Service for generating official receipt PDF documents for approved eDO payments using TCPDF
 */
class OfficialReceiptGeneratorService implements OfficialReceiptGeneratorServiceInterface
{
    public function __construct(
        private string $projectDir
    ) {
    }

    /**
     * Generate official receipt PDF after payment approval
     * 
     * @param EDOPayment $payment The approved payment entity
     * @return string File path of generated receipt (relative path for database storage)
     */
    public function generateOfficialReceipt(EDOPayment $payment): string
    {
        $edo = $payment->getEdo();
        $manifest = $payment->getManifest();
        $shippingLine = $payment->getShippingLine();
        $broker = $manifest->getBroker();
        $container = $edo?->getContainer();
        
        // Create new PDF document with custom page size (half width of A4, compact height)
        $pdf = new TCPDF('P', 'mm', array(105, 150), true, 'UTF-8');
        
        // Set document information
        $pdf->SetCreator('OPTIMUS - Trans-net Software Development Services');
        $pdf->SetAuthor('OPTIMUS System');
        $pdf->SetTitle('Official eDO Payment Receipt');
        $pdf->SetSubject('eDO Payment Receipt');
        
        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Disable auto page break to control content precisely
        $pdf->SetAutoPageBreak(false, 0);
        
        // Add a page
        $pdf->AddPage();
        
        // Set margins
        $pdf->SetMargins(10, 10, 10);
        
        // Draw "PAID" watermark FIRST so it appears behind all content
        // Page dimensions: 105mm x 150mm
        $pageWidth = 105;
        $pageHeight = 150;
        $centerX = $pageWidth / 2;
        $centerY = $pageHeight / 2;
        
        // Use transformation for rotation
        $pdf->StartTransform();
        $pdf->SetAlpha(0.50); // Very faded (8% opacity)
        $pdf->SetFont('helvetica', 'B', 60);
        $pdf->SetTextColor(200, 200, 200);
        
        // Rotate around center of page and position text centered
        $pdf->Rotate(45, $centerX, $centerY);
        
        // Calculate text position to center it
        $textWidth = 50;
        $textX = $centerX - ($textWidth / 2.5);
        $textY = $centerY - 15;
        
        $pdf->Text($textX, $textY, 'PAID');
        $pdf->StopTransform();
        
        // CRITICAL: Reset all PDF state after watermark
        $pdf->SetAlpha(1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY(10, 10); // Reset cursor to top-left with margin
        
        $receiptNumber = str_pad((string)$payment->getId(), 7, '0', STR_PAD_LEFT);
        
        // Helper function to draw dashed line
        $drawDashedLine = function() use ($pdf) {
            $pdf->SetFont('courier', '', 7);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->Cell(0, 3, str_repeat('-', 50), 0, 1, 'C');
        };
        
        // === HEADER ===
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetTextColor(40, 40, 40);
        $pdf->Cell(0, 6, 'OPTIMUS', 0, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 6);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 2.5, 'T R A N S - N E T   S O F T W A R E   D E V E L O P M E N T   S E R V I C E S', 0, 1, 'C');
                
        $pdf->Ln(2);
        
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetTextColor(60, 60, 60);
        $pdf->Cell(0, 5, 'OFFICIAL RECEIPT', 0, 1, 'C');
        
        $pdf->Ln(1);
        $drawDashedLine();
        $pdf->Ln(1);
        
        // === RECEIPT INFO ===
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(60, 60, 60);
        $pdf->Cell(0, 4, 'Receipt No: ' . $receiptNumber, 0, 1, 'L');
        $pdf->Cell(0, 4, 'Date: ' . ($payment->getValidatedAt()?->format('F d, Y') ?? date('F d, Y')), 0, 1, 'L');
        
        $pdf->Ln(1);
        
        // === RECEIVED FROM ===
        $pdf->Cell(0, 4, 'Received From: ' . ($broker ? $broker->getEmail() : 'N/A'), 0, 1, 'L');
        $pdf->Cell(0, 4, 'Business Style: ' . $shippingLine->getBrandName(), 0, 1, 'L');
        
        $pdf->Ln(1);
        $drawDashedLine();
        $pdf->Ln(1);
        
        // === DESCRIPTION ===
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(0, 4, 'Description:', 0, 1, 'L');
        
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(0, 4, 'Payment for eDO Release Fee', 0, 1, 'L');
        
        $pdf->Ln(1);
        
        $pdf->Cell(0, 4, 'eDO No: ' . ($edo?->getEdoNumber() ?? 'N/A'), 0, 1, 'L');
        $pdf->Cell(0, 4, 'Manifest No: ' . ($manifest->getManifestNumber() ?? 'N/A'), 0, 1, 'L');
        
        if ($container) {
            $pdf->Cell(0, 4, 'Container No: ' . $container->getContainerNumber(), 0, 1, 'L');
        }
        
        $pdf->Ln(1);
        $drawDashedLine();
        $pdf->Ln(1);
        
        // === AMOUNT ===
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 4, 'Amount Paid:', 0, 1, 'L');
        
        // Amount on new line, left-aligned with slight indent
        $pdf->SetFont('helvetica', 'B', 15);
        $pdf->SetTextColor(60, 60, 60);
        $pdf->Cell(5, 5, '', 0, 0, 'L'); // Small indent
        $pdf->Cell(0, 5, 'PHP ' . number_format($payment->getAmount(), 2), 0, 1, 'L');
        
        $pdf->Ln(1);
        
        // Amount in words
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(80, 80, 80);
        $amountWords = $this->convertNumberToWords($payment->getAmount());
        $pdf->Cell(0, 4, 'Amount in Words:', 0, 1, 'L');
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->MultiCell(0, 3, strtoupper($amountWords) . ' PESOS ONLY', 0, 'L');
        
        $pdf->Ln(1);
        $drawDashedLine();
        $pdf->Ln(1);
        
        // === PAYMENT DETAILS ===
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(60, 60, 60);
        $pdf->Cell(0, 4, 'Payment Method: Online', 0, 1, 'L');
        $pdf->Cell(0, 4, 'Reference No: PAY-' . str_pad((string)$payment->getId(), 6, '0', STR_PAD_LEFT), 0, 1, 'L');
        
        $processedBy = $payment->getValidatedBy() ? $payment->getValidatedBy()->getEmail() : 'system@optimus.com';
        $pdf->Cell(0, 4, 'Processed By: ' . $processedBy, 0, 1, 'L');
        
        $pdf->Ln(1);
        $drawDashedLine();
        $pdf->Ln(2);
        
        // === FOOTER ===
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 3, '*** This is a system-generated receipt ***', 0, 1, 'C');
        
        $pdf->Ln(2);
        
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(0, 4, 'Thank you!', 0, 1, 'C');
        
        $pdf->Ln(1);
        $drawDashedLine();
        
        // Generate directory structure: /storage/official-receipts/{year}/{month}/
        $year = date('Y');
        $month = date('m');
        $directory = $this->projectDir . '/storage/official-receipts/' . $year . '/' . $month;
        $this->ensureDirectoryExists($directory);
        
        // Generate filename: edo-{edo_id}-payment-{payment_id}.pdf
        $edoId = $edo?->getId() ?? 'unknown';
        $paymentId = $payment->getId();
        $filename = sprintf('edo-%s-payment-%s.pdf', $edoId, $paymentId);
        
        $filepath = $directory . '/' . $filename;
        $pdf->Output($filepath, 'F');
        
        // Return relative path for database storage
        return '/storage/official-receipts/' . $year . '/' . $month . '/' . $filename;
    }
    
    /**
     * Convert number to words (Philippine Peso)
     */
    private function convertNumberToWords(float $number): string
    {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $teens = ['Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

        $intPart = (int) $number;
        $decPart = (int) round(($number - $intPart) * 100);

        if ($intPart == 0) {
            $words = 'Zero';
        } else {
            $words = '';

            // Millions
            if ($intPart >= 1000000) {
                $millions = (int) ($intPart / 1000000);
                $words .= $this->convertHundreds($millions, $ones, $tens, $teens) . ' Million ';
                $intPart %= 1000000;
            }

            // Thousands
            if ($intPart >= 1000) {
                $thousands = (int) ($intPart / 1000);
                $words .= $this->convertHundreds($thousands, $ones, $tens, $teens) . ' Thousand ';
                $intPart %= 1000;
            }

            // Hundreds
            if ($intPart > 0) {
                $words .= $this->convertHundreds($intPart, $ones, $tens, $teens);
            }

            $words = trim($words);
        }

        if ($decPart > 0) {
            $words .= ' and ' . str_pad((string)$decPart, 2, '0', STR_PAD_LEFT) . '/100';
        }

        return $words;
    }

    /**
     * Convert hundreds place
     */
    private function convertHundreds(int $num, array $ones, array $tens, array $teens): string
    {
        $words = '';

        if ($num >= 100) {
            $hundreds = (int) ($num / 100);
            $words .= $ones[$hundreds] . ' Hundred ';
            $num %= 100;
        }

        if ($num >= 20) {
            $tensPlace = (int) ($num / 10);
            $words .= $tens[$tensPlace] . ' ';
            $num %= 10;
        } elseif ($num >= 10) {
            $words .= $teens[$num - 10] . ' ';
            $num = 0;
        }

        if ($num > 0) {
            $words .= $ones[$num] . ' ';
        }

        return trim($words);
    }
    
    /**
     * Ensure directory exists
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
    }
}
