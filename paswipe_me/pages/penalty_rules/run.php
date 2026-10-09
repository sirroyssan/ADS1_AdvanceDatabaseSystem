<?php
/**
 * Penalty Rules - run the automatic penalty check for every open obligation now (POST).
 */

require_once __DIR__ . '/../../includes/auth_check.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/penalty_rules/index.php');
}
require_csrf('pages/penalty_rules/index.php');

try {
    [$checked, $created] = penalty_run_all();
    log_activity('penalty_run', 'system', null, 'Penalty check (manual): ' . $created . ' new penalties on ' . $checked . ' open obligation(s)');
    flash('success', 'Checked ' . $checked . ' open obligation(s): ' . ($created ? $created . ' new penalt' . ($created === 1 ? 'y was' : 'ies were') . ' charged.' : 'everything is already up to date.'));
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect('pages/penalty_rules/index.php');
