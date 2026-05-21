<?php

namespace App\Service;

use App\Entity\GeotagPhoto;
use App\Entity\PreAdviceRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Psr\Log\LoggerInterface;

class PhotoVerificationService
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/jpg', 'image/png'];
    private const MIN_IMAGE_WIDTH = 800;
    private const MIN_IMAGE_HEIGHT = 600;
    private const GPS_COORDINATE_PRECISION = 0.0001; // ~11 meters accuracy

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private string $uploadDirectory = 'public/uploads/geotag_photos'
    ) {}

    /**
     * Process and store geotag photo with GPS coordinate extraction
     */
    public function processGeotagPhoto(
        UploadedFile $uploadedFile,
        PreAdviceRequest $preAdviceRequest = null
    ): GeotagPhoto {
        // Validate uploaded file
        $this->validateUploadedFile($uploadedFile);

        // Extract GPS coordinates from EXIF data
        $gpsData = $this->extractGPSCoordinates($uploadedFile);
        
        if (!$gpsData) {
            throw new \InvalidArgumentException('Photo must contain GPS coordinates (geotag data)');
        }

        // Generate unique filename
        $filename = $this->generateUniqueFilename($uploadedFile);
        
        // Store file in file system
        $storedPath = $this->storePhotoFile($uploadedFile, $filename);

        // Create GeotagPhoto entity
        $photo = new GeotagPhoto();
        $photo->setFilename($filename);
        $photo->setOriginalName($uploadedFile->getClientOriginalName());
        $photo->setLatitude((string) $gpsData['latitude']);
        $photo->setLongitude((string) $gpsData['longitude']);
        $photo->setCapturedAt($gpsData['timestamp'] ?? new \DateTime());
        $photo->setUploadedAt(new \DateTime());

        if ($preAdviceRequest) {
            $photo->setPreAdviceRequest($preAdviceRequest);
        }

        $this->entityManager->persist($photo);
        $this->entityManager->flush();

        $this->logger->info('Geotag photo processed successfully', [
            'filename' => $filename,
            'originalName' => $uploadedFile->getClientOriginalName(),
            'latitude' => $gpsData['latitude'],
            'longitude' => $gpsData['longitude'],
            'fileSize' => $uploadedFile->getSize()
        ]);

        return $photo;
    }

    /**
     * Validate geotag photo for FREE-ADVICE requirements
     */
    public function validateGeotagPhoto(GeotagPhoto $photo): bool
    {
        $errors = [];

        // Check if photo has GPS coordinates
        if (empty($photo->getLatitude()) || empty($photo->getLongitude())) {
            $errors[] = 'Photo must contain GPS coordinates';
        }

        // Validate GPS coordinate format and range
        $lat = (float) $photo->getLatitude();
        $lng = (float) $photo->getLongitude();

        if ($lat < -90 || $lat > 90) {
            $errors[] = 'Invalid latitude value';
        }

        if ($lng < -180 || $lng > 180) {
            $errors[] = 'Invalid longitude value';
        }

        // Check if coordinates are not null island (0,0)
        if (abs($lat) < self::GPS_COORDINATE_PRECISION && abs($lng) < self::GPS_COORDINATE_PRECISION) {
            $errors[] = 'GPS coordinates appear to be invalid (null island)';
        }

        // Validate photo file exists
        if (!$this->photoFileExists($photo->getFilename())) {
            $errors[] = 'Photo file not found in storage';
        }

        if (!empty($errors)) {
            $this->logger->warning('Photo validation failed', [
                'photoId' => $photo->getId(),
                'errors' => $errors
            ]);
            return false;
        }

        return true;
    }

    /**
     * Check if photo is valid for verification
     */
    public function isPhotoValid(GeotagPhoto $photo): bool
    {
        return $this->validateGeotagPhoto($photo) && $this->photoFileExists($photo->getFilename());
    }

    /**
     * Extract GPS coordinates from EXIF data
     */
    public function extractGPSCoordinates(UploadedFile $uploadedFile): ?array
    {
        try {
            // Read EXIF data from uploaded file
            $exifData = @exif_read_data($uploadedFile->getPathname());
            
            if (!$exifData || !isset($exifData['GPS'])) {
                $this->logger->warning('No GPS data found in photo EXIF', [
                    'filename' => $uploadedFile->getClientOriginalName()
                ]);
                return null;
            }

            $gps = $exifData['GPS'];

            // Extract latitude
            if (!isset($gps['GPSLatitude'], $gps['GPSLatitudeRef'])) {
                return null;
            }

            $latitude = $this->convertGPSCoordinate($gps['GPSLatitude'], $gps['GPSLatitudeRef']);

            // Extract longitude
            if (!isset($gps['GPSLongitude'], $gps['GPSLongitudeRef'])) {
                return null;
            }

            $longitude = $this->convertGPSCoordinate($gps['GPSLongitude'], $gps['GPSLongitudeRef']);

            // Extract timestamp if available
            $timestamp = null;
            if (isset($gps['GPSTimeStamp'], $gps['GPSDateStamp'])) {
                try {
                    $dateStr = $gps['GPSDateStamp'];
                    $timeArray = $gps['GPSTimeStamp'];
                    $timeStr = sprintf('%02d:%02d:%02d', 
                        $this->fractionToDecimal($timeArray[0]),
                        $this->fractionToDecimal($timeArray[1]),
                        $this->fractionToDecimal($timeArray[2])
                    );
                    $timestamp = \DateTime::createFromFormat('Y:m:d H:i:s', $dateStr . ' ' . $timeStr);
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to parse GPS timestamp', [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Use photo creation date as fallback
            if (!$timestamp && isset($exifData['DateTime'])) {
                try {
                    $timestamp = \DateTime::createFromFormat('Y:m:d H:i:s', $exifData['DateTime']);
                } catch (\Exception $e) {
                    $timestamp = new \DateTime();
                }
            }

            return [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'timestamp' => $timestamp ?: new \DateTime()
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to extract GPS coordinates', [
                'filename' => $uploadedFile->getClientOriginalName(),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Verify photo for Terminal Team review
     */
    public function verifyPhoto(GeotagPhoto $photo, bool $isVerified, string $notes = null): GeotagPhoto
    {
        $photo->setIsVerified($isVerified);
        if ($notes) {
            $photo->setVerificationNotes($notes);
        }

        $this->entityManager->persist($photo);
        $this->entityManager->flush();

        $this->logger->info('Photo verification updated', [
            'photoId' => $photo->getId(),
            'isVerified' => $isVerified,
            'hasNotes' => !empty($notes)
        ]);

        return $photo;
    }

    /**
     * Get photo verification details for Terminal Team
     */
    public function getPhotoVerificationDetails(GeotagPhoto $photo): array
    {
        return [
            'id' => $photo->getId(),
            'filename' => $photo->getFilename(),
            'originalName' => $photo->getOriginalName(),
            'latitude' => (float) $photo->getLatitude(),
            'longitude' => (float) $photo->getLongitude(),
            'capturedAt' => $photo->getCapturedAt(),
            'uploadedAt' => $photo->getUploadedAt(),
            'isVerified' => $photo->isVerified(),
            'verificationNotes' => $photo->getVerificationNotes(),
            'fileExists' => $this->photoFileExists($photo->getFilename()),
            'isValid' => $this->isPhotoValid($photo),
            'coordinates' => [
                'lat' => (float) $photo->getLatitude(),
                'lng' => (float) $photo->getLongitude()
            ]
        ];
    }

    /**
     * Flag suspicious photos for review
     */
    public function flagSuspiciousPhoto(GeotagPhoto $photo, string $reason): GeotagPhoto
    {
        $notes = $photo->getVerificationNotes() 
            ? $photo->getVerificationNotes() . "\n[FLAGGED] " . $reason
            : "[FLAGGED] " . $reason;
            
        $photo->setVerificationNotes($notes);
        $photo->setIsVerified(false);

        $this->entityManager->persist($photo);
        $this->entityManager->flush();

        $this->logger->warning('Photo flagged as suspicious', [
            'photoId' => $photo->getId(),
            'reason' => $reason
        ]);

        return $photo;
    }

    /**
     * Validate uploaded file meets requirements
     */
    private function validateUploadedFile(UploadedFile $uploadedFile): void
    {
        $errors = [];

        // Check file size
        if ($uploadedFile->getSize() > self::MAX_FILE_SIZE) {
            $errors[] = sprintf('File size exceeds %dMB limit', self::MAX_FILE_SIZE / (1024 * 1024));
        }

        // Check file type
        if (!in_array($uploadedFile->getMimeType(), self::ALLOWED_MIME_TYPES)) {
            $errors[] = 'Invalid file type. Only JPEG and PNG are allowed';
        }

        // Check if file is actually an image
        $imageInfo = @getimagesize($uploadedFile->getPathname());
        if (!$imageInfo) {
            $errors[] = 'File is not a valid image';
        } else {
            // Check minimum dimensions
            if ($imageInfo[0] < self::MIN_IMAGE_WIDTH || $imageInfo[1] < self::MIN_IMAGE_HEIGHT) {
                $errors[] = sprintf('Image must be at least %dx%d pixels', 
                    self::MIN_IMAGE_WIDTH, self::MIN_IMAGE_HEIGHT);
            }
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException('File validation failed: ' . implode(', ', $errors));
        }
    }

    /**
     * Generate unique filename for photo storage
     */
    private function generateUniqueFilename(UploadedFile $uploadedFile): string
    {
        $extension = $uploadedFile->getClientOriginalExtension();
        $timestamp = date('YmdHis');
        $random = bin2hex(random_bytes(8));
        
        return "geotag_{$timestamp}_{$random}.{$extension}";
    }

    /**
     * Store photo file in file system
     */
    private function storePhotoFile(UploadedFile $uploadedFile, string $filename): string
    {
        try {
            // Ensure upload directory exists
            if (!is_dir($this->uploadDirectory)) {
                mkdir($this->uploadDirectory, 0755, true);
            }

            $uploadedFile->move($this->uploadDirectory, $filename);
            
            return $this->uploadDirectory . '/' . $filename;
        } catch (FileException $e) {
            $this->logger->error('Failed to store photo file', [
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to store photo file: ' . $e->getMessage());
        }
    }

    /**
     * Check if photo file exists in storage
     */
    private function photoFileExists(string $filename): bool
    {
        return file_exists($this->uploadDirectory . '/' . $filename);
    }

    /**
     * Convert GPS coordinate from EXIF format to decimal degrees
     */
    private function convertGPSCoordinate(array $coordinate, string $hemisphere): float
    {
        $degrees = $this->fractionToDecimal($coordinate[0]);
        $minutes = $this->fractionToDecimal($coordinate[1]);
        $seconds = $this->fractionToDecimal($coordinate[2]);

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        // Apply hemisphere
        if ($hemisphere === 'S' || $hemisphere === 'W') {
            $decimal *= -1;
        }

        return $decimal;
    }

    /**
     * Convert fraction string to decimal
     */
    private function fractionToDecimal($fraction): float
    {
        if (is_numeric($fraction)) {
            return (float) $fraction;
        }

        if (strpos($fraction, '/') !== false) {
            $parts = explode('/', $fraction);
            if (count($parts) === 2 && $parts[1] != 0) {
                return (float) $parts[0] / (float) $parts[1];
            }
        }

        return 0.0;
    }
}