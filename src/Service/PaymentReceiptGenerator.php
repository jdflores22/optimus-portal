<?php

namespace App\Service;

use App\Entity\Payment;
use TCPDF;

/**
 * Service for generating official payment receipt PDF documents using TCPDF
 */
class PaymentReceiptGenerator
{
    public function __construct(
        private string $projectDir
    ) {
    }

    /**
     * Generate official receipt PDF for approved payment
     * 
     * @param Payment $payment The payment entity
     * @return string Path to generated PDF file (relative path for database storage)
     */
    public function generateOfficialReceipt(Payment $payment): string
    {
        $manifest = $payment->getManifest();
        $shippingLine = $payment->getShippingLine();
        $broker = $manifest->getBroker();
        $consignee = $manifest->getConsignee();
        
        // Create new PDF document
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        
        // Set document information
        $pdf->SetCreator('OPTIMUS Shipping Portal');
        $pdf->SetAuthor($shippingLine->getBrandName());
        $pdf->SetTitle('Official Payment Receipt');
        $pdf->SetSubject('Payment Receipt');
        
        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Add a page
        $pdf->AddPage();
        
        // Set margins
        $pdf->SetMargins(15, 15, 15);
        
        // === HEADER ===
        $pdf->SetFont('helvetica', 'B', 20);
        $pdf->Cell(0, 10, $shippingLine->getBrandName(), 0, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 6, 'OFFICIAL PAYMENT RECEIPT', 0, 1, 'C');
        
        $pdf->Ln(5);
        
        // === RECEIPT NUMBER ===
        $pdf->SetFont('helvetica', 'B', 14);
        $receiptNumber = 'OR-' . str_pad($payment->getId(), 8, '0', STR_PAD_LEFT);
        $pdf->Cell(0, 8, 'Receipt No: ' . $receiptNumber, 0, 1, 'C');
        
        $pdf->Ln(5);
        
        // === PAID STAMP ===
        $pdf->SetTextColor(40, 167, 69); // Green color
        $pdf->SetFont('helvetica', 'B', 24);
        $pdf->Cell(0, 10, 'PAID', 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0); // Reset to black
        
        $pdf->Ln(5);
        
        // === PAYMENT INFORMATION ===
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Payment Information', 0, 1);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(3);
        
        $pdf->SetFont('helvetica', '', 10);
        $this->addRow($pdf, 'Payment Type:', $payment->getPaymentType()->value);
        $this->addRow($pdf, 'Payment Date:', $payment->getCreatedAt()->format('F d, Y h:i A'));
        $this->addRow($pdf, 'Verified Date:', $payment->getValidatedAt()->format('F d, Y h:i A'));
        $this->addRow($pdf, 'Verified By:', $payment->getValidatedBy()->getEmail());
        
        $pdf->Ln(5);
        
        // === MANIFEST INFORMATION ===
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Manifest Information', 0, 1);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(3);
        
        $pdf->SetFont('helvetica', '', 10);
        $this->addRow($pdf, 'Manifest Number:', $manifest->getManifestNumber() ?? 'N/A');
        $this->addRow($pdf, 'BL Number:', $manifest->getBlNumber());
        
        $pdf->Ln(5);
        
        // === PAYER INFORMATION ===
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Payer Information', 0, 1);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(3);
        
        $pdf->SetFont('helvetica', '', 10);
        $this->addRow($pdf, 'Broker:', $broker ? $broker->getEmail() : 'N/A');
        $this->addRow($pdf, 'Consignee:', $consignee ? $consignee->getEmail() : 'N/A');
        $this->addRow($pdf, 'Submitted By:', $payment->getSubmittedBy()->getEmail());
        
        $pdf->Ln(10);
        
        // === AMOUNT BOX ===
        $pdf->SetFillColor(249, 249, 249);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'AMOUNT PAID', 1, 1, 'C', true);
        
        $pdf->SetFont('helvetica', 'B', 20);
        $pdf->Cell(0, 12, 'PHP ' . number_format($payment->getAmount(), 2), 1, 1, 'C', true);
        
        $pdf->SetFont('helvetica', 'I', 10);
        $amountWords = $this->convertNumberToWords($payment->getAmount());
        $pdf->Cell(0, 8, '(' . $amountWords . ' Pesos)', 1, 1, 'C', true);
        
        $pdf->Ln(10);
        
        // === FOOTER ===
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'This is an official receipt issued by ' . $shippingLine->getBrandName(), 0, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, 'Generated on ' . date('F d, Y h:i A'), 0, 1, 'C');
        $pdf->Cell(0, 5, 'This document is computer-generated and requires no signature.', 0, 1, 'C');
        
        // Generate filename and save
        $filename = 'RECEIPT_' . $shippingLine->getBrandName() . '_' . $payment->getId() . '_' . date('YmdHis') . '.pdf';
        $filename = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $filename); // Sanitize filename
        
        $directory = $this->projectDir . '/public/uploads/receipts/official';
        $this->ensureDirectoryExists($directory);
        
        $filepath = $directory . '/' . $filename;
        $pdf->Output($filepath, 'F');
        
        // Return relative path for database storage
        return '/uploads/receipts/official/' . $filename;
    }
    
    /**
     * Add a row with label and value
     */
    private function addRow(TCPDF $pdf, string $label, string $value): void
    {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(60, 6, $label, 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, $value, 0, 1);
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
            $words .= ' and ' . str_pad($decPart, 2, '0', STR_PAD_LEFT) . '/100';
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
}
