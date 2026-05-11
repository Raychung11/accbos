<?php
declare(strict_types=1);

/**
 * ACCBOS database configuration.
 * Reads from environment variables when present; falls back to development defaults.
 *
 * On Hostinger / shared hosting, set these values directly below.
 */

require_once __DIR__ . '/app_config.php';

$dbHost = getenv('ACCBOS_DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('ACCBOS_DB_PORT') ?: '3306';
$dbName = getenv('ACCBOS_DB_NAME') ?: 'accbos';
$dbUser = getenv('ACCBOS_DB_USER') ?: 'accbos_user';
$dbPass = getenv('ACCBOS_DB_PASS') ?: 'change_this_password';
$dbCharset = 'utf8mb4';

define('DB_HOST', $dbHost);
define('DB_PORT', $dbPort);
define('DB_NAME', $dbName);
define('DB_USER', $dbUser);
define('DB_PASS', $dbPass);
define('DB_CHARSET', $dbCharset);

/**
 * Return a shared PDO instance configured for ACCBOS.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE utf8mb4_unicode_ci",
        ]);
    } catch (PDOException $e) {
        error_log('[ACCBOS][DB] Connection failed: ' . $e->getMessage());
        http_response_code(500);
        echo 'Database connection error. Please contact the administrator.';
        exit;
    }

    return $pdo;
}
