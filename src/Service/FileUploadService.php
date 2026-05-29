<?php

namespace App\Service;

use App\Exception\FileUploadException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service for handling payment receipt file uploads
 * Validates file format, size, and MIME type for security
 */
class FileUploadService implements FileUploadServiceInterface
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];
    private const MAX_FILE_SIZE_MB = 5;
    private const MAX_FILE_SIZE_BYTES = self::MAX_FILE_SIZE_MB * 1024 * 1024; // 5MB in bytes

    private string $storageBasePath;
    private LoggerInterface $logger;

    public function __construct(
        string $storageBasePath,
        LoggerInterface $logger
    ) {
        $this->storageBasePath = rtrim($storageBasePath, '/');
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function storePaymentReceipt(UploadedFile $file, int $edoId, int $paymentId): string
    {
        // Validate file format
        if (!$this->isValidReceiptFormat($file)) {
            $this->logger->warning('Invalid file format attempted', [
                'edo_id' => $edoId,
                'payment_id' => $paymentId,
                'extension' => $file->getClientOriginalExtension(),
                'mime_type' => $file->getMimeType()
            ]);

            throw new FileUploadException(
                FileUploadException::INVALID_FILE_FORMAT,
                'Only PDF, JPG, and PNG files are accepted',
                400
            );
        }

        // Validate file size
        if (!$this->isValidFileSize($file)) {
            $this->logger->warning('File size exceeded', [
                'edo_id' => $edoId,
                'payment_id' => $paymentId,
                'file_size' => $file->getSize(),
                'max_size' => self::MAX_FILE_SIZE_BYTES
            ]);

            throw new FileUploadException(
                FileUploadException::FILE_SIZE_EXCEEDED,
                sprintf('File size must not exceed %dMB', self::MAX_FILE_SIZE_MB),
                400
            );
        }

        // Validate MIME type for security
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            $this->logger->warning('Invalid MIME type detected', [
                'edo_id' => $edoId,
                'payment_id' => $paymentId,
                'mime_type' => $mimeType
            ]);

            throw new FileUploadException(
                FileUploadException::INVALID_MIME_TYPE,
                'Invalid file type detected',
                400
            );
        }

        // Generate directory structure: /storage/payment-receipts/{year}/{month}/
        $year = date('Y');
        $month = date('m');
        $directory = sprintf('%s/payment-receipts/%s/%s', $this->storageBasePath, $year, $month);

        // Create directory if it doesn't exist
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                $this->logger->error('Failed to create storage directory', [
                    'directory' => $directory,
                    'edo_id' => $edoId,
                    'payment_id' => $paymentId
                ]);

                throw new FileUploadException(
                    FileUploadException::FILE_UPLOAD_FAILED,
                    'Failed to create storage directory',
                    500
                );
            }
        }

        // Generate secure file name: edo-{edo_id}-payment-{payment_id}.{extension}
        $extension = strtolower($file->getClientOriginalExtension());
        $filename = sprintf('edo-%d-payment-%d.%s', $edoId, $paymentId, $extension);
        $filePath = sprintf('%s/%s', $directory, $filename);

        // Check if file is valid and not already moved
        if (!$file->isValid()) {
            $this->logger->error('Uploaded file is not valid', [
                'edo_id' => $edoId,
                'payment_id' => $paymentId,
                'error' => $file->getError(),
                'error_message' => $file->getErrorMessage()
            ]);

            throw new FileUploadException(
                FileUploadException::FILE_UPLOAD_FAILED,
                'Uploaded file is not valid: ' . $file->getErrorMessage(),
                500
            );
        }

        // Move uploaded file to storage
        try {
            // Get file size BEFORE moving (file will be gone after move)
            $fileSize = $file->getSize();
            
            // Move the file
            $file->move($directory, $filename);

            $this->logger->info('Payment receipt stored successfully', [
                'edo_id' => $edoId,
                'payment_id' => $paymentId,
                'file_path' => $filePath,
                'file_size' => $fileSize
            ]);

            // Return relative path for database storage
            return sprintf('payment-receipts/%s/%s/%s', $year, $month, $filename);
        } catch (\Exception $e) {
            $this->logger->error('File upload failed', [
                'edo_id' => $edoId,
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
                'directory' => $directory,
                'filename' => $filename,
                'file_path' => $filePath
            ]);

            throw new FileUploadException(
                FileUploadException::FILE_UPLOAD_FAILED,
                'Failed to store payment receipt: ' . $e->getMessage(),
                500,
                $e
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isValidReceiptFormat(UploadedFile $file): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());
        return in_array($extension, self::ALLOWED_EXTENSIONS, true);
    }

    /**
     * {@inheritdoc}
     */
    public function isValidFileSize(UploadedFile $file, int $maxSizeInMB = 5): bool
    {
        $maxSizeBytes = $maxSizeInMB * 1024 * 1024;
        return $file->getSize() <= $maxSizeBytes;
    }

    /**
     * Store detention payment receipt file
     * 
     * @param UploadedFile $file The uploaded receipt file
     * @param int $billingId The billing ID
     * @param int $brokerId The broker ID
     * @return string Relative path to stored file
     * @throws FileUploadException If validation or storage fails
     */
    public function storeDetentionPaymentReceipt(UploadedFile $file, int $billingId, int $brokerId): string
    {
        // Validate file format
        if (!$this->isValidReceiptFormat($file)) {
            throw new FileUploadException(
                FileUploadException::INVALID_FILE_FORMAT,
                'Only PDF, JPG, and PNG files are accepted',
                400
            );
        }

        // Validate file size
        if (!$this->isValidFileSize($file)) {
            throw new FileUploadException(
                FileUploadException::FILE_SIZE_EXCEEDED,
                sprintf('File size must not exceed %dMB', self::MAX_FILE_SIZE_MB),
                400
            );
        }

        // Validate MIME type
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new FileUploadException(
                FileUploadException::INVALID_MIME_TYPE,
                'Invalid file type detected',
                400
            );
        }

        // Generate directory structure: /storage/detention-receipts/{year}/{month}/
        $year = date('Y');
        $month = date('m');
        $directory = sprintf('%s/detention-receipts/%s/%s', $this->storageBasePath, $year, $month);

        // Create directory if it doesn't exist
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new FileUploadException(
                    FileUploadException::FILE_UPLOAD_FAILED,
                    'Failed to create storage directory',
                    500
                );
            }
        }

        // Generate secure file name: billing-{billing_id}-broker-{broker_id}.{extension}
        $extension = strtolower($file->getClientOriginalExtension());
        $filename = sprintf('billing-%d-broker-%d.%s', $billingId, $brokerId, $extension);
        $filePath = sprintf('%s/%s', $directory, $filename);

        // Check if file is valid
        if (!$file->isValid()) {
            throw new FileUploadException(
                FileUploadException::FILE_UPLOAD_FAILED,
                'Uploaded file is not valid: ' . $file->getErrorMessage(),
                500
            );
        }

        // Move uploaded file to storage
        try {
            $file->move($directory, $filename);

            $this->logger->info('Detention payment receipt stored successfully', [
                'billing_id' => $billingId,
                'broker_id' => $brokerId,
                'file_path' => $filePath
            ]);

            // Return relative path for database storage
            return sprintf('detention-receipts/%s/%s/%s', $year, $month, $filename);
        } catch (\Exception $e) {
            $this->logger->error('Detention receipt upload failed', [
                'billing_id' => $billingId,
                'broker_id' => $brokerId,
                'error' => $e->getMessage()
            ]);

            throw new FileUploadException(
                FileUploadException::FILE_UPLOAD_FAILED,
                'Failed to store detention payment receipt: ' . $e->getMessage(),
                500,
                $e
            );
        }
    }
}
