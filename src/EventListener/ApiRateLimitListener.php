<?php

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Security;
class ApiRateLimitListener
{
    public function __construct(
        private RateLimiterFactory $apiUserRateLimiterFactory,
        private RateLimiterFactory $apiIpRateLimiterFactory,
        private RateLimiterFactory $photoUploadRateLimiterFactory,
        private Security $security,
        private LoggerInterface $logger
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        
        // Only apply rate limiting to API routes
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        // Skip rate limiting for non-main requests (sub-requests)
        if (!$event->isMainRequest()) {
            return;
        }

        $clientIp = $request->getClientIp();
        
        // Apply IP-based rate limiting first
        $ipLimiter = $this->apiIpRateLimiterFactory->create($clientIp);
        $ipLimit = $ipLimiter->consume();
        
        if (!$ipLimit->isAccepted()) {
            $this->logger->warning('API IP rate limit exceeded', [
                'ip_address' => $clientIp,
                'request_path' => $request->getPathInfo(),
                'retry_after' => $ipLimit->getRetryAfter()->getTimestamp()
            ]);
            
            $response = new JsonResponse([
                'success' => false,
                'error' => 'Rate limit exceeded',
                'code' => 'RATE_LIMIT_EXCEEDED',
                'retry_after' => $ipLimit->getRetryAfter()->getTimestamp()
            ], Response::HTTP_TOO_MANY_REQUESTS);
            
            $response->headers->set('Retry-After', (string) $ipLimit->getRetryAfter()->getTimestamp());
            $response->headers->set('X-RateLimit-Limit', '200');
            $response->headers->set('X-RateLimit-Remaining', (string) $ipLimit->getRemainingTokens());
            
            $event->setResponse($response);
            return;
        }

        // Apply user-based rate limiting if user is authenticated
        $user = $this->security->getUser();
        if ($user) {
            $userIdentifier = $user->getUserIdentifier();
            $userLimiter = $this->apiUserRateLimiterFactory->create($userIdentifier);
            $userLimit = $userLimiter->consume();
            
            if (!$userLimit->isAccepted()) {
                $this->logger->warning('API user rate limit exceeded', [
                    'user_identifier' => $userIdentifier,
                    'ip_address' => $clientIp,
                    'request_path' => $request->getPathInfo(),
                    'retry_after' => $userLimit->getRetryAfter()->getTimestamp()
                ]);
                
                $response = new JsonResponse([
                    'success' => false,
                    'error' => 'User rate limit exceeded',
                    'code' => 'USER_RATE_LIMIT_EXCEEDED',
                    'retry_after' => $userLimit->getRetryAfter()->getTimestamp()
                ], Response::HTTP_TOO_MANY_REQUESTS);
                
                $response->headers->set('Retry-After', (string) $userLimit->getRetryAfter()->getTimestamp());
                $response->headers->set('X-RateLimit-Limit', '100');
                $response->headers->set('X-RateLimit-Remaining', (string) $userLimit->getRemainingTokens());
                
                $event->setResponse($response);
                return;
            }
        }

        // Apply special rate limiting for photo uploads
        if (str_contains($request->getPathInfo(), '/photo/upload') && $user) {
            $photoLimiter = $this->photoUploadRateLimiterFactory->create($user->getUserIdentifier());
            $photoLimit = $photoLimiter->consume();
            
            if (!$photoLimit->isAccepted()) {
                $this->logger->warning('Photo upload rate limit exceeded', [
                    'user_identifier' => $user->getUserIdentifier(),
                    'ip_address' => $clientIp,
                    'retry_after' => $photoLimit->getRetryAfter()->getTimestamp()
                ]);
                
                $response = new JsonResponse([
                    'success' => false,
                    'error' => 'Photo upload rate limit exceeded',
                    'code' => 'PHOTO_UPLOAD_RATE_LIMIT_EXCEEDED',
                    'retry_after' => $photoLimit->getRetryAfter()->getTimestamp()
                ], Response::HTTP_TOO_MANY_REQUESTS);
                
                $response->headers->set('Retry-After', (string) $photoLimit->getRetryAfter()->getTimestamp());
                $response->headers->set('X-RateLimit-Limit', '10');
                $response->headers->set('X-RateLimit-Remaining', (string) $photoLimit->getRemainingTokens());
                
                $event->setResponse($response);
                return;
            }
        }

        // Add rate limit headers to successful responses
        $response = $event->getResponse();
        if ($response) {
            $response->headers->set('X-RateLimit-IP-Limit', '200');
            $response->headers->set('X-RateLimit-IP-Remaining', (string) $ipLimit->getRemainingTokens());
            
            if ($user && isset($userLimit)) {
                $response->headers->set('X-RateLimit-User-Limit', '100');
                $response->headers->set('X-RateLimit-User-Remaining', (string) $userLimit->getRemainingTokens());
            }
        }
    }
}