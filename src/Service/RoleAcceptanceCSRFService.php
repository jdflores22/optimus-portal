<?php

namespace App\Service;

use App\Entity\PendingUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Enhanced CSRF protection service for role acceptance with security monitoring
 */
class RoleAcceptanceCSRFService
{
    public function __construct(
        private CsrfTokenManagerInterface $csrfTokenManager,
        private RoleAcceptanceSecurityService $securityService
    ) {
    }

    /**
     * Generate a CSRF token for role acceptance
     */
    public function generateToken(string $acceptanceToken): string
    {
        $csrfToken = $this->csrfTokenManager->getToken('role_acceptance_' . $acceptanceToken);
        
        $this->securityService->logRoleAcceptanceActivity('csrf_token_generated', $acceptanceToken, null, [
            'token_id' => 'role_acceptance_' . $acceptanceToken
        ]);
        
        return $csrfToken->getValue();
    }

    /**
     * Validate CSRF token with enhanced security logging
     */
    public function validateToken(Request $request, string $acceptanceToken, ?PendingUser $pendingUser = null): array
    {
        $submittedToken = $request->request->get('_token');
        
        if (!$submittedToken) {
            $this->securityService->logRoleAcceptanceActivity('csrf_token_missing', $acceptanceToken, $pendingUser, [
                'user_agent' => $request->headers->get('User-Agent'),
                'referer' => $request->headers->get('Referer')
            ]);
            
            return [
                'valid' => false,
                'reason' => 'missing_token',
                'message' => 'Security token is missing. Please refresh the page and try again.'
            ];
        }

        $csrfToken = new CsrfToken('role_acceptance_' . $acceptanceToken, $submittedToken);
        $isValid = $this->csrfTokenManager->isTokenValid($csrfToken);

        if (!$isValid) {
            $this->securityService->logRoleAcceptanceActivity('csrf_token_invalid', $acceptanceToken, $pendingUser, [
                'submitted_token_length' => strlen($submittedToken),
                'user_agent' => $request->headers->get('User-Agent'),
                'referer' => $request->headers->get('Referer'),
                'request_method' => $request->getMethod()
            ]);
            
            return [
                'valid' => false,
                'reason' => 'invalid_token',
                'message' => 'Security token is invalid. Please refresh the page and try again.'
            ];
        }

        // Log successful validation
        $this->securityService->logRoleAcceptanceActivity('csrf_token_validated', $acceptanceToken, $pendingUser);

        return [
            'valid' => true,
            'reason' => 'valid',
            'message' => 'Token validated successfully'
        ];
    }

    /**
     * Check for potential CSRF attack patterns
     */
    public function detectCSRFAttack(Request $request, string $acceptanceToken): bool
    {
        $suspiciousPatterns = [];

        // Check for missing or suspicious referer
        $referer = $request->headers->get('Referer');
        if (!$referer) {
            $suspiciousPatterns[] = 'missing_referer';
        } elseif (!$this->isValidReferer($referer, $request)) {
            $suspiciousPatterns[] = 'invalid_referer';
        }

        // Check for suspicious user agent
        $userAgent = $request->headers->get('User-Agent');
        if (!$userAgent || $this->isSuspiciousUserAgent($userAgent)) {
            $suspiciousPatterns[] = 'suspicious_user_agent';
        }

        // Check for rapid successive requests (potential automated attack)
        if ($this->isRapidRequest($request)) {
            $suspiciousPatterns[] = 'rapid_requests';
        }

        // Check for unusual request headers
        if ($this->hasUnusualHeaders($request)) {
            $suspiciousPatterns[] = 'unusual_headers';
        }

        if (!empty($suspiciousPatterns)) {
            $this->securityService->logRoleAcceptanceActivity('csrf_attack_detected', $acceptanceToken, null, [
                'patterns' => $suspiciousPatterns,
                'referer' => $referer,
                'user_agent' => $userAgent,
                'request_headers' => $this->getSafeHeaders($request)
            ]);
            
            return true;
        }

        return false;
    }

    /**
     * Validate referer header
     */
    private function isValidReferer(string $referer, Request $request): bool
    {
        $host = $request->getHost();
        $scheme = $request->getScheme();
        
        // Check if referer is from the same domain
        $expectedRefererPattern = "/^{$scheme}:\/\/{$host}/";
        
        return preg_match($expectedRefererPattern, $referer) === 1;
    }

    /**
     * Check for suspicious user agent patterns
     */
    private function isSuspiciousUserAgent(string $userAgent): bool
    {
        $suspiciousPatterns = [
            '/bot/i',
            '/crawler/i',
            '/spider/i',
            '/scraper/i',
            '/curl/i',
            '/wget/i',
            '/python/i',
            '/java/i',
            '/^$/i' // Empty user agent
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check for rapid successive requests
     */
    private function isRapidRequest(Request $request): bool
    {
        // This would typically check against a cache or session
        // For now, we'll use a simple heuristic based on request timing
        $requestTime = $request->server->get('REQUEST_TIME_FLOAT');
        $sessionKey = 'last_role_acceptance_request';
        
        if ($request->hasSession()) {
            $session = $request->getSession();
            $lastRequestTime = $session->get($sessionKey, 0);
            
            if ($requestTime - $lastRequestTime < 2) { // Less than 2 seconds between requests
                return true;
            }
            
            $session->set($sessionKey, $requestTime);
        }

        return false;
    }

    /**
     * Check for unusual request headers that might indicate an attack
     */
    private function hasUnusualHeaders(Request $request): bool
    {
        $suspiciousHeaders = [
            'X-Forwarded-For' => '/^(127\.0\.0\.1|localhost|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/i',
            'X-Real-IP' => '/^(127\.0\.0\.1|localhost)/i',
            'X-Originating-IP' => '/.*/',
            'X-Remote-IP' => '/.*/',
            'X-Remote-Addr' => '/.*/'
        ];

        foreach ($suspiciousHeaders as $header => $pattern) {
            $value = $request->headers->get($header);
            if ($value && preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get safe headers for logging (excluding sensitive information)
     */
    private function getSafeHeaders(Request $request): array
    {
        $safeHeaders = [];
        $allowedHeaders = [
            'Accept',
            'Accept-Language',
            'Accept-Encoding',
            'Content-Type',
            'Content-Length',
            'Cache-Control',
            'Connection',
            'Host',
            'Origin'
        ];

        foreach ($allowedHeaders as $header) {
            $value = $request->headers->get($header);
            if ($value) {
                $safeHeaders[$header] = $value;
            }
        }

        return $safeHeaders;
    }

    /**
     * Refresh CSRF token (used when token validation fails)
     */
    public function refreshToken(string $acceptanceToken): string
    {
        // Remove the old token
        $this->csrfTokenManager->removeToken('role_acceptance_' . $acceptanceToken);
        
        // Generate a new token
        return $this->generateToken($acceptanceToken);
    }
}