<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE, priority: 0)]
class SecurityHeadersListener
{
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $request = $event->getRequest();
        
        // Check if this is a file viewing route that needs iframe embedding
        $routeName = $request->attributes->get('_route');
        $isFileViewRoute = in_array($routeName, [
            'payment_file_view',
            'app_admin_file_download', // Admin file viewing
            // Add other file viewing routes as needed
        ]);

        // Add security headers
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        
        // Set frame options based on route
        if ($isFileViewRoute) {
            // Allow iframe embedding for file viewing routes
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        } else {
            // Deny iframe embedding for all other routes
            $response->headers->set('X-Frame-Options', 'DENY');
        }
        
        // Content Security Policy
        if ($isFileViewRoute) {
            // More permissive CSP for file viewing
            $csp = "default-src 'self'; " .
                   "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; " .
                   "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; " .
                   "img-src 'self' data: blob: https://cdn.jsdelivr.net https://*.tile.openstreetmap.org; " .
                   "font-src 'self' https://fonts.gstatic.com; " .
                   "connect-src 'self' https://cdn.jsdelivr.net; " .
                   "frame-src 'self' blob:; " .
                   "object-src 'none'; " .
                   "frame-ancestors 'self'"; // Allow same-origin framing
        } else {
            // Restrictive CSP for regular pages - allow Chart.js, ApexCharts, and Leaflet CDN
            $csp = "default-src 'self'; " .
                   "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; " .
                   "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; " .
                   "img-src 'self' data: blob: https://cdn.jsdelivr.net https://*.tile.openstreetmap.org; " .
                   "font-src 'self' https://fonts.gstatic.com; " .
                   "connect-src 'self' https://cdn.jsdelivr.net; " .
                   "frame-src 'self' blob:; " .
                   "object-src 'none'; " .
                   "frame-ancestors 'none'"; // Deny framing
        }
        
        $response->headers->set('Content-Security-Policy', $csp);

        // Remove server information
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');
    }
}