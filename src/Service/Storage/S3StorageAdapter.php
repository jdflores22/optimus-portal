<?php

namespace App\Service\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Psr\Log\LoggerInterface;

/**
 * S3/MinIO Storage Adapter
 * 
 * This adapter provides cloud storage support using AWS S3 or MinIO.
 * To use this adapter, install the AWS SDK: composer require aws/aws-sdk-php
 * 
 * Configuration required in .env:
 * STORAGE_ADAPTER=s3
 * S3_ENDPOINT=https://s3.amazonaws.com (or MinIO endpoint)
 * S3_BUCKET=your-bucket-name
 * S3_REGION=us-east-1
 * S3_KEY=your-access-key
 * S3_SECRET=your-secret-key
 */
class S3StorageAdapter implements StorageAdapterInterface
{
    private LoggerInterface $logger;
    private ?object $s3Client = null;
    private string $bucket;
    private string $region;

    public function __construct(
        LoggerInterface $logger,
        string $endpoint,
        string $bucket,
        string $region,
        string $key,
        string $secret
    ) {
        $this->logger = $logger;
        $this->bucket = $bucket;
        $this->region = $region;

        // Initialize S3 client if AWS SDK is available
        if (class_exists('\Aws\S3\S3Client')) {
            $this->s3Client = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region' => $region,
                'endpoint' => $endpoint,
                'use_path_style_endpoint' => true, // Required for MinIO
                'credentials' => [
                    'key' => $key,
                    'secret' => $secret,
                ],
            ]);
        } else {
            $this->logger->warning('AWS SDK not installed. S3 storage adapter will not function. Install with: composer require aws/aws-sdk-php');
        }
    }

    public function store(UploadedFile $file, string $path): string
    {
        if (!$this->s3Client) {
            throw new \RuntimeException('S3 client not initialized. Install AWS SDK: composer require aws/aws-sdk-php');
        }

        try {
            $this->s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
                'Body' => fopen($file->getPathname(), 'r'),
                'ContentType' => $file->getMimeType(),
            ]);

            $this->logger->info('File stored in S3', [
                'bucket' => $this->bucket,
                'path' => $path
            ]);

            return $path;
        } catch (\Exception $e) {
            $this->logger->error('S3 file storage failed', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to store file in S3: ' . $e->getMessage(), 0, $e);
        }
    }

    public function retrieve(string $path): ?string
    {
        if (!$this->s3Client) {
            throw new \RuntimeException('S3 client not initialized');
        }

        try {
            $result = $this->s3Client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);

            return (string) $result['Body'];
        } catch (\Exception $e) {
            $this->logger->error('S3 file retrieval failed', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function delete(string $path): bool
    {
        if (!$this->s3Client) {
            throw new \RuntimeException('S3 client not initialized');
        }

        try {
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);

            $this->logger->info('File deleted from S3', [
                'bucket' => $this->bucket,
                'path' => $path
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('S3 file deletion failed', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function exists(string $path): bool
    {
        if (!$this->s3Client) {
            return false;
        }

        try {
            $this->s3Client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getFullPath(string $path): string
    {
        // For S3, return the S3 URI
        return sprintf('s3://%s/%s', $this->bucket, $path);
    }

    public function getPublicUrl(string $path): ?string
    {
        if (!$this->s3Client) {
            return null;
        }

        try {
            // Generate a pre-signed URL valid for 1 hour
            $cmd = $this->s3Client->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);

            $request = $this->s3Client->createPresignedRequest($cmd, '+1 hour');
            
            return (string) $request->getUri();
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate S3 pre-signed URL', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
