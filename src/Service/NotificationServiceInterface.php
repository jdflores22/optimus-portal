<?php

namespace App\Service;

use App\Entity\Manifest;
use App\Entity\Payment;
use App\Entity\Billing;
use App\Entity\ElectronicDeliveryOrder;

interface NotificationServiceInterface
{
    /**
     * Notify that NOA has been generated
     */
    public function notifyNOAGenerated(Manifest $manifest, string $noaPath): void;

    /**
     * Notify that billing has been generated
     */
    public function notifyBillingGenerated(Manifest $manifest, Billing $billing): void;

    /**
     * Notify that payment has been rejected
     */
    public function notifyPaymentRejected(Payment $payment, string $reason): void;

    /**
     * Notify that EDO has been generated
     */
    public function notifyEDOGenerated(ElectronicDeliveryOrder $edo): void;

    /**
     * Notify that EDO has been released
     */
    public function notifyEDOReleased(ElectronicDeliveryOrder $edo): void;

    /**
     * Notify that EDO has been rejected
     */
    public function notifyEDORejected(ElectronicDeliveryOrder $edo, string $reason): void;
}
