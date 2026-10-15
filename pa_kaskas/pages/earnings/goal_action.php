<?php
/**
 * Earnings - mark a saving goal used, or delete it (POST only).
 * A goal only keeps track, so deleting it never touches earnings.
 */

require_once __DIR__ . '/../../includes/auth_check.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/earnings/index.php');
}
require_csrf('pages/earnings/index.php');
$goal = find_or_redirect(savings_goal_find(request_id()), 'pages/earnings/index.php', 'Saving goal');

if (input_string($_POST, 'action') === 'used') {
    db_update('savings_goals', ['status' => 'used', 'used_at' => date('Y-m-d H:i:s')], $goal['id']);
    log_activity('update', 'savings_goal', $goal['id'], 'Marked saving goal ' . $goal['name'] . ' as used', ['status' => $goal['status']], ['status' => 'used']);
    flash('success', '"' . $goal['name'] . '" was marked as used.');
} elseif (input_string($_POST, 'action') === 'delete') {
    db_exec('DELETE FROM savings_goals WHERE id = :id', [':id' => $goal['id']]);
    log_activity('delete', 'savings_goal', $goal['id'], 'Deleted saving goal ' . $goal['name'], $goal);
    flash('success', 'Saving goal "' . $goal['name'] . '" was deleted.');
}

redirect('pages/earnings/index.php');
