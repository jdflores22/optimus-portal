<?php
header('Content-Type: text/plain');

echo "=== PHP Configuration Check ===\n\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Loaded php.ini: " . php_ini_loaded_file() . "\n";
echo "Additional ini files: " . (php_ini_scanned_files() ?: 'None') . "\n\n";

echo "=== Extension Status ===\n";
echo "GMP loaded: " . (extension_loaded('gmp') ? 'YES' : 'NO') . "\n";
echo "OpenSSL loaded: " . (extension_loaded('openssl') ? 'YES' : 'NO') . "\n";
echo "mbstring loaded: " . (extension_loaded('mbstring') ? 'YES' : 'NO') . "\n\n";

if (extension_loaded('gmp')) {
    echo "✅ GMP is loaded - push notifications should work!\n";
} else {
    echo "❌ GMP is NOT loaded\n\n";
    echo "Troubleshooting:\n";
    echo "1. Check if extension=gmp is uncommented in: " . php_ini_loaded_file() . "\n";
    echo "2. Check if php_gmp.dll exists in PHP ext directory\n";
    echo "3. Restart Apache after making changes\n";
    echo "4. Check Apache error log for extension loading errors\n";
}

echo "\n=== Loaded Extensions ===\n";
$extensions = get_loaded_extensions();
sort($extensions);
foreach ($extensions as $ext) {
    if (stripos($ext, 'gmp') !== false || stripos($ext, 'openssl') !== false) {
        echo "• $ext\n";
    }
}
