<?php
/**
 * CSRF protection.
 *
 * One random token is stored in the session. Every state-changing form
 * (login, create, update, delete, logout) sends it back in a hidden field,
 * and the server compares the two with hash_equals() before doing anything.
 * A malicious site cannot read the token, so it cannot forge the request.
 */

defined('APP_ROOT') or exit('No direct access.');

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Hidden input to place inside every POST form.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_is_valid(): bool
{
    $sent = $_POST['csrf_token'] ?? '';

    return is_string($sent)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $sent);
}

/**
 * Stop the request when the token is missing or wrong, and send the user
 * back to $redirectPath (a project path such as 'pages/categories/index.php').
 */
function require_csrf(string $redirectPath = 'pages/dashboard.php'): void
{
    if (!csrf_is_valid()) {
        flash('error', 'Your form has expired. Please try again.');
        redirect($redirectPath);
    }
}

/**
 * One-time token for a form that must not be saved twice (a double click or a
 * resent request): issued with the form, used up by the first successful save.
 */
function form_once_token(string $form): string
{
    $token = bin2hex(random_bytes(16));
    $_SESSION['form_once'][$form][$token] = time();
    // Keep the list short.
    $_SESSION['form_once'][$form] = array_slice($_SESSION['form_once'][$form], -20, null, true);

    return $token;
}

function form_once_use(string $form, string $token): bool
{
    if ($token === '' || !isset($_SESSION['form_once'][$form][$token])) {
        return false;
    }
    unset($_SESSION['form_once'][$form][$token]);

    return true;
}

/** Hidden field carrying a one-time token for $form. */
function form_once_field(string $form): string
{
    return '<input type="hidden" name="once" value="' . e(form_once_token($form)) . '">';
}
