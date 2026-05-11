<?php
declare(strict_types=1);

/**
 * Shared helper functions for ACCBOS.
 */

require_once __DIR__ . '/../config/db_config.php';

/**
 * HTML-escape a value for output.
 */
function e($value): string
{
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Build an absolute URL from a path relative to the app base URL.
 */
function url(string $path = ''): string
{
    $path = '/' . ltrim($path, '/');
    return APP_BASE_URL . $path;
}

/**
 * Redirect to a path within the application.
 */
function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/**
 * Read a trimmed POST value as a string.
 */
function postStr(string $key, string $default = ''): string
{
    if (!isset($_POST[$key])) {
        return $default;
    }
    $value = $_POST[$key];
    if (is_array($value)) {
        return $default;
    }
    return trim((string)$value);
}

/**
 * Read a GET value as a string.
 */
function getStr(string $key, string $default = ''): string
{
    if (!isset($_GET[$key])) {
        return $default;
    }
    $value = $_GET[$key];
    if (is_array($value)) {
        return $default;
    }
    return trim((string)$value);
}

/**
 * Read a GET value as a positive integer.
 */
function getInt(string $key, int $default = 0): int
{
    if (!isset($_GET[$key])) {
        return $default;
    }
    if (!is_scalar($_GET[$key])) {
        return $default;
    }
    return (int)$_GET[$key];
}

/**
 * Set a flash message stored in the session and shown on the next request.
 */
function flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Pop and return all flash messages.
 *
 * @return array<int,array{type:string,message:string}>
 */
function flash_pull(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/**
 * Mask an API secret for display. Reveals only the first and last 2 chars.
 */
function mask_secret(?string $secret): string
{
    if ($secret === null || $secret === '') {
        return '';
    }
    $len = strlen($secret);
    if ($len <= 4) {
        return str_repeat('*', $len);
    }
    return substr($secret, 0, 2) . str_repeat('*', max(4, $len - 4)) . substr($secret, -2);
}

/**
 * Format a money amount with 2 decimals.
 */
function money(float $value): string
{
    return number_format($value, 2, '.', ',');
}

/**
 * Render a Bootstrap badge for a status value.
 */
function status_badge(string $status): string
{
    $map = [
        // local_status
        'draft'         => 'secondary',
        'ready_to_push' => 'info',
        'pushed'        => 'success',
        'failed'        => 'danger',
        'cancelled'     => 'dark',
        // accounting_status
        'pending'       => 'warning',
        'success'       => 'success',
        // queue
        'processing'    => 'info',
        'active'        => 'success',
        'inactive'      => 'secondary',
    ];
    $cls = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . e($cls) . '">' . e($status) . '</span>';
}

/**
 * Persist an API call entry to the api_logs table.
 *
 * @param array<string,mixed> $entry
 */
function log_api_call(array $entry): void
{
    $sql = "INSERT INTO api_logs
            (company_id, module, action, endpoint, request_payload, response_payload,
             http_status, status, error_message)
            VALUES (:company_id, :module, :action, :endpoint, :request_payload, :response_payload,
                    :http_status, :status, :error_message)";
    $stmt = db()->prepare($sql);
    $stmt->execute([
        ':company_id'       => $entry['company_id'] ?? null,
        ':module'           => $entry['module'] ?? null,
        ':action'           => $entry['action'] ?? null,
        ':endpoint'         => $entry['endpoint'] ?? null,
        ':request_payload'  => isset($entry['request_payload']) && !is_string($entry['request_payload'])
                                 ? json_encode($entry['request_payload'])
                                 : ($entry['request_payload'] ?? null),
        ':response_payload' => isset($entry['response_payload']) && !is_string($entry['response_payload'])
                                 ? json_encode($entry['response_payload'])
                                 : ($entry['response_payload'] ?? null),
        ':http_status'      => $entry['http_status'] ?? null,
        ':status'           => $entry['status'] ?? 'failed',
        ':error_message'    => $entry['error_message'] ?? null,
    ]);
}

/**
 * Fetch the active list of companies for dropdowns.
 *
 * @return array<int,array<string,mixed>>
 */
function fetch_companies_active(): array
{
    $stmt = db()->query(
        "SELECT company_id, company_name, accounting_system
         FROM companies WHERE status = 'active'
         ORDER BY company_name ASC"
    );
    return $stmt->fetchAll();
}
