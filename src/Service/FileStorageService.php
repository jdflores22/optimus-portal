<?php

namespace App\Service;

use App\Service\Storage\StorageAdapterInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Psr\Log\LoggerInterface;

class FileStorageService implements FileStorageServiceInterface
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ];

    private const MAX_FILE_SIZE = 10485760; // 10 MB

    private StorageAdapterInterface $storageAdapter;
    private LoggerInterface $logger;

    public function __construct(
        StorageAdapterInterface $storageAdapter,
        LoggerInterface $logger
    ) {
        $this->storageAdapter = $storageAdapter;
        $this->logger = $logger;
    }

    public function uploadFile(UploadedFile $file, string $category, ?string $subCategory = null): string
    {
        // Validate file
        $this->validateFile($file);

        // Generate unique filename
        $filename = $this->generateUniqueFilename($file);

        // Get organized path
        $relativePath = $this->getFilePath($category, $subCategory, $filename);

        // Store file using adapter
        try {
            $storedPath = $this->storageAdapter->store($file, $relativePath);
            
            $this->logger->info('File uploaded successfully', [
                'category' => $category,
                'sub_category' => $subCategory,
                'filename' => $filename,
                'path' => $storedPath
            ]);

            return $storedPath;
        } catch (\Exception $e) {
            $this->logger->error('File upload failed', [
                'error' => $e->getMessage(),
                'category' => $category,
                'filename' => $filename
            ]);
            throw new \RuntimeException('File upload failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getFilePath(string $category, ?string $subCategory = null, ?string $filename = null): string
    {
        $path = $category;
        
        if ($subCategory) {
            $path .= '/' . $subCategory;
        }

        if ($filename) {
            $path .= '/' . $filename;
        }

        return $path;
    }

    public function deleteFile(string $relativePath): void
    {
        if (!$this->storageAdapter->exists($relativePath)) {
            $this->logger->warning('Attempted to delete non-existent file', [
                'path' => $relativePath
            ]);
            return;
        }

        try {
            $this->storageAdapter->delete($relativePath);
            
            $this->logger->info('File deleted successfully', [
                'path' => $relativePath
            ]);
        } catch (\Exception $e) {
            $this->logger->error('File deletion failed', [
                'error' => $e->getMessage(),
                'path' => $relativePath
            ]);
            throw new \RuntimeException('File deletion failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function fileExists(string $relativePath): bool
    {
        return $this->storageAdapter->exists($relativePath);
    }

    public function getFullPath(string $relativePath): string
    {
        return $this->storageAdapter->getFullPath($relativePath);
    }

    private function validateFile(UploadedFile $file): void
    {
        // Check if file upload was successful
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('File upload failed: ' . $file->getErrorMessage());
        }

        // Check file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException(sprintf(
                'File size (%d bytes) exceeds maximum allowed size (%d bytes)',
                $file->getSize(),
                self::MAX_FILE_SIZE
            ));
        }

        // Check MIME type
        $mimeType = $file->getMimeType();
        if ($mimeType && !in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            throw new \InvalidArgumentException(sprintf(
                'File type "%s" is not allowed',
                $mimeType
            ));
        }

        // Additional security check: verify file extension matches MIME type
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'xls', 'xlsx'];
        
        if (!in_array($extension, $allowedExtensions)) {
            throw new \InvalidArgumentException(sprintf(
                'File extension "%s" is not allowed',
                $extension
            ));
        }
    }

    private function generateUniqueFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $uniqueId = uniqid('', true);
        $timestamp = date('YmdHis');
        
        return sprintf('%s_%s.%s', $timestamp, $uniqueId, $extension);
    }
}
