<?php
/**
 * Entry point: http://localhost/paswipe_me/
 * Sends signed-in users to the dashboard and everyone else to the login page.
 */

require_once __DIR__ . '/includes/auth_check.php';

redirect(is_logged_in() ? 'pages/dashboard.php' : 'auth/login.php');
