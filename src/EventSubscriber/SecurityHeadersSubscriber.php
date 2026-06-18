<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -10],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        
        // Routes that should allow iframe embedding (inline viewing)
        $inlineRoutes = [
            'api_billing_download',
            'broker_manifest_billing_download',
            'accounting_payment_billing_download',
            'api_payments_download_receipt',
            'accounting_payment_receipt',
            'accounting_billing_payment_receipt',
            'api_noa_download',
            'api_edo_download',
            'api_bl_download',
            'api_manifest_download',
            'broker_edo_view_receipt',
            'document_template_preview_html',
            'document_template_preview_pdf',
        ];
        
        $routeName = $request->attributes->get('_route');
        $inline = $request->query->get('inline', 'false') === 'true';
        
        // If this is an inline viewing request for allowed routes OR if it's the broker receipt route
        if ((in_array($routeName, $inlineRoutes) && $inline)
            || $routeName === 'broker_edo_view_receipt'
            || $routeName === 'document_template_preview_html'
            || $routeName === 'document_template_preview_pdf') {
            // Allow iframe embedding from same origin
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        } else {
            // Default: prevent iframe embedding for security
            if (!$response->headers->has('X-Frame-Options')) {
                $response->headers->set('X-Frame-Options', 'DENY');
            }
            if (!$response->headers->has('Content-Security-Policy')) {
                $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
            }
        }
        
        // Add other security headers
        if (!$response->headers->has('X-Content-Type-Options')) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }
        
        if (!$response->headers->has('X-XSS-Protection')) {
            $response->headers->set('X-XSS-Protection', '1; mode=block');
        }
        
        if (!$response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
    }
}
