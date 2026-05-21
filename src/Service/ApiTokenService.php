<?php

namespace App\Service;

use App\Entity\Trucker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class ApiTokenService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private ?RateLimiterFactory $apiRateLimiterFactory = null
    ) {
    }

    /**
     * Generate API token for trucker
     */
    public function generateTokenForTrucker(Trucker $trucker, int $validityDays = 30): string
    {
        // Revoke existing token first
        $trucker->revokeApiToken();
        
        // Generate new token
        $token = $trucker->generateApiToken($validityDays);
        
        // Save to database
        $this->entityManager->persist($trucker);
        $this->entityManager->flush();
        
        $this->logger->info('API token generated for trucker', [
            'trucker_id' => $trucker->getId(),
            'trucker_email' => $trucker->getEmail(),
            'expires_at' => $trucker->getApiTokenExpiresAt()?->format('Y-m-d H:i:s'),
            'validity_days' => $validityDays
        ]);
        
        return $token;
    }

    /**
     * Revoke API token for trucker
     */
    public function revokeTokenForTrucker(Trucker $trucker): void
    {
        $trucker->revokeApiToken();
        
        $this->entityManager->persist($trucker);
        $this->entityManager->flush();
        
        $this->logger->info('API token revoked for trucker', [
            'trucker_id' => $trucker->getId(),
            'trucker_email' => $trucker->getEmail()
        ]);
    }

    /**
     * Refresh API token for trucker
     */
    public function refreshTokenForTrucker(Trucker $trucker, int $validityDays = 30): string
    {
        return $this->generateTokenForTrucker($trucker, $validityDays);
    }

    /**
     * Validate API token format
     */
    public function validateTokenFormat(string $token): bool
    {
        // API tokens should be exactly 64 characters (32 bytes in hex)
        return strlen($token) === 64 && ctype_xdigit($token);
    }

    /**
     * Check rate limiting for API requests
     */
    public function checkRateLimit(Request $request, string $identifier): bool
    {
        if (!$this->apiRateLimiterFactory) {
            // Rate limiting not configured, allow request
            return true;
        }

        $limiter = $this->apiRateLimiterFactory->create($identifier);
        $limit = $limiter->consume();

        if (!$limit->isAccepted()) {
            $this->logger->warning('API rate limit exceeded', [
                'identifier' => $identifier,
                'ip_address' => $request->getClientIp(),
                'request_path' => $request->getPathInfo(),
                'retry_after' => $limit->getRetryAfter()->getTimestamp()
            ]);
            return false;
        }

        return true;
    }

    /**
     * Get API token statistics for trucker
     */
    public function getTokenStatistics(Trucker $trucker): array
    {
        return [
            'has_token' => !empty($trucker->getApiTokenHash()),
            'is_valid' => $trucker->hasValidApiToken(),
            'expires_at' => $trucker->getApiTokenExpiresAt()?->format('Y-m-d H:i:s'),
            'last_activity' => $trucker->getLastActivityAt()?->format('Y-m-d H:i:s'),
            'days_until_expiry' => $this->getDaysUntilExpiry($trucker),
            'is_expired' => $this->isTokenExpired($trucker)
        ];
    }

    /**
     * Clean up expired tokens
     */
    public function cleanupExpiredTokens(): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        $result = $qb->update(Trucker::class, 't')
            ->set('t.apiTokenHash', ':null')
            ->set('t.apiTokenExpiresAt', ':null')
            ->where('t.apiTokenExpiresAt IS NOT NULL')
            ->andWhere('t.apiTokenExpiresAt < :now')
            ->setParameter('null', null)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->execute();

        $this->logger->info('Cleaned up expired API tokens', [
            'tokens_cleaned' => $result
        ]);

        return $result;
    }

    /**
     * Get days until token expiry
     */
    private function getDaysUntilExpiry(Trucker $trucker): ?int
    {
        $expiresAt = $trucker->getApiTokenExpiresAt();
        
        if (!$expiresAt) {
            return null;
        }

        $now = new \DateTime();
        $diff = $now->diff($expiresAt);
        
        return $diff->invert ? -$diff->days : $diff->days;
    }

    /**
     * Check if token is expired
     */
    private function isTokenExpired(Trucker $trucker): bool
    {
        $expiresAt = $trucker->getApiTokenExpiresAt();
        
        if (!$expiresAt) {
            return false;
        }

        return $expiresAt < new \DateTime();
    }
}