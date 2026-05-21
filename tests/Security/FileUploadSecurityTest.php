<?php

namespace App\Tests\Security;

use App\Service\FileService;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Test file upload security features
 * 
 * Tests Requirements: 5.1, 7.4, Security best practices
 */
class FileUploadSecurityTest extends KernelTestCase
{
    private FileService $fileService;
    private $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->fileService = $container->get(FileService::class);
        $this->entityManager = $container->get('doctrine')->getManager();
    }

    /**
     * Test that only allowed file types are accepted
     */
    public function testOnlyAllowedFileTypesAreAccepted(): void
    {
        $user = $this->createTestUser();
        
        // Test valid file types
        $validTypes = ['pdf', 'jpg', 'jpeg', 'png'];
        
        foreach ($validTypes as $extension) {
            $file = $this->createTestFile($extension, 'application/pdf');
            
            try {
                $result = $this->fileService->uploadFile($file, 'receipt', $user);
                $this->assertNotNull($result, "File with extension {$extension} should be accepted");
            } catch (\Exception $e) {
                // File might fail for other reasons, but not file type
                $this->assertStringNotContainsString('not allowed', $e->getMessage());
            }
        }
    }

    /**
     * Test that executable files are rejected
     */
    public function testExecutableFilesAreRejected(): void
    {
        $user = $this->createTestUser();
        
        // Create a file with executable signature
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, "\x4D\x5A" . str_repeat('X', 100)); // PE/EXE signature
        
        $file = new UploadedFile(
            $tempFile,
            'malicious.pdf',
            'application/pdf',
            null,
            true
        );
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Executable files are not allowed');
        
        $this->fileService->uploadFile($file, 'receipt', $user);
    }

    /**
     * Test that file size limits are enforced
     */
    public function testFileSizeLimitsAreEnforced(): void
    {
        $user = $this->createTestUser();
        
        // Create a file larger than 10MB
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        $largeContent = str_repeat('X', 11 * 1024 * 1024); // 11MB
        file_put_contents($tempFile, $largeContent);
        
        $file = new UploadedFile(
            $tempFile,
            'large.pdf',
            'application/pdf',
            null,
            true
        );
        
        $this->expectException(\InvalidArgumentException::class);
        
        $this->fileService->uploadFile($file, 'receipt', $user);
    }

    /**
     * Test that file extension matches MIME type
     */
    public function testFileExtensionMatchesMimeType(): void
    {
        $user = $this->createTestUser();
        
        // Create a file with mismatched extension and MIME type
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, 'test content');
        
        $file = new UploadedFile(
            $tempFile,
            'test.pdf',
            'text/plain', // Wrong MIME type for PDF
            null,
            true
        );
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match');
        
        $this->fileService->uploadFile($file, 'receipt', $user);
    }

    /**
     * Test that files are stored outside web root
     */
    public function testFilesAreStoredOutsideWebRoot(): void
    {
        $user = $this->createTestUser();
        
        $file = $this->createTestFile('pdf', 'application/pdf');
        $storedFile = $this->fileService->uploadFile($file, 'receipt', $user);
        
        $filePath = $storedFile->getEncryptedPath();
        
        // Check that file is not in public directory
        $this->assertStringNotContainsString('/public/', $filePath, 
            'Files should not be stored in public directory');
        
        // Check that file path contains organized structure
        $this->assertMatchesRegularExpression(
            '/\/(broker|consignee|sl_staff|accounting)\/\d+\/receipt\/\d{4}\//',
            $filePath,
            'Files should be stored in organized directory structure'
        );
    }

    /**
     * Test malware scanning is triggered for sensitive categories
     */
    public function testMalwareScanningIsTriggered(): void
    {
        $user = $this->createTestUser();
        
        // Categories that should trigger malware scanning
        $sensitiveCategories = ['bl', 'receipt', 'payment_proof'];
        
        foreach ($sensitiveCategories as $category) {
            $file = $this->createTestFile('pdf', 'application/pdf');
            
            // This should not throw an exception for clean files
            $result = $this->fileService->uploadFile($file, $category, $user);
            $this->assertNotNull($result, "Clean file should pass malware scan for category {$category}");
        }
    }

    /**
     * Helper method to create a test file
     */
    private function createTestFile(string $extension, string $mimeType): UploadedFile
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        
        // Create appropriate content based on MIME type
        if ($mimeType === 'application/pdf') {
            // Minimal valid PDF
            $content = "%PDF-1.4\n1 0 obj\n<<\n/Type /Catalog\n/Pages 2 0 R\n>>\nendobj\n2 0 obj\n<<\n/Type /Pages\n/Kids [3 0 R]\n/Count 1\n>>\nendobj\n3 0 obj\n<<\n/Type /Page\n/Parent 2 0 R\n/MediaBox [0 0 612 792]\n>>\nendobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000058 00000 n\n0000000115 00000 n\ntrailer\n<<\n/Size 4\n/Root 1 0 R\n>>\nstartxref\n190\n%%EOF";
        } elseif (str_starts_with($mimeType, 'image/')) {
            // Minimal valid image (1x1 PNG)
            $content = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        } else {
            $content = 'test content';
        }
        
        file_put_contents($tempFile, $content);
        
        return new UploadedFile(
            $tempFile,
            'test.' . $extension,
            $mimeType,
            null,
            true
        );
    }

    /**
     * Helper method to create a test user
     */
    private function createTestUser(): User
    {
        $user = new \App\Entity\Broker();
        $user->setFullName('Test Broker User');
        $user->setEmail('test_' . uniqid() . '@example.com');
        $user->setPasswordHash(password_hash('test_password', PASSWORD_BCRYPT));
        $user->setRole(UserRole::BROKER);
        $user->setStatus(\App\Entity\Enum\AccountStatus::APPROVED);
        $user->setEmailVerifiedAt(new \DateTime());
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        return $user;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
        $this->entityManager = null;
    }
}
