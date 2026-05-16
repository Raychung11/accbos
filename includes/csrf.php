<?php
declare(strict_types=1);

/**
 * Minimal CSRF token helper.
 * Token is bound to the session and rotated only after consumption on success.
 */

require_once __DIR__ . '/../config/app_config.php';

/**
 * Generate or fetch the current CSRF token.
 */
function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Render a hidden input carrying the CSRF token for forms.
 */
function csrf_input(): string
{
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME .
           '" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verify the CSRF token on a POST request. Aborts on failure.
 */
function csrf_verify(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $expected = $_SESSION[CSRF_TOKEN_NAME] ?? '';
    $provided = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!is_string($expected) || !is_string($provided) || $expected === '' ||
        !hash_equals($expected, $provided)) {
        http_response_code(419);
        echo 'CSRF token mismatch. Please reload the page and try again.';
        exit;
    }
}
