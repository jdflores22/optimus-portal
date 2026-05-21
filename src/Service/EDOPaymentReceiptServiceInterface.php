<?php

namespace App\Service;

use App\Entity\EDOBilling;
use App\Entity\EDOPaymentReceipt;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Interface for eDO payment receipt service
 * 
 * Requirements: 8.1, 8.2, 8.3, 9.2
 */
interface EDOPaymentReceiptServiceInterface
{
    /**
     * Submit payment receipt for billing
     * 
     * @param EDOBilling $billing
     * @param UploadedFile $receiptFile
     * @param User $submitter
     * @return EDOPaymentReceipt
     */
    public function submitPaymentReceipt(EDOBilling $billing, UploadedFile $receiptFile, User $submitter): EDOPaymentReceipt;

    /**
     * Confirm payment and trigger eDO regeneration
     * 
     * @param EDOPaymentReceipt $payment
     * @param User $accountingUser
     * @return ElectronicDeliveryOrder New regenerated eDO
     */
    public function confirmPayment(EDOPaymentReceipt $payment, User $accountingUser): ElectronicDeliveryOrder;

    /**
     * Reject payment with reason
     * 
     * @param EDOPaymentReceipt $payment
     * @param User $accountingUser
     * @param string $reason
     * @return void
     */
    public function rejectPayment(EDOPaymentReceipt $payment, User $accountingUser, string $reason): void;

    /**
     * Validate payment receipt file format
     * 
     * @param UploadedFile $file
     * @return bool
     */
    public function validateReceiptFile(UploadedFile $file): bool;
}
