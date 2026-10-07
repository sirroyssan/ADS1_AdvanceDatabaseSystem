<?php
/**
 * Turns uncaught errors into a readable page instead of a blank
 * "HTTP ERROR 500", and logs the technical details.
 *
 * Users see a plain explanation and what to check. Raw SQL/PHP messages
 * are shown only when APP_DEBUG is true in config/app.php.
 */

defined('APP_ROOT') or exit('No direct access.');

/**
 * Plain-language hint for the most common XAMPP setup problems.
 */
function setup_hint(Throwable $e): string
{
    if ($e instanceof PDOException) {
        $code = (int) ($e->errorInfo[1] ?? 0);
        $message = $e->getMessage();

        if ($code === 1146 || str_contains($message, "doesn't exist")) {
            return 'A database table is missing. Import db/pa_swipe_ako_mariadb_10_6_v2.sql into the "'
                . (defined('DB_NAME') ? DB_NAME : '') . '" database in phpMyAdmin.';
        }
        if ($code === 1049) {
            return 'The database does not exist. Create it in phpMyAdmin, or fix DB_NAME in config/database.php.';
        }
        if ($code === 1045) {
            return 'MySQL rejected the username/password. Check DB_USER and DB_PASS in config/database.php.';
        }
        if ($code === 2002) {
            return 'MySQL is not running. Start MySQL in the XAMPP Control Panel.';
        }

        return 'A database error occurred. Open setup_check.php to verify the database setup.';
    }

    if ($e instanceof Error && str_contains($e->getMessage(), 'undefined function mb_')) {
        return 'The PHP "mbstring" extension is disabled. Enable extension=mbstring in xampp/php/php.ini and restart Apache.';
    }

    return 'An unexpected error occurred. Open setup_check.php to verify the installation.';
}

function render_error_page(string $hint, ?string $details = null): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Error: ' . $hint . PHP_EOL . ($details !== null ? $details . PHP_EOL : ''));
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    $hint = htmlspecialchars($hint, ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'Application', ENT_QUOTES, 'UTF-8');
    $detailsHtml = '';

    if ($details !== null && defined('APP_DEBUG') && APP_DEBUG) {
        $detailsHtml = '<pre style="white-space:pre-wrap;background:#f4f6fa;border:1px solid #e1e5ee;'
            . 'padding:12px;border-radius:8px;font-size:13px;">' . htmlspecialchars($details, ENT_QUOTES, 'UTF-8') . '</pre>';
    } else {
        $detailsHtml = '<p style="color:#5d6880;font-size:14px;">Technical details were written to the PHP error log '
            . '(XAMPP: <code>xampp/php/logs/php_error_log</code> or <code>xampp/apache/logs/error.log</code>). '
            . 'To show them here, set <code>APP_DEBUG</code> to <code>true</code> in <code>config/app.php</code>.</p>';
    }

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Something went wrong · ' . $name . '</title></head>'
        . '<body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f4f6fa;color:#172033;margin:0;padding:24px 16px;">'
        . '<main style="max-width:640px;margin:40px auto;background:#fff;border:1px solid #e1e5ee;border-radius:14px;padding:28px;">'
        . '<h1 style="margin:0 0 8px;font-size:22px;">Something went wrong</h1>'
        . '<p style="margin:0 0 16px;font-size:16px;">' . $hint . '</p>'
        . $detailsHtml
        . '</main></body></html>';
}

set_exception_handler(static function (Throwable $e): void {
    error_log('[' . (defined('APP_NAME') ? APP_NAME : 'app') . '] ' . get_class($e) . ': '
        . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    render_error_page(
        setup_hint($e),
        get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString()
    );
});

// Fatal errors (e.g. syntax errors, memory limits) bypass the exception handler.
register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    render_error_page(
        'A PHP error stopped this page. Open setup_check.php to verify the installation.',
        $error['message'] . "\n" . $error['file'] . ':' . $error['line']
    );
});
