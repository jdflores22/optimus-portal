<?php

namespace App\Service\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;

interface StorageAdapterInterface
{
    /**
     * Store a file
     * 
     * @param UploadedFile $file The file to store
     * @param string $path The relative path where to store the file
     * @return string The stored file path
     */
    public function store(UploadedFile $file, string $path): string;

    /**
     * Retrieve a file
     * 
     * @param string $path The relative path to the file
     * @return string|null The file content or null if not found
     */
    public function retrieve(string $path): ?string;

    /**
     * Delete a file
     * 
     * @param string $path The relative path to the file
     * @return bool True if deleted successfully
     */
    public function delete(string $path): bool;

    /**
     * Check if a file exists
     * 
     * @param string $path The relative path to the file
     * @return bool True if file exists
     */
    public function exists(string $path): bool;

    /**
     * Get the full path to a file
     * 
     * @param string $path The relative path to the file
     * @return string The full path
     */
    public function getFullPath(string $path): string;

    /**
     * Get a public URL for a file (if applicable)
     * 
     * @param string $path The relative path to the file
     * @return string|null The public URL or null if not applicable
     */
    public function getPublicUrl(string $path): ?string;
}
