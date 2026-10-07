<?php
/**
 * Logout: accepts POST with a CSRF token (sent by the sidebar/header buttons),
 * so another website cannot sign the user out with a hidden link.
 */

require_once __DIR__ . '/../includes/auth_check.php';

send_no_cache_headers();

if (!is_post() || !csrf_is_valid()) {
    redirect(is_logged_in() ? 'pages/dashboard.php' : 'auth/login.php');
}

if (is_logged_in()) {
    log_activity('logout', 'user', $_SESSION['user_id'], 'Signed out');
}

// 1. Unset session data, 2. delete the cookie and destroy the session.
destroy_session();

// 3. Start a fresh, empty session only to carry the goodbye message.
start_secure_session();
flash('success', 'You have been signed out.');

// 4. Redirect to login (protected pages send no-store headers, so Back
//    reloads them from the server and the auth check rejects the request).
redirect('auth/login.php');
