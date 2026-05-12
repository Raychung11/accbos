<?php
declare(strict_types=1);

/**
 * ACCBOS application configuration.
 * Loaded from every entry point through includes/auth.php.
 */

if (!defined('ACCBOS_APP')) {
    define('ACCBOS_APP', true);
}

// Application identity
const APP_NAME        = 'ACCBOS';
const APP_FULL_NAME   = 'Accounting Connector for BOS';
const APP_VERSION     = '1.0.0-phase1';
const APP_TIMEZONE    = 'Asia/Kuala_Lumpur';

// Base URL helpers. Override APP_BASE_URL in environment if required.
// Default is '' (deployed at site root, e.g. public_html/).
// If you upload into a subfolder such as public_html/accbos, set ACCBOS_BASE_URL=/accbos.
$appBaseUrl = getenv('ACCBOS_BASE_URL');
if ($appBaseUrl === false) {
    $appBaseUrl = '';
}
define('APP_BASE_URL', rtrim($appBaseUrl, '/'));

// Session lifetime in seconds (idle timeout)
const SESSION_IDLE_TIMEOUT = 1800; // 30 minutes

// HTTP client defaults for connectors
const HTTP_CONNECT_TIMEOUT = 10;
const HTTP_TIMEOUT         = 30;

// Log files (relative to project root)
const LOG_DIR = __DIR__ . '/../logs';

// CSRF token name
const CSRF_TOKEN_NAME = 'accbos_csrf';

// Encryption key for masking secrets when displayed.
// In production replace this with a 32-byte random string stored outside the repo.
$cfgKey = getenv('ACCBOS_APP_KEY');
if ($cfgKey === false || $cfgKey === '') {
    $cfgKey = 'change_this_app_key_in_production_please_32b';
}
define('APP_KEY', $cfgKey);

date_default_timezone_set(APP_TIMEZONE);

// Apply secure defaults to session cookies before any session_start().
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');

// Display nothing to the browser; log instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0775, true);
}
ini_set('error_log', LOG_DIR . '/php_error.log');
