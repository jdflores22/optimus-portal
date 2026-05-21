<?php

namespace App\Service;

use App\Entity\Manifest;
use App\Entity\Billing;
use App\Entity\ElectronicDeliveryOrder;

interface DocumentServiceInterface
{
    /**
     * Generate NOA PDF document
     */
    public function generateNOAPDF(Manifest $manifest, array $data): string;

    /**
     * Generate Billing PDF document
     */
    public function generateBillingPDF(Billing $billing): string;

    /**
     * Generate EDO PDF document
     */
    public function generateEDOPDF(ElectronicDeliveryOrder $edo): string;

    /**
     * Add digital signature to PDF
     */
    public function addDigitalSignature(string $pdfPath): void;
}
