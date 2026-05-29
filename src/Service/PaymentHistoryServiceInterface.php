<?php

namespace App\Service;

use App\Entity\Payment;
use App\Entity\Manifest;

/**
 * Interface for PaymentHistoryService
 * Defines methods for retrieving payment history, chains, and statistics
 */
interface PaymentHistoryServiceInterface
{
    /**
     * Get complete payment history for a manifest
     * 
     * @param Manifest $manifest The manifest to get payment history for
     * @param string $paymentType The payment type (e.g., 'final_payment')
     * @return array Array of Payment entities ordered by version
     */
    public function getPaymentHistory(Manifest $manifest, string $paymentType): array;

    /**
     * Get payment chain starting from a specific payment
     * 
     * @param Payment $payment The payment to get the chain for
     * @return array Array of Payment entities from v1 to latest version
     */
    public function getPaymentChain(Payment $payment): array;

    /**
     * Get payment statistics for a manifest
     * 
     * @param Manifest $manifest The manifest to get statistics for
     * @param string $paymentType The payment type (e.g., 'final_payment')
     * @return array Statistics array with keys: total_versions, total_rejections,
     *               current_version, first_submission, last_submission
     */
    public function getPaymentStatistics(Manifest $manifest, string $paymentType): array;

    /**
     * Invalidate payment history cache for a manifest
     * 
     * @param Manifest $manifest The manifest to invalidate cache for
     * @param string $paymentType The payment type
     */
    public function invalidatePaymentHistoryCache(Manifest $manifest, string $paymentType): void;

    /**
     * Invalidate payment chain cache for a specific payment
     * 
     * @param Payment $payment The payment to invalidate cache for
     */
    public function invalidatePaymentChainCache(Payment $payment): void;
}
