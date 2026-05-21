<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Global event listener to disable caching on HTML responses
 * This ensures users always get fresh data without needing hard refresh
 * Priority -1001 ensures this runs AFTER SessionListener (-1000)
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -1001)]
class NoCacheResponseListener
{
    public function onKernelResponse(ResponseEvent $event): void
    {
        // Only handle main requests (not sub-requests)
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $request = $event->getRequest();
        
        // Skip for static assets (CSS, JS, images)
        $path = $request->getPathInfo();
        if (preg_match('/\.(css|js|jpg|jpeg|png|gif|svg|woff|woff2|ttf|eot|ico)$/i', $path)) {
            return;
        }
        
        // Skip for API endpoints that return JSON (they handle their own caching)
        if (str_starts_with($path, '/api/')) {
            return;
        }
        
        // Only apply to HTML responses
        $contentType = $response->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'text/html') && $contentType !== '') {
            return;
        }
        
        // Add cache control headers to prevent browser caching of HTML pages
        // Using replace: true to override any existing headers
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0, post-check=0, pre-check=0', true);
        $response->headers->set('Pragma', 'no-cache', true);
        $response->headers->set('Expires', '0', true);
        
        // Remove any ETag that might cause caching
        $response->headers->remove('ETag');
        
        // Remove Last-Modified that might cause caching
        $response->headers->remove('Last-Modified');
    }
}
