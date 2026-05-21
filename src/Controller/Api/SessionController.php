<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/session')]
class SessionController extends AbstractController
{
    private string $sessionConfigPath;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir
    ) {
        $this->sessionConfigPath = $projectDir . '/config/session_config.json';
    }

    /**
     * Get session configuration
     */
    #[Route('/config', name: 'api_session_config', methods: ['GET'])]
    public function config(): JsonResponse
    {
        $config = $this->loadSessionConfig();
        
        return new JsonResponse([
            'desktop_timeout_minutes' => $config['desktop_timeout_minutes'] ?? 30,
            'check_interval_seconds' => $config['check_interval_seconds'] ?? 60,
            'pwa_ping_interval_minutes' => $config['pwa_ping_interval_minutes'] ?? 5
        ]);
    }
    /**
     * Ping endpoint to keep session alive (for PWA)
     */
    #[Route('/ping', name: 'api_session_ping', methods: ['POST'])]
    public function ping(Request $request): JsonResponse
    {
        // Check if user is authenticated
        if (!$this->getUser()) {
            return new JsonResponse(['status' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }
        
        // Detect if request is from PWA
        $isPWA = $this->isPWARequest($request);
        
        // Update session last activity time
        $session = $request->getSession();
        $session->set('last_activity', time());
        $session->set('is_pwa', $isPWA);
        
        // For PWA, extend session lifetime significantly
        if ($isPWA) {
            // Set session to expire in 30 days for PWA
            $session->migrate(false, 30 * 24 * 60 * 60);
        }
        
        return new JsonResponse([
            'status' => 'alive',
            'timestamp' => time(),
            'is_pwa' => $isPWA
        ]);
    }
    
    /**
     * Update activity timestamp (for Desktop)
     */
    #[Route('/activity', name: 'api_session_activity', methods: ['POST'])]
    public function updateActivity(Request $request): JsonResponse
    {
        // Check if user is authenticated
        if (!$this->getUser()) {
            return new JsonResponse(['status' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }
        
        // Update last activity time
        $session = $request->getSession();
        $session->set('last_activity', time());
        
        return new JsonResponse([
            'status' => 'updated',
            'timestamp' => time()
        ]);
    }
    
    /**
     * Check session status (for Desktop)
     */
    #[Route('/status', name: 'api_session_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        // Check if user is authenticated
        if (!$this->getUser()) {
            return new JsonResponse(['status' => 'expired'], Response::HTTP_UNAUTHORIZED);
        }
        
        $session = $request->getSession();
        $lastActivity = $session->get('last_activity');
        $isPWA = $session->get('is_pwa', false);
        
        // If no last activity recorded, this is a new session - set it now
        if (!$lastActivity) {
            $lastActivity = time();
            $session->set('last_activity', $lastActivity);
        }
        
        // For PWA, always return active
        if ($isPWA) {
            return new JsonResponse([
                'status' => 'active',
                'is_pwa' => true,
                'last_activity' => $lastActivity
            ]);
        }
        
        // For Desktop, check if session is still valid
        $config = $this->loadSessionConfig();
        $sessionTimeout = ($config['desktop_timeout_minutes'] ?? 30) * 60; // Convert minutes to seconds
        $inactiveTime = time() - $lastActivity;
        
        if ($inactiveTime > $sessionTimeout) {
            return new JsonResponse([
                'status' => 'expired',
                'inactive_time' => $inactiveTime
            ], Response::HTTP_UNAUTHORIZED);
        }
        
        // Don't update last_activity here - only track actual user activity
        // The JavaScript session manager tracks activity and this endpoint just checks it
        
        return new JsonResponse([
            'status' => 'active',
            'is_pwa' => false,
            'last_activity' => $lastActivity,
            'inactive_time' => $inactiveTime
        ]);
    }
    
    /**
     * Detect if request is from PWA
     */
    private function isPWARequest(Request $request): bool
    {
        // Check User-Agent for PWA indicators
        $userAgent = $request->headers->get('User-Agent', '');
        
        // Check for standalone mode header (some PWAs send this)
        $displayMode = $request->headers->get('X-Display-Mode', '');
        if ($displayMode === 'standalone') {
            return true;
        }
        
        // Check for PWA-specific headers
        if ($request->headers->has('X-Requested-With') && 
            $request->headers->get('X-Requested-With') === 'PWA') {
            return true;
        }
        
        // Check session flag
        $session = $request->getSession();
        if ($session->has('is_pwa') && $session->get('is_pwa')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Load session configuration from JSON file
     */
    private function loadSessionConfig(): array
    {
        if (!file_exists($this->sessionConfigPath)) {
            return [
                'desktop_timeout_minutes' => 30,
                'check_interval_seconds' => 60,
                'pwa_ping_interval_minutes' => 5
            ];
        }
        
        $json = file_get_contents($this->sessionConfigPath);
        return json_decode($json, true) ?? [];
    }
}
