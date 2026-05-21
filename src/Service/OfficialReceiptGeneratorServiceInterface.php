<?php

namespace App\Service;

use App\Entity\EDOPayment;

/**
 * Interface for generating official receipt PDFs for approved eDO payments
 */
interface OfficialReceiptGeneratorServiceInterface
{
    /**
     * Generate official receipt PDF after payment approval
     * 
     * @param EDOPayment $payment The approved payment entity
     * @return string File path of generated receipt (relative path for database storage)
     */
    public function generateOfficialReceipt(EDOPayment $payment): string;
}
