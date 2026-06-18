<?php

declare(strict_types=1);

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

if ($base !== '' && str_starts_with($path, $base)) {
    $suffix = substr($path, strlen($base)) ?: '/';
} else {
    $suffix = $path;
}

$target = $base . '/public' . ($suffix === '/' ? '/' : $suffix);

$query = parse_url($requestUri, PHP_URL_QUERY);
if ($query !== null && $query !== '') {
    $target .= '?' . $query;
}

header('Location: ' . $target, true, 302);
exit;
