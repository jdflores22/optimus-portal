<?php

namespace App\Service;

use App\Entity\Payment;
use App\Entity\ElectronicDeliveryOrder;

interface PaymentVerificationTransactionServiceInterface
{
    /**
     * Verify final payment and auto-generate eDO in a single transaction
     * 
     * @param Payment $payment The payment to verify
     * @return ElectronicDeliveryOrder The generated eDO
     * @throws \Exception If verification or eDO generation fails
     */
    public function verifyFinalPaymentWithEDO(Payment $payment): ElectronicDeliveryOrder;

    /**
     * Verify manifest access payment with state transition in a transaction
     * 
     * @param Payment $payment The payment to verify
     * @return void
     * @throws \Exception If verification fails
     */
    public function verifyManifestAccessPayment(Payment $payment): void;
}
