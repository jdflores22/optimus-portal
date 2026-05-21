<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service interface for handling payment receipt file uploads
 * Validates file format, size, and MIME type for security
 */
interface FileUploadServiceInterface
{
    /**
     * Validate and store payment receipt file
     * 
     * Stores files in organized directory structure:
     * /storage/payment-receipts/{year}/{month}/
     * 
     * File naming convention:
     * edo-{edo_id}-payment-{payment_id}.{extension}
     * 
     * @param UploadedFile $file The uploaded receipt file
     * @param int $edoId The eDO ID for file naming
     * @param int $paymentId The payment ID for file naming
     * @return string File path of stored receipt
     * @throws \App\Exception\FileUploadException if file format is invalid
     * @throws \App\Exception\FileUploadException if file size exceeds 5MB
     */
    public function storePaymentReceipt(UploadedFile $file, int $edoId, int $paymentId): string;

    /**
     * Validate file format
     * 
     * Accepts: PDF, JPG, JPEG, PNG
     * 
     * @param UploadedFile $file The file to validate
     * @return bool True if format is valid
     */
    public function isValidReceiptFormat(UploadedFile $file): bool;

    /**
     * Validate file size
     * 
     * @param UploadedFile $file The file to validate
     * @param int $maxSizeInMB Maximum file size in megabytes (default: 5)
     * @return bool True if size is within limit
     */
    public function isValidFileSize(UploadedFile $file, int $maxSizeInMB = 5): bool;
}
