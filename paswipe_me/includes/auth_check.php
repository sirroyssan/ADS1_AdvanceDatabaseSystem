<?php
/**
 * Authentication and authorization for protected pages.
 *
 * Usage at the top of every admin page:
 *     require_once __DIR__ . '/../includes/auth_check.php';   // adjust depth
 *     require_admin();
 *
 * Checks run on the server on every request, so typing a URL directly
 * (e.g. /pages/categories/delete.php) is blocked the same way as a link.
 */

require_once __DIR__ . '/bootstrap.php';

// Display order when a user has more than one role (user_roles allows several).
const ROLE_PRIORITY = ['owner', 'admin', 'borrower'];

const ROLE_LABELS = [
    'owner'    => 'Owner',
    'admin'    => 'Administrator',
    'borrower' => 'Borrower',
];

/**
 * Roles of a user from user_roles, highest priority first.
 *
 * @return string[]
 */
function fetch_user_roles(string $userId): array
{
    $stmt = db()->prepare('SELECT role FROM user_roles WHERE user_id = :id');
    $stmt->execute([':id' => $userId]);
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return array_values(array_intersect(ROLE_PRIORITY, $roles));
}

function role_label(?string $role): string
{
    return ROLE_LABELS[$role] ?? 'User';
}

/**
 * Name to show in the header: first + last name, else username, else email.
 */
function user_display_name(array $user): string
{
    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

    return $name !== '' ? $name : (string) ($user['username'] ?: $user['email']);
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

/**
 * Clear the session completely (used by logout and forced sign-outs).
 */
function destroy_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

/**
 * End the session and send the user to the login page with a message.
 */
function force_logout(string $message): void
{
    destroy_session();
    start_secure_session();
    flash('warning', $message);
    redirect('auth/login.php');
}

/**
 * The logged-in user, re-read from the database once per request so that a
 * suspended account or a removed role takes effect immediately.
 */
function current_user(): ?array
{
    static $user = false;

    if ($user !== false) {
        return $user;
    }

    if (!is_logged_in()) {
        return $user = null;
    }

    $stmt = db()->prepare(
        'SELECT id, username, email, first_name, middle_name, last_name, status
           FROM users
          WHERE id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $row = $stmt->fetch();

    if (!$row) {
        return $user = null;
    }

    $row['roles'] = fetch_user_roles($row['id']);
    $row['role']  = $row['roles'][0] ?? null;

    return $user = $row;
}

/**
 * Step 1: is someone logged in, with a valid, active account?
 */
function require_login(): array
{
    send_no_cache_headers();

    if (!is_logged_in()) {
        flash('info', 'Please sign in to continue.');
        redirect('auth/login.php');
    }

    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    if ($lastActivity && time() - $lastActivity > SESSION_IDLE_TIMEOUT) {
        log_activity('session_timeout', 'user', $_SESSION['user_id'], 'Session expired after inactivity');
        force_logout('Your session expired due to inactivity. Please sign in again.');
    }
    $_SESSION['last_activity'] = time();

    $user = current_user();

    if ($user === null) {
        force_logout('Your account could not be found. Please sign in again.');
    }

    if ($user['status'] !== 'active') {
        force_logout('Your account is no longer active.');
    }

    // Keep header information in sync with the database.
    $_SESSION['user_roles']   = $user['roles'];
    $_SESSION['user_role']    = $user['role'];
    $_SESSION['display_name'] = user_display_name($user);

    return $user;
}

/**
 * Step 2: does the user have one of the required roles?
 *
 * @param string[] $roles
 */
function require_role(array $roles): array
{
    $user = require_login();

    if (!array_intersect($roles, $user['roles'])) {
        log_activity('access_denied', 'page', null, 'Denied: ' . ($_SERVER['REQUEST_URI'] ?? ''));
        http_response_code(403);
        require APP_ROOT . '/auth/access_denied.php';
        exit;
    }

    return $user;
}

/**
 * Owner or admin (see ADMIN_ROLES in config/app.php).
 */
function require_admin(): array
{
    $user = require_role(ADMIN_ROLES);
    // Cards saved before used credit was calculated get their opening balance once.
    card_usage_ensure_opening_balances();
    // Monthly penalties on overdue amounts are charged once a day, on the first page load.
    penalty_run_if_due();

    return $user;
}

/**
 * The Owner role (the card owner). Some actions, like overriding a payment
 * amount, are reserved for owners - administrators cannot do them.
 */
function user_is_owner(?array $user): bool
{
    return $user !== null && in_array('owner', $user['roles'], true);
}

function user_is_admin(?array $user): bool
{
    return $user !== null && (bool) array_intersect(ADMIN_ROLES, $user['roles']);
}
