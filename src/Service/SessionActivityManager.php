<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Centralizes idle-session tracking so all tabs share one server-side activity clock.
 */
class SessionActivityManager
{
    public const LAST_ACTIVITY_KEY = '_last_activity';

    /** @deprecated Legacy key written by older session API — read for migration only */
    private const LEGACY_LAST_ACTIVITY_KEY = 'last_activity';

    private string $sessionConfigPath;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
    ) {
        $this->sessionConfigPath = $projectDir . '/config/session_config.json';
    }

    public function touch(SessionInterface $session): void
    {
        $session->set(self::LAST_ACTIVITY_KEY, time());
    }

    public function getLastActivity(SessionInterface $session): ?int
    {
        $lastActivity = $session->get(self::LAST_ACTIVITY_KEY);

        if ($lastActivity !== null) {
            return (int) $lastActivity;
        }

        $legacy = $session->get(self::LEGACY_LAST_ACTIVITY_KEY);
        if ($legacy !== null) {
            $lastActivity = (int) $legacy;
            $session->set(self::LAST_ACTIVITY_KEY, $lastActivity);

            return $lastActivity;
        }

        return null;
    }

    public function initializeIfMissing(SessionInterface $session): int
    {
        $lastActivity = $this->getLastActivity($session);
        if ($lastActivity !== null) {
            return $lastActivity;
        }

        $now = time();
        $this->touch($session);

        return $now;
    }

    public function getTimeoutSeconds(): int
    {
        $config = $this->loadSessionConfig();

        return ($config['desktop_timeout_minutes'] ?? 30) * 60;
    }

    public function getInactiveSeconds(SessionInterface $session): int
    {
        $lastActivity = $this->getLastActivity($session);
        if ($lastActivity === null) {
            return 0;
        }

        return max(0, time() - $lastActivity);
    }

    public function isExpired(SessionInterface $session): bool
    {
        $lastActivity = $this->getLastActivity($session);
        if ($lastActivity === null) {
            return false;
        }

        return $this->getInactiveSeconds($session) > $this->getTimeoutSeconds();
    }

    /**
     * @return array{desktop_timeout_minutes: int, check_interval_seconds: int, pwa_ping_interval_minutes: int}
     */
    public function getClientConfig(): array
    {
        $config = $this->loadSessionConfig();

        return [
            'desktop_timeout_minutes' => (int) ($config['desktop_timeout_minutes'] ?? 30),
            'check_interval_seconds' => (int) ($config['check_interval_seconds'] ?? 60),
            'pwa_ping_interval_minutes' => (int) ($config['pwa_ping_interval_minutes'] ?? 5),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSessionConfig(): array
    {
        if (!file_exists($this->sessionConfigPath)) {
            return [];
        }

        $json = file_get_contents($this->sessionConfigPath);

        return json_decode($json, true) ?? [];
    }
}
