<?php

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\ResponseEvent;
class ApiSecurityHeadersListener
{
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        
        // Only apply security headers to API routes
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        // Skip for non-main requests (sub-requests)
        if (!$event->isMainRequest()) {
            return;
        }

        // Set security headers
        $headers = $response->headers;
        
        // Content Security Policy for API responses
        $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none';");
        
        // Prevent MIME type sniffing
        $headers->set('X-Content-Type-Options', 'nosniff');
        
        // Prevent clickjacking
        $headers->set('X-Frame-Options', 'DENY');
        
        // XSS Protection
        $headers->set('X-XSS-Protection', '1; mode=block');
        
        // Referrer Policy
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        
        // Strict Transport Security (HTTPS only)
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }
        
        // API-specific headers
        $headers->set('X-API-Version', '1.0');
        $headers->set('X-Powered-By', 'Optimus Terminal Team Pre-Advice API');
        
        // CORS headers for mobile applications
        $this->setCorsHeaders($request, $response);
        
        // Cache control for API responses
        $headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $headers->set('Pragma', 'no-cache');
        $headers->set('Expires', '0');
    }

    /**
     * Set CORS headers for mobile applications
     */
    private function setCorsHeaders($request, $response): void
    {
        $headers = $response->headers;
        
        // Allow specific origins (configure based on your mobile app domains)
        $allowedOrigins = [
            'https://mobile.optimus-portal.com',
            'https://app.optimus-portal.com',
            'capacitor://localhost', // For Capacitor apps
            'ionic://localhost',     // For Ionic apps
        ];
        
        $origin = $request->headers->get('Origin');
        
        if ($origin && in_array($origin, $allowedOrigins)) {
            $headers->set('Access-Control-Allow-Origin', $origin);
        } else {
            // For development, you might want to allow localhost
            if (str_contains($origin, 'localhost') || str_contains($origin, '127.0.0.1')) {
                $headers->set('Access-Control-Allow-Origin', $origin);
            }
        }
        
        // Allow specific methods
        $headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        
        // Allow specific headers
        $headers->set('Access-Control-Allow-Headers', 
            'Content-Type, Authorization, X-API-Token, X-Requested-With, Accept, Origin'
        );
        
        // Allow credentials
        $headers->set('Access-Control-Allow-Credentials', 'true');
        
        // Preflight cache duration
        $headers->set('Access-Control-Max-Age', '86400'); // 24 hours
        
        // Expose custom headers to the client
        $headers->set('Access-Control-Expose-Headers', 
            'X-RateLimit-Limit, X-RateLimit-Remaining, X-API-Version, Retry-After'
        );
    }
}