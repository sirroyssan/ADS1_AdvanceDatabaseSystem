<?php
/**
 * Users & Roles - quick actions (POST): change status, clear sign-in lock.
 * There is deliberately no delete action (see user_functions.php).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/user_functions.php';

$currentUser = require_system_admin();

if (!is_post()) {
    redirect('pages/users/index.php');
}

$user = find_or_redirect(user_find(request_id()), 'pages/users/index.php', 'User');
require_csrf('pages/users/view.php?id=' . $user['id']);

$action = input_string($_POST, 'action');

if ($user['id'] === $currentUser['id']) {
    flash('error', 'You cannot change the status of your own account.');
    redirect('pages/users/view.php', ['id' => $user['id']]);
}

if ($action === 'status') {
    $status = input_string($_POST, 'status');
    if (!in_array($status, ENUM_USER_STATUS, true)) {
        flash('error', 'Invalid status.');
    } else {
        $update = ['status' => $status];
        if ($status === 'active') {
            $update += ['failed_login_attempts' => 0, 'locked_until' => null];
        }
        db_update('users', $update, $user['id']);
        log_activity('status_change', 'user', $user['id'], 'Status of ' . $user['email'] . ' changed to ' . $status, ['status' => $user['status']], ['status' => $status]);
        flash('success', 'Account status changed to ' . humanize($status) . '.');
    }
} elseif ($action === 'unlock') {
    db_update('users', ['failed_login_attempts' => 0, 'locked_until' => null], $user['id']);
    log_activity('unlock', 'user', $user['id'], 'Cleared sign-in lock for ' . $user['email']);
    flash('success', 'Sign-in lock cleared.');
}

redirect('pages/users/view.php', ['id' => $user['id']]);
