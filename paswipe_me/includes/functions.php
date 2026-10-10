<?php
/**
 * General helper functions shared by all pages.
 */

defined('APP_ROOT') or exit('No direct access.');

/* ------------------------------------------------------------------
 * Output escaping
 * ------------------------------------------------------------------ */

/**
 * Escape a value for safe HTML output.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ------------------------------------------------------------------
 * URLs and redirects
 * ------------------------------------------------------------------ */

/**
 * Web path of the project folder, e.g. "/paswipe_me" (no trailing slash).
 *
 * Auto-detected by comparing the running script's file path with its URL,
 * so the project works in any folder without hardcoding a path.
 */
function app_base_url(): string
{
    static $base = null;

    if ($base !== null) {
        return $base;
    }

    if (APP_BASE_URL !== '') {
        return $base = rtrim(APP_BASE_URL, '/');
    }

    $root   = str_replace('\\', '/', (string) realpath(APP_ROOT));
    $script = str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $url    = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    $base = '';
    if ($root !== '' && $script !== '' && str_starts_with($script, $root . '/')) {
        // Number of folders between the project root and the running script.
        $depth = substr_count(substr($script, strlen($root) + 1), '/');
        $path  = dirname($url);
        for ($i = 0; $i < $depth; $i++) {
            $path = dirname($path);
        }
        // dirname() returns "\" for the root on Windows.
        $path = str_replace('\\', '/', $path);
        $base = rtrim($path, '/');
    }

    return $base;
}

/**
 * Build an absolute web path to a file inside the project.
 * Example: url('assets/css/base.css') -> "/paswipe_me/assets/css/base.css"
 */
function url(string $path = '', array $query = []): string
{
    $result = app_base_url() . '/' . ltrim($path, '/');

    if ($query) {
        $result .= '?' . http_build_query($query);
    }

    return $result;
}

/**
 * Redirect to a project path and stop the script.
 */
function redirect(string $path, array $query = []): void
{
    if (is_modal_request()) {
        modal_response(url($path, $query));
    }
    header('Location: ' . url($path, $query));
    exit;
}

/**
 * True when the request comes from a modal form (assets/js/modal-form.js).
 * The same pages and handlers serve both the full page and the modal.
 */
function is_modal_request(): bool
{
    return ($_SERVER['HTTP_X_MODAL_FORM'] ?? '') === '1';
}

/**
 * Answer a modal form instead of redirecting: {ok, redirect, error}.
 * Error messages are taken out of the flash queue and shown inside the
 * modal (which stays open); success messages stay queued and appear when
 * the page content is refreshed.
 */
function modal_response(string $location): never
{
    $errors = [];
    $keep = [];
    foreach ($_SESSION['flash'] ?? [] as $message) {
        if ($message['type'] === 'error') {
            $errors[] = $message['message'];
        } else {
            $keep[] = $message;
        }
    }
    $_SESSION['flash'] = $keep;

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => !$errors, 'redirect' => $location, 'error' => implode(' ', $errors)], JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Add the asset's modification time so browsers reload changed CSS/JS.
 */
function asset(string $path): string
{
    $file = APP_ROOT . '/' . ltrim($path, '/');
    $version = is_file($file) ? (string) filemtime($file) : '1';

    return url($path, ['v' => $version]);
}

/* ------------------------------------------------------------------
 * Session
 * ------------------------------------------------------------------ */

/**
 * Start the PHP session with secure cookie settings.
 */
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_name('PASWIPESESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => app_base_url() . '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * Tell the browser not to cache protected pages, so "Back" after logout
 * does not show private data.
 */
function send_no_cache_headers(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/* ------------------------------------------------------------------
 * Flash messages (shown once after a redirect)
 * ------------------------------------------------------------------ */

/**
 * @param string $type success | error | warning | info
 */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flash_messages(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $messages;
}

/* ------------------------------------------------------------------
 * Request helpers
 * ------------------------------------------------------------------ */

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/**
 * Read a trimmed string from GET or POST. Arrays are rejected.
 */
function input_string(array $source, string $key, string $default = ''): string
{
    $value = $source[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

/**
 * Read a positive integer from GET, falling back to $default.
 */
function input_int(array $source, string $key, int $default, int $min = 1, int $max = PHP_INT_MAX): int
{
    $value = filter_var($source[$key] ?? null, FILTER_VALIDATE_INT);

    if ($value === false || $value < $min || $value > $max) {
        return $default;
    }

    return $value;
}

/**
 * The schema uses CHAR(36) UUID primary keys.
 */
function is_valid_uuid(string $value): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
}

/**
 * Ask MariaDB for a new UUID so the id is known before the INSERT
 * (needed to redirect to the new record; lastInsertId() does not work
 * for CHAR(36) keys).
 */
function new_uuid(): string
{
    return (string) db()->query('SELECT UUID()')->fetchColumn();
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/**
 * Escape % and _ so user text is matched literally inside LIKE.
 */
function like_value(string $term): string
{
    return '%' . addcslashes($term, '\\%_') . '%';
}

/* ------------------------------------------------------------------
 * Formatting
 * ------------------------------------------------------------------ */

function format_datetime(?string $value, string $format = 'M j, Y g:i A'): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    $timestamp = strtotime($value);

    return $timestamp ? date($format, $timestamp) : '—';
}

function format_date(?string $value): string
{
    return format_datetime($value, 'M j, Y');
}

function format_number(int|float $value, int $decimals = 0): string
{
    return number_format($value, $decimals);
}

function format_money(int|float|string|null $value): string
{
    return '₱' . number_format((float) $value, 2);
}

/**
 * Shorten long text for table cells.
 */
function excerpt(?string $text, int $length = 80): string
{
    $text = trim((string) $text);

    if (mb_strlen($text) <= $length) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, $length - 1)) . '…';
}

/* ------------------------------------------------------------------
 * Pagination
 * ------------------------------------------------------------------ */

/**
 * Calculate pagination values.
 *
 * @return array{page:int, per_page:int, total:int, pages:int, offset:int, from:int, to:int}
 */
function paginate(int $total, int $page, int $perPage): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min(max(1, $page), $pages);
    $offset = ($page - 1) * $perPage;

    return [
        'page'     => $page,
        'per_page' => $perPage,
        'total'    => $total,
        'pages'    => $pages,
        'offset'   => $offset,
        'from'     => $total === 0 ? 0 : $offset + 1,
        'to'       => min($offset + $perPage, $total),
    ];
}

/**
 * Page numbers to show, with null where a "…" gap belongs.
 *
 * @return array<int|null>
 */
function pagination_links(int $current, int $pages, int $window = 1): array
{
    $links = [];
    $last = 0;

    for ($i = 1; $i <= $pages; $i++) {
        $isEdge = $i === 1 || $i === $pages;
        $isNear = abs($i - $current) <= $window;

        if ($isEdge || $isNear) {
            if ($last && $i - $last > 1) {
                $links[] = null;
            }
            $links[] = $i;
            $last = $i;
        }
    }

    return $links;
}

/* ------------------------------------------------------------------
 * Templates
 * ------------------------------------------------------------------ */

/**
 * Replace {{placeholders}} in agreement / demand letter templates.
 * Unknown placeholders are left as they are so they are easy to spot.
 */
function fill_template(string $body, array $values): string
{
    return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static function (array $m) use ($values) {
        return array_key_exists($m[1], $values) ? (string) $values[$m[1]] : $m[0];
    }, $body) ?? $body;
}
