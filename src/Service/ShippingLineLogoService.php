<?php

namespace App\Service;

use App\Entity\ShippingLine;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

/**
 * Handles shipping line logo upload, optimization, and management
 */
class ShippingLineLogoService
{
    private const UPLOAD_DIR = 'public/uploads/shipping-lines';
    private const MAX_FILE_SIZE = 2097152; // 2MB in bytes
    private const MAX_WIDTH = 200;
    private const MAX_HEIGHT = 200;
    private const ALLOWED_MIME_TYPES = ['image/png', 'image/jpeg', 'image/jpg', 'image/svg+xml'];

    public function __construct(
        private string $projectDir
    ) {
    }

    /**
     * Upload and process a shipping line logo
     * 
     * @throws \InvalidArgumentException if file validation fails
     * @throws FileException if file upload fails
     */
    public function uploadLogo(UploadedFile $file, ShippingLine $shippingLine): string
    {
        // Validate file
        $this->validateFile($file);

        // Generate unique filename
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $this->sanitizeFilename($originalFilename);
        $extension = $file->guessExtension();
        $newFilename = $safeFilename . '-' . uniqid() . '.' . $extension;

        // Get upload directory
        $uploadDir = $this->projectDir . '/' . self::UPLOAD_DIR;
        
        // Ensure directory exists
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Move file
        try {
            $file->move($uploadDir, $newFilename);
        } catch (FileException $e) {
            throw new FileException('Failed to upload logo: ' . $e->getMessage());
        }

        $filePath = $uploadDir . '/' . $newFilename;

        // Optimize image (except SVG)
        if ($extension !== 'svg') {
            $this->optimizeLogo($filePath);
        }

        // Delete old logo if exists
        if ($shippingLine->getLogoPath()) {
            $this->deleteLogo($shippingLine);
        }

        return self::UPLOAD_DIR . '/' . $newFilename;
    }

    /**
     * Delete a shipping line logo
     */
    public function deleteLogo(ShippingLine $shippingLine): void
    {
        $logoPath = $shippingLine->getLogoPath();
        if (!$logoPath) {
            return;
        }

        $fullPath = $this->projectDir . '/' . $logoPath;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    /**
     * Get the public URL for a logo
     */
    public function getLogoUrl(ShippingLine $shippingLine): ?string
    {
        $logoPath = $shippingLine->getLogoPath();
        if (!$logoPath) {
            return null;
        }

        // Convert to web-accessible path
        return '/' . str_replace('public/', '', $logoPath);
    }

    /**
     * Optimize logo image (resize and compress)
     */
    public function optimizeLogo(string $filePath): void
    {
        $imageInfo = getimagesize($filePath);
        if (!$imageInfo) {
            return;
        }

        [$width, $height, $type] = $imageInfo;

        // Skip if already small enough
        if ($width <= self::MAX_WIDTH && $height <= self::MAX_HEIGHT) {
            return;
        }

        // Calculate new dimensions maintaining aspect ratio
        $ratio = min(self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);
        $newWidth = (int)($width * $ratio);
        $newHeight = (int)($height * $ratio);

        // Create image resource based on type
        $sourceImage = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($filePath),
            IMAGETYPE_PNG => imagecreatefrompng($filePath),
            default => null
        };

        if (!$sourceImage) {
            return;
        }

        // Create new image
        $newImage = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG
        if ($type === IMAGETYPE_PNG) {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
        }

        // Resize
        imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        // Save optimized image
        match ($type) {
            IMAGETYPE_JPEG => imagejpeg($newImage, $filePath, 85),
            IMAGETYPE_PNG => imagepng($newImage, $filePath, 8),
            default => null
        };

        // Free memory
        imagedestroy($sourceImage);
        imagedestroy($newImage);
    }

    /**
     * Validate uploaded file
     * 
     * @throws \InvalidArgumentException if validation fails
     */
    private function validateFile(UploadedFile $file): void
    {
        // Check file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File size exceeds maximum allowed size of 2MB');
        }

        // Check MIME type
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            throw new \InvalidArgumentException('Invalid file type. Only PNG, JPG, and SVG files are allowed');
        }

        // Check if file is actually an image (except SVG)
        if ($mimeType !== 'image/svg+xml') {
            $imageInfo = getimagesize($file->getPathname());
            if (!$imageInfo) {
                throw new \InvalidArgumentException('File is not a valid image');
            }
        }
    }

    /**
     * Sanitize filename for safe storage
     */
    private function sanitizeFilename(string $filename): string
    {
        // Remove special characters
        $filename = preg_replace('/[^a-zA-Z0-9-_]/', '-', $filename);
        // Remove multiple dashes
        $filename = preg_replace('/-+/', '-', $filename);
        // Trim dashes from ends
        $filename = trim($filename, '-');
        // Limit length
        return substr($filename, 0, 50);
    }
}
