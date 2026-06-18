<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/config/front-controller.php';
optimus_prepare_request();

require_once __DIR__ . '/vendor/autoload_runtime.php';

return optimus_kernel_factory();
