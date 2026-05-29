<?php

namespace App\EventListener;

use App\Service\EDOExpirationServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Automatically checks for expired eDOs on every request
 * 
 * SIMPLIFIED VERSION - Maximum safety, minimum crashes
 */
class EDOExpirationListener
{
    private const CACHE_KEY = 'edo_expiration_last_check';
    private const CHECK_INTERVAL = 60; // Check every 60 seconds
    
    public function __construct(
        private EDOExpirationServiceInterface $expirationService,
        private LoggerInterface $logger,
        private string $cacheDir
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // ULTRA-SAFE: Wrap EVERYTHING in try-catch
        try {
            // Only run on main request
            if (!$event->isMainRequest()) {
                return;
            }

            // Skip for API requests, assets, and files
            $request = $event->getRequest();
            $path = $request->getPathInfo();
            
            // Skip these paths
            if ($this->shouldSkipPath($path)) {
                return;
            }

            // Check throttle
            if (!$this->shouldCheck()) {
                return;
            }

            // Run the check
            $this->checkExpiredEDOs();
            $this->updateLastCheckTime();
            
        } catch (\Throwable $e) {
            // NEVER let this break the application
            // Just log and continue
            try {
                $this->logger->error('[EDOExpirationListener] Fatal error caught', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            } catch (\Throwable $logError) {
                // Even if logging fails, don't crash
            }
        }
    }

    private function shouldSkipPath(string $path): bool
    {
        try {
            // Skip API calls
            if (str_starts_with($path, '/api/')) {
                return true;
            }
            
            // Skip assets
            if (str_starts_with($path, '/assets/')) {
                return true;
            }
            
            // Skip Symfony profiler
            if (str_starts_with($path, '/_')) {
                return true;
            }
            
            // Skip files with extensions (css, js, images)
            if (str_contains($path, '.') && !str_ends_with($path, '/')) {
                return true;
            }
            
            return false;
        } catch (\Throwable $e) {
            // If path checking fails, skip to be safe
            return true;
        }
    }

    private function shouldCheck(): bool
    {
        try {
            $cacheFile = $this->cacheDir . '/' . self::CACHE_KEY;
            
            if (!file_exists($cacheFile)) {
                return true;
            }

            $lastCheck = (int) @file_get_contents($cacheFile);
            $now = time();

            return ($now - $lastCheck) >= self::CHECK_INTERVAL;
        } catch (\Throwable $e) {
            // If throttle check fails, don't run to be safe
            return false;
        }
    }

    private function updateLastCheckTime(): void
    {
        try {
            $cacheFile = $this->cacheDir . '/' . self::CACHE_KEY;
            @file_put_contents($cacheFile, time());
        } catch (\Throwable $e) {
            // Ignore throttle update failures
        }
    }

    private function checkExpiredEDOs(): void
    {
        try {
            // Detect expired eDOs
            $expiredEDOs = $this->expirationService->detectExpiredEDOs();
            
            if (count($expiredEDOs) === 0) {
                return;
            }

            // Process each expired eDO
            $processedCount = 0;
            foreach ($expiredEDOs as $edo) {
                try {
                    $this->expirationService->markAsExpired($edo);
                    $processedCount++;
                } catch (\Throwable $e) {
                    // Log but continue with other eDOs
                    try {
                        $this->logger->error('[EDOExpirationListener] Failed to mark eDO as expired', [
                            'edoId' => $edo->getId(),
                            'error' => $e->getMessage()
                        ]);
                    } catch (\Throwable $logError) {
                        // Ignore logging errors
                    }
                }
            }

            if ($processedCount > 0) {
                try {
                    $this->logger->info('[EDOExpirationListener] Processed expired eDOs', [
                        'expired_count' => count($expiredEDOs),
                        'processed_count' => $processedCount
                    ]);
                } catch (\Throwable $logError) {
                    // Ignore logging errors
                }
            }
        } catch (\Throwable $e) {
            // Log but don't crash
            try {
                $this->logger->error('[EDOExpirationListener] Check failed', [
                    'error' => $e->getMessage()
                ]);
            } catch (\Throwable $logError) {
                // Ignore logging errors
            }
        }
    }
}
