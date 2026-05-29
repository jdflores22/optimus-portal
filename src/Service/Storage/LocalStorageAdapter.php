<?php

namespace App\Service\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Psr\Log\LoggerInterface;

class LocalStorageAdapter implements StorageAdapterInterface
{
    private string $storageRoot;
    private LoggerInterface $logger;

    public function __construct(
        ParameterBagInterface $parameterBag,
        LoggerInterface $logger
    ) {
        $this->storageRoot = $parameterBag->get('kernel.project_dir') . '/public/uploads';
        $this->logger = $logger;

        // Ensure storage root exists
        if (!is_dir($this->storageRoot)) {
            mkdir($this->storageRoot, 0755, true);
        }
    }

    public function store(UploadedFile $file, string $path): string
    {
        $fullPath = $this->storageRoot . '/' . $path;
        $directory = dirname($fullPath);

        // Ensure directory exists
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        try {
            $file->move($directory, basename($fullPath));
            
            $this->logger->info('File stored locally', [
                'path' => $path,
                'full_path' => $fullPath
            ]);

            return $path;
        } catch (\Exception $e) {
            $this->logger->error('Local file storage failed', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to store file locally: ' . $e->getMessage(), 0, $e);
        }
    }

    public function retrieve(string $path): ?string
    {
        $fullPath = $this->getFullPath($path);

        if (!file_exists($fullPath)) {
            return null;
        }

        $content = file_get_contents($fullPath);
        
        if ($content === false) {
            $this->logger->error('Failed to read local file', ['path' => $path]);
            return null;
        }

        return $content;
    }

    public function delete(string $path): bool
    {
        $fullPath = $this->getFullPath($path);

        if (!file_exists($fullPath)) {
            $this->logger->warning('Attempted to delete non-existent file', ['path' => $path]);
            return false;
        }

        try {
            unlink($fullPath);
            
            $this->logger->info('File deleted locally', ['path' => $path]);
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Local file deletion failed', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function exists(string $path): bool
    {
        return file_exists($this->getFullPath($path));
    }

    public function getFullPath(string $path): string
    {
        return $this->storageRoot . '/' . $path;
    }

    public function getPublicUrl(string $path): ?string
    {
        // Local storage doesn't provide public URLs directly
        // Files are served through controllers with authorization
        return null;
    }
}
