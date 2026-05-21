<?php

namespace App\Service;

use App\Entity\Payment;
use App\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;

interface PaymentServiceInterface
{
    /**
     * Submit final payment for freight charges, THC, and other computed charges
     */
    public function submitFinalPayment(int $manifestId, float $amount, UploadedFile $receipt, User $broker): Payment;

    /**
     * Validate final payment submitted by broker
     */
    public function validateFinalPayment(int $paymentId, bool $approved, ?string $reason, User $accounting): void;

    /**
     * Get pending final payments awaiting ACCOUNTING validation
     */
    public function getPendingFinalPayments(): array;

    /**
     * Get payment by ID
     */
    public function getPaymentById(int $paymentId): ?Payment;
}
