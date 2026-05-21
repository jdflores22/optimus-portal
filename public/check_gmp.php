<?php
/**
 * Check if GMP extension is loaded for web requests
 */

header('Content-Type: application/json');

$result = [
    'gmp_loaded' => extension_loaded('gmp'),
    'php_version' => PHP_VERSION,
    'openssl_loaded' => extension_loaded('openssl'),
    'openssl_version' => OPENSSL_VERSION_TEXT ?? 'N/A'
];

if ($result['gmp_loaded']) {
    $result['status'] = 'OK';
    $result['message'] = 'GMP extension is loaded. Push notifications should work.';
} else {
    $result['status'] = 'ERROR';
    $result['message'] = 'GMP extension NOT loaded. Restart Apache to load it.';
}

echo json_encode($result, JSON_PRETTY_PRINT);
