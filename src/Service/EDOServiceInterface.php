<?php

namespace App\Service;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Payment;

interface EDOServiceInterface
{
    /**
     * Auto-generate EDO after payment verification
     */
    /**
     * @deprecated Use BatchEDOGenerationService after final payment approval instead.
     */
    public function autoGenerateEDO(Payment $verifiedPayment): ElectronicDeliveryOrder;

    /**
     * Get EDO by manifest ID
     */
    public function getEDOByManifest(int $manifestId): ?ElectronicDeliveryOrder;

    /**
     * Get EDO by EDO number
     */
    public function getEDOByNumber(string $edoNumber): ?ElectronicDeliveryOrder;

    /**
     * Generate unique EDO number
     */
    public function generateEDONumber(): string;

    /**
     * Get cached eDO PDF content
     */
    public function getCachedEDOPDF(ElectronicDeliveryOrder $edo): string;

    /**
     * Invalidate cached eDO PDF
     */
    public function invalidateEDOPDFCache(ElectronicDeliveryOrder $edo): void;
}
