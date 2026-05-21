<?php

use App\Kernel;

// Suppress deprecation warnings from vendor libraries (PHP 8.4 compatibility)
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
