<?php

declare(strict_types=1);

use App\Kernel;

/**
 * Shared bootstrap for root index.php and public/index.php on Hostinger
 * when Apache DocumentRoot is the project folder (not public/).
 */
if (!function_exists('optimus_prepare_request')) {
    function optimus_prepare_request(): bool
    {
        $projectRoot = dirname(__DIR__);
        $isProjectRootDeployment = is_file($projectRoot . '/composer.json');

        if (!$isProjectRootDeployment) {
            return false;
        }

        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

        if (str_contains($path, '/public')) {
            $cleanPath = preg_replace('#(/public)+#', '', $path) ?: '/';
            $query = parse_url($requestUri, PHP_URL_QUERY);
            header('Location: ' . $cleanPath . ($query ? '?' . $query : ''), true, 301);
            exit;
        }

        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if (str_ends_with($scriptName, '/public/index.php')) {
            $_SERVER['SCRIPT_NAME'] = substr($scriptName, 0, -strlen('/public/index.php')) . '/index.php';
            if (isset($_SERVER['PHP_SELF']) && str_contains((string) $_SERVER['PHP_SELF'], '/public/index.php')) {
                $_SERVER['PHP_SELF'] = str_replace('/public/index.php', '/index.php', (string) $_SERVER['PHP_SELF']);
            }
        }

        return true;
    }
}

if (!function_exists('optimus_kernel_factory')) {
    function optimus_kernel_factory(): callable
    {
        return function (array $context) {
            return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
        };
    }
}
