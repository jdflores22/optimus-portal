<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

interface FileStorageServiceInterface
{
    /**
     * Upload a file to organized storage
     * 
     * @param UploadedFile $file The file to upload
     * @param string $category Main category (e.g., 'manifests', 'bl', 'receipts', 'documents')
     * @param string|null $subCategory Optional subcategory (e.g., 'noa', 'billing', 'edo')
     * @return string Relative path to the uploaded file
     */
    public function uploadFile(UploadedFile $file, string $category, ?string $subCategory = null): string;

    /**
     * Get organized file path
     * 
     * @param string $category Main category
     * @param string|null $subCategory Optional subcategory
     * @param string|null $filename Optional filename
     * @return string Relative path
     */
    public function getFilePath(string $category, ?string $subCategory = null, ?string $filename = null): string;

    /**
     * Delete a file from storage
     * 
     * @param string $relativePath Relative path to the file
     */
    public function deleteFile(string $relativePath): void;

    /**
     * Check if a file exists
     * 
     * @param string $relativePath Relative path to the file
     * @return bool True if file exists
     */
    public function fileExists(string $relativePath): bool;

    /**
     * Get full filesystem path for a relative path
     * 
     * @param string $relativePath Relative path to the file
     * @return string Full filesystem path
     */
    public function getFullPath(string $relativePath): string;
}
