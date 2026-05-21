<?php
/**
 * Suppress Deprecation Warnings from thecodingmachine/safe Library
 * Add this at the top of scripts to suppress harmless PHP 8.4 warnings
 */

// Suppress specific deprecation warnings from vendor libraries
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// Alternative: Only suppress warnings, keep errors
// error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
