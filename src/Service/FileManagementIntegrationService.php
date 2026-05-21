<?php

namespace App\Service;

use App\Entity\GeotagPhoto;
use App\Entity\PreAdviceRequest;
use App\Entity\StoredFile;
use App\Entity\TerminalTeamUser;
use App\Entity\Trucker;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service for integrating geotag photo storage with existing file management system
 */
class FileManagementIntegrationService
{
    private const GEOTAG_PHOTO_CATEGORY = 'geotag_photos';
    private const ALLOWED_PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png'];
    private const MAX_PHOTO_SIZE = 5242880; // 5 MB in bytes
    private const ALLOWED_PHOTO_MIME_TYPES = [
        'image/jpeg',
        'image/png'
    ];

    public function __construct(
        private FileService $fileService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Upload and process geotag photo for pre-advice request
     */
    public function uploadGeotagPhoto(
        UploadedFile $file,
        PreAdviceRequest $preAdviceRequest,
        Trucker $trucker
    ): GeotagPhoto {
        // Validate photo file
        $this->validatePhotoFile($file);

        // Extract GPS coordinates from EXIF data
        $gpsData = $this->extractGpsCoordinates($file);

        // Upload file using existing file service
        $storedFile = $this->fileService->uploadFile($file, self::GEOTAG_PHOTO_CATEGORY, $trucker);

        // Create GeotagPhoto entity
        $geotagPhoto = new GeotagPhoto();
        $geotagPhoto->setPreAdviceRequest($preAdviceRequest);
        $geotagPhoto->setFilename($storedFile->getFileId());
        $geotagPhoto->setOriginalName($file->getClientOriginalName());
        $geotagPhoto->setLatitude($gpsData['latitude']);
        $geotagPhoto->setLongitude($gpsData['longitude']);
        $geotagPhoto->setCapturedAt($gpsData['capturedAt']);

        $this->entityManager->persist($geotagPhoto);
        $this->entityManager->flush();

        $this->logger->info('Geotag photo uploaded successfully', [
            'photo_id' => $geotagPhoto->getId(),
            'pre_advice_id' => $preAdviceRequest->getId(),
            'trucker_id' => $trucker->getId(),
            'file_id' => $storedFile->getFileId(),
            'latitude' => $gpsData['latitude'],
            'longitude' => $gpsData['longitude']
        ]);

        return $geotagPhoto;
    }

    /**
     * Get geotag photo content for verification
     */
    public function getGeotagPhotoContent(GeotagPhoto $geotagPhoto, User $user): ?array
    {
        // Check access permissions
        if (!$this->canUserAccessGeotagPhoto($geotagPhoto, $user)) {
            $this->logger->warning('Unauthorized access attempt to geotag photo', [
                'photo_id' => $geotagPhoto->getId(),
                'user_id' => $user->getId(),
                'user_role' => $user->getRole()->value
            ]);
            return null;
        }

        // Get file content using existing file service
        $fileResponse = $this->fileService->getFileResponse($geotagPhoto->getFilename(), $user);

        if (!$fileResponse) {
            $this->logger->error('Failed to retrieve geotag photo content', [
                'photo_id' => $geotagPhoto->getId(),
                'file_id' => $geotagPhoto->getFilename()
            ]);
            return null;
        }

        return [
            'content' => $fileResponse['content'],
            'filename' => $geotagPhoto->getOriginalName(),
            'mimeType' => $fileResponse['mimeType'],
            'size' => $fileResponse['size'],
            'latitude' => $geotagPhoto->getLatitude(),
            'longitude' => $geotagPhoto->getLongitude(),
            'capturedAt' => $geotagPhoto->getCapturedAt(),
            'isVerified' => $geotagPhoto->isVerified(),
            'verificationNotes' => $geotagPhoto->getVerificationNotes()
        ];
    }

    /**
     * Verify geotag photo by Terminal Team member
     */
    public function verifyGeotagPhoto(
        GeotagPhoto $geotagPhoto,
        TerminalTeamUser $verifier,
        bool $isVerified,
        ?string $notes = null
    ): void {
        $geotagPhoto->setIsVerified($isVerified);
        $geotagPhoto->setVerificationNotes($notes);

        $this->entityManager->flush();

        $this->logger->info('Geotag photo verification updated', [
            'photo_id' => $geotagPhoto->getId(),
            'verifier_id' => $verifier->getId(),
            'is_verified' => $isVerified,
            'has_notes' => !empty($notes)
        ]);
    }

    /**
     * Delete geotag photo and associated file
     */
    public function deleteGeotagPhoto(GeotagPhoto $geotagPhoto, User $user): void
    {
        // Check permissions
        if (!$this->canUserDeleteGeotagPhoto($geotagPhoto, $user)) {
            throw new \InvalidArgumentException('User does not have permission to delete this photo');
        }

        $fileId = $geotagPhoto->getFilename();
        $photoId = $geotagPhoto->getId();

        // Remove from database first
        $this->entityManager->remove($geotagPhoto);
        $this->entityManager->flush();

        // Delete file using existing file service
        try {
            $this->fileService->deleteFile($fileId);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete geotag photo file', [
                'photo_id' => $photoId,
                'file_id' => $fileId,
                'error' => $e->getMessage()
            ]);
            // Don't throw exception as database record is already deleted
        }

        $this->logger->info('Geotag photo deleted', [
            'photo_id' => $photoId,
            'file_id' => $fileId,
            'user_id' => $user->getId()
        ]);
    }

    /**
     * Get all geotag photos for a pre-advice request
     */
    public function getGeotagPhotosForPreAdvice(PreAdviceRequest $preAdviceRequest, User $user): array
    {
        $photos = $preAdviceRequest->getGeotagPhotos();
        $result = [];

        foreach ($photos as $photo) {
            if ($this->canUserAccessGeotagPhoto($photo, $user)) {
                $result[] = [
                    'id' => $photo->getId(),
                    'originalName' => $photo->getOriginalName(),
                    'latitude' => $photo->getLatitude(),
                    'longitude' => $photo->getLongitude(),
                    'capturedAt' => $photo->getCapturedAt(),
                    'isVerified' => $photo->isVerified(),
                    'verificationNotes' => $photo->getVerificationNotes(),
                    'uploadedAt' => $photo->getUploadedAt()
                ];
            }
        }

        return $result;
    }

    /**
     * Clean up orphaned geotag photos
     */
    public function cleanupOrphanedGeotagPhotos(): int
    {
        $this->logger->info('Starting orphaned geotag photo cleanup');

        $cleanedCount = 0;

        // Find geotag photos that reference non-existent files
        $geotagPhotos = $this->entityManager->getRepository(GeotagPhoto::class)->findAll();

        foreach ($geotagPhotos as $photo) {
            if (!$this->fileService->fileExists($photo->getFilename())) {
                $this->logger->warning('Geotag photo references non-existent file', [
                    'photo_id' => $photo->getId(),
                    'file_id' => $photo->getFilename()
                ]);

                $this->entityManager->remove($photo);
                $cleanedCount++;
            }
        }

        if ($cleanedCount > 0) {
            $this->entityManager->flush();
        }

        // Also clean up files in the geotag_photos category that don't have corresponding GeotagPhoto records
        $storedFiles = $this->entityManager->getRepository(StoredFile::class)
            ->findBy(['category' => self::GEOTAG_PHOTO_CATEGORY]);

        foreach ($storedFiles as $storedFile) {
            $geotagPhoto = $this->entityManager->getRepository(GeotagPhoto::class)
                ->findOneBy(['filename' => $storedFile->getFileId()]);

            if (!$geotagPhoto) {
                $this->logger->warning('Found orphaned geotag photo file', [
                    'file_id' => $storedFile->getFileId()
                ]);

                try {
                    $this->fileService->deleteFile($storedFile->getFileId());
                    $cleanedCount++;
                } catch (\Exception $e) {
                    $this->logger->error('Failed to delete orphaned geotag photo file', [
                        'file_id' => $storedFile->getFileId(),
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        $this->logger->info('Orphaned geotag photo cleanup completed', ['cleaned_count' => $cleanedCount]);

        return $cleanedCount;
    }

    /**
     * Validate photo file before upload
     */
    private function validatePhotoFile(UploadedFile $file): void
    {
        $validation = $this->fileService->validateFile(
            $file,
            self::ALLOWED_PHOTO_EXTENSIONS,
            self::MAX_PHOTO_SIZE
        );

        if (!$validation['isValid']) {
            throw new \InvalidArgumentException('Photo validation failed: ' . $validation['error']);
        }

        // Additional validation for photo-specific requirements
        $mimeType = $file->getMimeType();
        if ($mimeType && !in_array($mimeType, self::ALLOWED_PHOTO_MIME_TYPES)) {
            throw new \InvalidArgumentException('Invalid photo format. Only JPEG and PNG are allowed.');
        }
    }

    /**
     * Extract GPS coordinates from photo EXIF data
     */
    private function extractGpsCoordinates(UploadedFile $file): array
    {
        $tempPath = $file->getPathname();
        
        // Read EXIF data
        $exifData = @exif_read_data($tempPath);
        
        if (!$exifData) {
            throw new \InvalidArgumentException('Unable to read photo metadata. Please ensure the photo contains GPS information.');
        }

        // Check for GPS data
        if (!isset($exifData['GPS'])) {
            throw new \InvalidArgumentException('Photo does not contain GPS coordinates. Please enable location services when taking the photo.');
        }

        $gps = $exifData['GPS'];

        // Extract latitude
        if (!isset($gps['GPSLatitude']) || !isset($gps['GPSLatitudeRef'])) {
            throw new \InvalidArgumentException('Photo does not contain valid GPS latitude information.');
        }

        // Extract longitude
        if (!isset($gps['GPSLongitude']) || !isset($gps['GPSLongitudeRef'])) {
            throw new \InvalidArgumentException('Photo does not contain valid GPS longitude information.');
        }

        $latitude = $this->convertGpsCoordinate($gps['GPSLatitude'], $gps['GPSLatitudeRef']);
        $longitude = $this->convertGpsCoordinate($gps['GPSLongitude'], $gps['GPSLongitudeRef']);

        // Extract capture time
        $capturedAt = new \DateTime();
        if (isset($exifData['DateTime'])) {
            try {
                $capturedAt = new \DateTime($exifData['DateTime']);
            } catch (\Exception $e) {
                // Use current time if DateTime parsing fails
                $this->logger->warning('Failed to parse photo DateTime from EXIF', [
                    'datetime' => $exifData['DateTime'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'capturedAt' => $capturedAt
        ];
    }

    /**
     * Convert GPS coordinate from EXIF format to decimal degrees
     */
    private function convertGpsCoordinate(array $coordinate, string $hemisphere): float
    {
        if (count($coordinate) !== 3) {
            throw new \InvalidArgumentException('Invalid GPS coordinate format');
        }

        $degrees = $this->evaluateFraction($coordinate[0]);
        $minutes = $this->evaluateFraction($coordinate[1]);
        $seconds = $this->evaluateFraction($coordinate[2]);

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        // Apply hemisphere
        if (in_array($hemisphere, ['S', 'W'])) {
            $decimal = -$decimal;
        }

        return $decimal;
    }

    /**
     * Evaluate fraction string from EXIF data
     */
    private function evaluateFraction(string $fraction): float
    {
        $parts = explode('/', $fraction);
        if (count($parts) === 2) {
            return (float)$parts[0] / (float)$parts[1];
        }
        return (float)$fraction;
    }

    /**
     * Check if user can access geotag photo
     */
    private function canUserAccessGeotagPhoto(GeotagPhoto $geotagPhoto, User $user): bool
    {
        // Trucker can access their own photos
        if ($user instanceof Trucker && $geotagPhoto->getPreAdviceRequest()->getTrucker() === $user) {
            return true;
        }

        // Terminal Team can access all photos for verification
        if ($user instanceof TerminalTeamUser) {
            return true;
        }

        // System admin can access all photos
        if ($user->getRole()->value === 'SYSTEM_ADMIN') {
            return true;
        }

        return false;
    }

    /**
     * Check if user can delete geotag photo
     */
    private function canUserDeleteGeotagPhoto(GeotagPhoto $geotagPhoto, User $user): bool
    {
        // Only truckers can delete their own photos, and only if pre-advice is still pending
        if ($user instanceof Trucker && $geotagPhoto->getPreAdviceRequest()->getTrucker() === $user) {
            return $geotagPhoto->getPreAdviceRequest()->getStatus()->value === 'pending';
        }

        // System admin can delete any photo
        if ($user->getRole()->value === 'SYSTEM_ADMIN') {
            return true;
        }

        return false;
    }

    /**
     * Get photo statistics for dashboard
     */
    public function getPhotoStatistics(): array
    {
        $qb = $this->entityManager->createQueryBuilder();

        // Total photos
        $totalPhotos = $qb->select('COUNT(gp.id)')
            ->from(GeotagPhoto::class, 'gp')
            ->getQuery()
            ->getSingleScalarResult();

        // Verified photos
        $verifiedPhotos = $qb->select('COUNT(gp.id)')
            ->from(GeotagPhoto::class, 'gp')
            ->where('gp.isVerified = :verified')
            ->setParameter('verified', true)
            ->getQuery()
            ->getSingleScalarResult();

        // Photos uploaded today
        $today = new \DateTime('today');
        $photosToday = $qb->select('COUNT(gp.id)')
            ->from(GeotagPhoto::class, 'gp')
            ->where('gp.uploadedAt >= :today')
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total_photos' => (int)$totalPhotos,
            'verified_photos' => (int)$verifiedPhotos,
            'unverified_photos' => (int)$totalPhotos - (int)$verifiedPhotos,
            'photos_today' => (int)$photosToday,
            'verification_rate' => $totalPhotos > 0 ? round(($verifiedPhotos / $totalPhotos) * 100, 2) : 0
        ];
    }
}