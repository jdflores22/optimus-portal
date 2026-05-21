<?php

namespace App\Service;

use App\Entity\StoredFile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use App\Service\ValidationService;
use App\Service\CacheService;
use Psr\Log\LoggerInterface;

class FileService
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'docx'];
    private const MAX_FILE_SIZE = 10485760; // 10 MB in bytes
    private const ENCRYPTION_METHOD = 'aes-256-gcm';
    
    // Manifest workflow specific file types
    private const MANIFEST_ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png'
    ];
    
    private const MANIFEST_ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    private EntityManagerInterface $entityManager;
    private ValidationService $validationService;
    private CacheService $cacheService;
    private LoggerInterface $logger;
    private string $uploadDirectory;
    private string $encryptionKey;

    public function __construct(
        EntityManagerInterface $entityManager,
        ValidationService $validationService,
        CacheService $cacheService,
        LoggerInterface $logger,
        ParameterBagInterface $parameterBag,
        private RetryService $retryService
    ) {
        $this->entityManager = $entityManager;
        $this->validationService = $validationService;
        $this->cacheService = $cacheService;
        $this->logger = $logger;
        $this->uploadDirectory = $parameterBag->get('kernel.project_dir') . '/public/uploads';
        $this->encryptionKey = $parameterBag->get('app.file_encryption_key') ?? 'default-key-change-in-production';
        
        // Ensure upload directory exists
        if (!is_dir($this->uploadDirectory)) {
            mkdir($this->uploadDirectory, 0755, true);
        }
    }

    /**
     * Generate organized folder path based on user role, user ID, category, and year
     * Format: {role}/{userId}/{category}/{year}/
     * Example: broker/48/accreditation/2026/
     */
    private function generateFolderPath(User $user, string $category): string
    {
        $role = strtolower($user->getRole()->value);
        $userId = $user->getId();
        $year = date('Y');
        
        return $role . '/' . $userId . '/' . $category . '/' . $year;
    }

    public function uploadFile(UploadedFile $file, string $category, User $user): StoredFile
    {
        // First check if the file upload was successful at PHP level
        if (!$file->isValid()) {
            $error = $file->getErrorMessage();
            $this->logger->error('File upload failed at PHP level', [
                'error' => $error,
                'error_code' => $file->getError(),
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize()
            ]);
            throw new \InvalidArgumentException('File upload failed: ' . $error);
        }

        // Validate file extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            $this->logger->error('Invalid file extension', [
                'extension' => $extension,
                'allowed' => self::ALLOWED_EXTENSIONS,
                'original_name' => $file->getClientOriginalName()
            ]);
            throw new \InvalidArgumentException('Invalid file type. Allowed types: ' . implode(', ', self::ALLOWED_EXTENSIONS));
        }

        // Validate file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            $this->logger->error('File size exceeds limit', [
                'size' => $file->getSize(),
                'max_size' => self::MAX_FILE_SIZE,
                'original_name' => $file->getClientOriginalName()
            ]);
            throw new \InvalidArgumentException('File size exceeds maximum allowed size of ' . (self::MAX_FILE_SIZE / 1048576) . 'MB');
        }
        
        // Additional security: scan for malware if category is sensitive
        if (in_array($category, ['bl', 'receipt', 'payment_proof'])) {
            $this->scanFileForMalware($file);
        }

        $storedFile = new StoredFile();
        $storedFile->setOriginalName($file->getClientOriginalName());
        $storedFile->setMimeType($file->getMimeType() ?? 'application/octet-stream');
        $storedFile->setSize($file->getSize());
        $storedFile->setCategory($category);
        $storedFile->setUploadedBy($user);

        // Create organized folder path
        $folderPath = $this->generateFolderPath($user, $category);
        $fullFolderPath = $this->uploadDirectory . '/' . $folderPath;
        
        // Create unique filename and full path
        $filename = $storedFile->getFileId() . '.' . $file->getClientOriginalExtension();
        $filePath = $fullFolderPath . '/' . $filename;

        // Ensure organized directory structure exists and is writable
        if (!is_dir($fullFolderPath)) {
            if (!mkdir($fullFolderPath, 0755, true)) {
                throw new \RuntimeException('Failed to create upload directory: ' . $fullFolderPath);
            }
        }

        if (!is_writable($fullFolderPath)) {
            throw new \RuntimeException('Upload directory is not writable: ' . $fullFolderPath);
        }

        try {
            // Move uploaded file to organized directory structure
            $file->move($fullFolderPath, $filename);
            
            if (!file_exists($filePath)) {
                throw new \RuntimeException('File was not moved successfully to: ' . $filePath);
            }

            // Store the organized path
            $storedFile->setEncryptedPath($filePath);

            $this->entityManager->persist($storedFile);
            $this->entityManager->flush();

            // Cache file metadata for lazy loading optimization
            $this->cacheFileMetadata($storedFile);

            $this->logger->info('File uploaded successfully', [
                'file_id' => $storedFile->getFileId(),
                'original_name' => $storedFile->getOriginalName(),
                'size' => $storedFile->getSize(),
                'category' => $category,
                'user_id' => $user->getId(),
                'folder_path' => $folderPath,
                'public_path' => '/uploads/' . $folderPath . '/' . $filename
            ]);

            return $storedFile;
            
        } catch (\Exception $e) {
            // Clean up any partial files
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            
            $this->logger->error('File upload process failed', [
                'error' => $e->getMessage(),
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $filePath,
                'folder_path' => $folderPath
            ]);
            
            throw new \RuntimeException('File upload failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function validateFile(UploadedFile $file, array $allowedTypes, int $maxSize): array
    {
        $errors = [];

        // Check file size
        if ($file->getSize() > $maxSize) {
            $errors[] = sprintf('File size (%d bytes) exceeds maximum allowed size (%d bytes)', $file->getSize(), $maxSize);
        }

        // Check file extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, array_map('strtolower', $allowedTypes))) {
            $errors[] = sprintf('File type "%s" is not allowed. Allowed types: %s', $extension, implode(', ', $allowedTypes));
        }

        // Check MIME type (only if detected MIME type is available)
        $mimeType = $file->getMimeType();
        if ($mimeType && !in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            // Allow if extension is valid even if MIME type detection fails
            $extension = strtolower($file->getClientOriginalExtension());
            if (!in_array($extension, array_map('strtolower', $allowedTypes))) {
                $errors[] = sprintf('MIME type "%s" is not allowed', $mimeType);
            }
        }

        // Check if file was uploaded successfully
        if (!$file->isValid()) {
            $errors[] = 'File upload failed: ' . $file->getErrorMessage();
        }

        return [
            'isValid' => empty($errors),
            'error' => empty($errors) ? null : implode('; ', $errors)
        ];
    }

    public function retrieveFile(string $fileId, User $user): ?StoredFile
    {
        // First try to get file metadata from cache
        $cachedMetadata = $this->cacheService->getFileMetadata($fileId);
        
        if ($cachedMetadata) {
            $this->logger->debug('File metadata retrieved from cache', ['file_id' => $fileId]);
            
            // Check authorization using cached metadata
            if (!$this->canUserAccessFileFromMetadata($cachedMetadata, $user)) {
                return null;
            }
            
            // Create a lazy-loaded StoredFile object from cached metadata
            $storedFile = $this->createStoredFileFromMetadata($cachedMetadata);
            
            return $storedFile;
        }

        // If not in cache, load from database
        $storedFile = $this->entityManager->getRepository(StoredFile::class)
            ->findOneBy(['fileId' => $fileId]);

        if (!$storedFile) {
            return null;
        }

        // Authorization check
        if (!$this->canUserAccessFile($storedFile, $user)) {
            return null;
        }

        // Cache the metadata for future requests
        $this->cacheFileMetadata($storedFile);

        return $storedFile;
    }
    
    /**
     * Check if a user can access a file using cached metadata
     */
    private function canUserAccessFileFromMetadata(array $metadata, User $user): bool
    {
        // User can access their own files
        if ($metadata['uploadedBy'] === $user->getId()) {
            error_log('File access granted: user owns file (cached)');
            return true;
        }
        
        // For accreditation files, allow access to evaluators and admins
        if ($metadata['category'] === 'accreditation') {
            $userRole = $user->getRole();
            if ($userRole === \App\Entity\Enum\UserRole::EVALUATOR || 
                $userRole === \App\Entity\Enum\UserRole::SHIPPING_LINES_ADMIN) {
                error_log('File access granted: evaluator/admin accessing accreditation file (cached)');
                return true;
            }
        }
        
        // For payment proof files, allow access to accounting staff, SL staff, and system admin
        if ($metadata['category'] === 'payment_proof') {
            $userRole = $user->getRole();
            if ($userRole === \App\Entity\Enum\UserRole::ACCOUNTING || 
                $userRole === \App\Entity\Enum\UserRole::SL_STAFF ||
                $userRole === \App\Entity\Enum\UserRole::SYSTEM_ADMIN) {
                error_log('File access granted: accounting/SL staff/system admin accessing payment proof file (cached)');
                return true;
            }
        }
        
        // For geotag photos, allow access to truckers (own photos) and terminal team (all photos)
        if ($metadata['category'] === 'geotag_photos') {
            $userRole = $user->getRole();
            
            // Terminal Team can access all geotag photos
            if ($userRole === \App\Entity\Enum\UserRole::TERMINAL_TEAM) {
                error_log('File access granted: terminal team accessing geotag photo (cached)');
                return true;
            }
            
            // Truckers can access their own geotag photos
            if ($userRole === \App\Entity\Enum\UserRole::TRUCKER && 
                $metadata['uploadedBy'] === $user->getId()) {
                error_log('File access granted: trucker accessing own geotag photo (cached)');
                return true;
            }
        }
        
        // Default: deny access
        error_log('File access denied (cached): userId=' . $user->getId() . ', fileOwner=' . $metadata['uploadedBy'] . ', category=' . $metadata['category'] . ', userRole=' . $user->getRole()->value);
        return false;
    }
    
    /**
     * Check if a user can access a specific file
     */
    private function canUserAccessFile(StoredFile $storedFile, User $user): bool
    {
        // User can access their own files
        if ($storedFile->getUploadedBy()->getId() === $user->getId()) {
            error_log('File access granted: user owns file');
            return true;
        }
        
        // For accreditation files, allow access to evaluators and admins
        if ($storedFile->getCategory() === 'accreditation') {
            $userRole = $user->getRole();
            if ($userRole === \App\Entity\Enum\UserRole::EVALUATOR || 
                $userRole === \App\Entity\Enum\UserRole::SHIPPING_LINES_ADMIN) {
                error_log('File access granted: evaluator/admin accessing accreditation file');
                return true;
            }
        }
        
        // For payment proof files, allow access to accounting staff, SL staff, and system admin
        if ($storedFile->getCategory() === 'payment_proof') {
            $userRole = $user->getRole();
            if ($userRole === \App\Entity\Enum\UserRole::ACCOUNTING || 
                $userRole === \App\Entity\Enum\UserRole::SL_STAFF ||
                $userRole === \App\Entity\Enum\UserRole::SYSTEM_ADMIN) {
                error_log('File access granted: accounting/SL staff/system admin accessing payment proof file');
                return true;
            }
        }
        
        // For geotag photos, allow access to truckers (own photos) and terminal team (all photos)
        if ($storedFile->getCategory() === 'geotag_photos') {
            $userRole = $user->getRole();
            
            // Terminal Team can access all geotag photos
            if ($userRole === \App\Entity\Enum\UserRole::TERMINAL_TEAM) {
                error_log('File access granted: terminal team accessing geotag photo');
                return true;
            }
            
            // Truckers can access their own geotag photos
            if ($userRole === \App\Entity\Enum\UserRole::TRUCKER && 
                $storedFile->getUploadedBy()->getId() === $user->getId()) {
                error_log('File access granted: trucker accessing own geotag photo');
                return true;
            }
        }
        
        // Default: deny access
        error_log('File access denied: userId=' . $user->getId() . ', fileOwner=' . $storedFile->getUploadedBy()->getId() . ', category=' . $storedFile->getCategory() . ', userRole=' . $user->getRole()->value);
        return false;
    }

    public function deleteFile(string $fileId): void
    {
        $storedFile = $this->entityManager->getRepository(StoredFile::class)
            ->findOneBy(['fileId' => $fileId]);

        if (!$storedFile) {
            throw new \InvalidArgumentException('File not found');
        }

        // Delete the encrypted file from filesystem
        if (file_exists($storedFile->getEncryptedPath())) {
            unlink($storedFile->getEncryptedPath());
        }

        // Remove from database
        $this->entityManager->remove($storedFile);
        $this->entityManager->flush();
    }

    public function encryptFile(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException('File does not exist: ' . $filePath);
        }

        $data = file_get_contents($filePath);
        if ($data === false) {
            throw new \RuntimeException('Failed to read file: ' . $filePath);
        }

        // Generate a random IV
        $iv = random_bytes(12); // 96-bit IV for GCM
        
        // Encrypt the data
        $encrypted = openssl_encrypt($data, self::ENCRYPTION_METHOD, $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);
        
        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed');
        }

        // Combine IV, tag, and encrypted data
        $encryptedData = $iv . $tag . $encrypted;

        // Create encrypted file path
        $encryptedPath = $filePath . '.enc';
        
        // Write encrypted data to file
        if (file_put_contents($encryptedPath, $encryptedData) === false) {
            throw new \RuntimeException('Failed to write encrypted file');
        }

        return $encryptedPath;
    }

    public function decryptFile(string $encryptedPath): string
    {
        if (!file_exists($encryptedPath)) {
            throw new \InvalidArgumentException('Encrypted file does not exist: ' . $encryptedPath);
        }

        $encryptedData = file_get_contents($encryptedPath);
        if ($encryptedData === false) {
            throw new \RuntimeException('Failed to read encrypted file: ' . $encryptedPath);
        }

        // Extract IV, tag, and encrypted data
        $iv = substr($encryptedData, 0, 12);
        $tag = substr($encryptedData, 12, 16);
        $encrypted = substr($encryptedData, 28);

        // Decrypt the data
        $decrypted = openssl_decrypt($encrypted, self::ENCRYPTION_METHOD, $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);
        
        if ($decrypted === false) {
            throw new \RuntimeException('Decryption failed');
        }

        return $decrypted;
    }

    public function getFileContent(string $fileId, User $user): ?string
    {
        $storedFile = $this->retrieveFile($fileId, $user);
        
        if (!$storedFile) {
            return null;
        }

        return $this->decryptFile($storedFile->getEncryptedPath());
    }

    public function getFileResponse(string $fileId, User $user): ?array
    {
        $storedFile = $this->retrieveFile($fileId, $user);
        
        if (!$storedFile) {
            return null;
        }

        // For public files, read directly without decryption
        $content = file_get_contents($storedFile->getEncryptedPath());
        
        if ($content === false) {
            throw new \RuntimeException('Failed to read file: ' . $storedFile->getEncryptedPath());
        }

        return [
            'content' => $content,
            'filename' => $storedFile->getOriginalName(),
            'mimeType' => $storedFile->getMimeType(),
            'size' => $storedFile->getSize()
        ];
    }

    /**
     * Cache file metadata for lazy loading optimization
     */
    private function cacheFileMetadata(StoredFile $storedFile): void
    {
        $metadata = [
            'id' => $storedFile->getId(),
            'fileId' => $storedFile->getFileId(),
            'originalName' => $storedFile->getOriginalName(),
            'encryptedPath' => $storedFile->getEncryptedPath(),
            'mimeType' => $storedFile->getMimeType(),
            'size' => $storedFile->getSize(),
            'category' => $storedFile->getCategory(),
            'uploadedBy' => $storedFile->getUploadedBy()->getId(),
            'uploadedAt' => $storedFile->getUploadedAt()->format('Y-m-d H:i:s')
        ];

        $this->cacheService->cacheFileMetadata($storedFile->getFileId(), $metadata);
    }

    /**
     * Create a StoredFile object from cached metadata (lazy loading)
     */
    private function createStoredFileFromMetadata(array $metadata): StoredFile
    {
        $storedFile = new StoredFile();
        $storedFile->setId($metadata['id']);
        $storedFile->setFileId($metadata['fileId']);
        $storedFile->setOriginalName($metadata['originalName']);
        $storedFile->setEncryptedPath($metadata['encryptedPath']);
        $storedFile->setMimeType($metadata['mimeType']);
        $storedFile->setSize($metadata['size']);
        $storedFile->setCategory($metadata['category']);
        
        // Load the actual user from database only when needed
        $user = $this->entityManager->getRepository(User::class)->find($metadata['uploadedBy']);
        if ($user) {
            $storedFile->setUploadedBy($user);
        }
        
        $storedFile->setUploadedAt(new \DateTime($metadata['uploadedAt']));

        return $storedFile;
    }

    /**
     * Clean up orphaned files that exist in filesystem but not in database
     */
    public function cleanupOrphanedFiles(): int
    {
        $this->logger->info('Starting orphaned file cleanup');
        
        $cleanedCount = 0;
        $uploadDir = $this->uploadDirectory;
        
        if (!is_dir($uploadDir)) {
            $this->logger->warning('Upload directory does not exist', ['directory' => $uploadDir]);
            return 0;
        }

        $files = glob($uploadDir . '/*.enc');
        
        foreach ($files as $filePath) {
            $filename = basename($filePath, '.enc');
            
            // Extract file ID from filename (assuming format: {fileId}.{extension}.enc)
            $parts = explode('.', $filename);
            if (count($parts) >= 2) {
                $fileId = $parts[0];
                
                // Check if file exists in database
                $storedFile = $this->entityManager->getRepository(StoredFile::class)
                    ->findOneBy(['fileId' => $fileId]);
                
                if (!$storedFile) {
                    // File exists in filesystem but not in database - orphaned
                    if (unlink($filePath)) {
                        $cleanedCount++;
                        $this->logger->info('Removed orphaned file', [
                            'file_path' => $filePath,
                            'file_id' => $fileId
                        ]);
                        
                        // Also invalidate any cached metadata
                        $this->cacheService->invalidateFileMetadata($fileId);
                    } else {
                        $this->logger->error('Failed to remove orphaned file', [
                            'file_path' => $filePath,
                            'file_id' => $fileId
                        ]);
                    }
                }
            }
        }

        // Also clean up database records that reference non-existent files
        $allStoredFiles = $this->entityManager->getRepository(StoredFile::class)->findAll();
        
        foreach ($allStoredFiles as $storedFile) {
            if (!file_exists($storedFile->getEncryptedPath())) {
                $this->logger->warning('Database record references non-existent file', [
                    'file_id' => $storedFile->getFileId(),
                    'expected_path' => $storedFile->getEncryptedPath()
                ]);
                
                // Remove database record for non-existent file
                $this->entityManager->remove($storedFile);
                $cleanedCount++;
                
                // Invalidate cached metadata
                $this->cacheService->invalidateFileMetadata($storedFile->getFileId());
            }
        }
        
        if ($cleanedCount > 0) {
            $this->entityManager->flush();
        }

        $this->logger->info('Orphaned file cleanup completed', ['cleaned_count' => $cleanedCount]);
        
        return $cleanedCount;
    }

    /**
     * Get file metadata without loading content (lazy loading)
     */
    public function getFileMetadata(string $fileId, User $user): ?array
    {
        $storedFile = $this->retrieveFile($fileId, $user);
        
        if (!$storedFile) {
            return null;
        }

        return [
            'fileId' => $storedFile->getFileId(),
            'originalName' => $storedFile->getOriginalName(),
            'mimeType' => $storedFile->getMimeType(),
            'size' => $storedFile->getSize(),
            'category' => $storedFile->getCategory(),
            'uploadedAt' => $storedFile->getUploadedAt()->format('Y-m-d H:i:s'),
            'uploadedBy' => $storedFile->getUploadedBy()->getId()
        ];
    }

    /**
     * Get public URL for a file
     */
    public function getPublicUrl(string $fileId): ?string
    {
        $storedFile = $this->entityManager->getRepository(StoredFile::class)
            ->findOneBy(['fileId' => $fileId]);
            
        if (!$storedFile) {
            return null;
        }
        
        // Extract the relative path from the full path
        $relativePath = str_replace($this->uploadDirectory . '/', '', $storedFile->getEncryptedPath());
        return '/uploads/' . $relativePath;
    }

    /**
     * Get files by user and category
     */
    public function getFilesByUserAndCategory(User $user, string $category): array
    {
        return $this->entityManager->getRepository(StoredFile::class)
            ->findBy([
                'uploadedBy' => $user,
                'category' => $category
            ], ['uploadedAt' => 'DESC']);
    }

    /**
     * Get files by user, category, and year
     */
    public function getFilesByUserCategoryAndYear(User $user, string $category, int $year): array
    {
        $startDate = new \DateTime($year . '-01-01 00:00:00');
        $endDate = new \DateTime($year . '-12-31 23:59:59');
        
        return $this->entityManager->getRepository(StoredFile::class)
            ->createQueryBuilder('f')
            ->where('f.uploadedBy = :user')
            ->andWhere('f.category = :category')
            ->andWhere('f.uploadedAt BETWEEN :startDate AND :endDate')
            ->setParameter('user', $user)
            ->setParameter('category', $category)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('f.uploadedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if file exists without loading content
     */
    public function fileExists(string $fileId): bool
    {
        // First check cache
        $cachedMetadata = $this->cacheService->getFileMetadata($fileId);
        if ($cachedMetadata) {
            return true;
        }

        // Then check database
        $storedFile = $this->entityManager->getRepository(StoredFile::class)
            ->findOneBy(['fileId' => $fileId]);
            
        return $storedFile !== null;
    }
    
    /**
     * Scan file for malware (placeholder for future integration)
     * 
     * This method provides a hook for malware scanning integration.
     * In production, this should integrate with ClamAV or similar antivirus solution.
     * 
     * @param UploadedFile $file The file to scan
     * @throws \InvalidArgumentException if malware is detected
     */
    private function scanFileForMalware(UploadedFile $file): void
    {
        // Log the scan attempt
        $this->logger->info('Malware scan initiated', [
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType()
        ]);
        
        // TODO: Integrate with ClamAV or similar antivirus solution
        // Example integration:
        // $scanner = new ClamAVScanner();
        // if ($scanner->scanFile($file->getPathname())) {
        //     $this->logger->error('Malware detected in uploaded file', [
        //         'filename' => $file->getClientOriginalName()
        //     ]);
        //     throw new \InvalidArgumentException('File contains malware and cannot be uploaded');
        // }
        
        // For now, perform basic checks
        $this->performBasicSecurityChecks($file);
    }
    
    /**
     * Perform basic security checks on uploaded files
     * 
     * @param UploadedFile $file The file to check
     * @throws \InvalidArgumentException if security issues are detected
     */
    private function performBasicSecurityChecks(UploadedFile $file): void
    {
        // Check for executable file signatures
        $handle = fopen($file->getPathname(), 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot read uploaded file for security check');
        }
        
        $header = fread($handle, 4);
        fclose($handle);
        
        // Check for common executable signatures
        $executableSignatures = [
            "\x4D\x5A", // PE/EXE (Windows)
            "\x7F\x45\x4C\x46", // ELF (Linux)
            "\xCA\xFE\xBA\xBE", // Mach-O (macOS)
            "\x23\x21", // Shebang script
        ];
        
        foreach ($executableSignatures as $signature) {
            if (str_starts_with($header, $signature)) {
                $this->logger->error('Executable file detected', [
                    'filename' => $file->getClientOriginalName(),
                    'signature' => bin2hex($header)
                ]);
                throw new \InvalidArgumentException('Executable files are not allowed');
            }
        }
        
        // Additional check: ensure file extension matches content
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType();
        
        $validMimeTypeMap = [
            'pdf' => ['application/pdf'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png']
        ];
        
        if (isset($validMimeTypeMap[$extension])) {
            if (!in_array($mimeType, $validMimeTypeMap[$extension])) {
                $this->logger->warning('File extension does not match MIME type', [
                    'filename' => $file->getClientOriginalName(),
                    'extension' => $extension,
                    'mime_type' => $mimeType
                ]);
                throw new \InvalidArgumentException('File extension does not match file content');
            }
        }
    }
}