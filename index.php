<?php
declare(strict_types=1);

/**
 * Front controller. Sends visitors to the dashboard if signed in, otherwise login.
 * Wrapped in a try/catch so a misconfigured DB or missing file surfaces a
 * branded error page rather than a blank screen.
 */

try {
    require_once __DIR__ . '/includes/auth.php';

    if (auth_check()) {
        redirect('/admin/dashboard.php');
    }
    redirect('/admin/login.php');
} catch (Throwable $e) {
    error_log('[ACCBOS] index bootstrap failed: ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>ACCBOS</title>
        <style>
            body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
                   background: linear-gradient(135deg, #5b3df5 0%, #3b82f6 100%);
                   color: #fff; min-height: 100vh; display: flex;
                   align-items: center; justify-content: center; margin: 0; }
            .box { background: rgba(255,255,255,0.08); padding: 2rem 2.5rem;
                   border-radius: 1rem; max-width: 480px; text-align: center; }
            h1 { margin: 0 0 .5rem; font-size: 1.5rem; }
            p  { margin: .25rem 0; opacity: .85; }
            code { background: rgba(0,0,0,0.25); padding: .1rem .4rem;
                   border-radius: .25rem; }
        </style>
    </head>
    <body>
        <div class="box">
            <h1>ACCBOS</h1>
            <p>The application started but could not finish booting.</p>
            <p>Check the database credentials in <code>config/db_config.php</code>
               and that <code>database/schema.sql</code> has been imported.</p>
            <p style="opacity:.5;font-size:.85rem;margin-top:1rem;">
                See <code>logs/php_error.log</code> for details.
            </p>
        </div>
    </body>
    </html>
    <?php
}
