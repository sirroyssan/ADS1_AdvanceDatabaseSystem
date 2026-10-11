<?php
/**
 * Penalty Rules - run the automatic penalty check now (POST) for the signed-in
 * user's open obligations (the daily automatic run covers everyone's).
 */

require_once __DIR__ . '/../../includes/auth_check.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/penalty_rules/index.php');
}
require_csrf('pages/penalty_rules/index.php');

try {
    // Same selection as penalty_run_all(), limited to the user's own obligations.
    $open = db_all(
        "SELECT * FROM borrower_obligations o
          WHERE o.outstanding_amount > 0 AND o.status NOT IN ('cancelled', 'paid', 'written_off')
            AND (o.due_date < :today OR o.transaction_id IS NOT NULL) AND " . owner_scope('borrower_obligations', 'o'),
        [':today' => date('Y-m-d')]
    );
    $created = 0;
    foreach ($open as $o) {
        $created += db_transaction(static fn () => penalty_run_obligation($o));
    }
    $checked = count($open);
    log_activity('penalty_run', 'system', null, 'Penalty check (manual): ' . $created . ' new penalties on ' . $checked . ' open obligation(s)');
    flash('success', 'Checked ' . $checked . ' open obligation(s): ' . ($created ? $created . ' new penalt' . ($created === 1 ? 'y was' : 'ies were') . ' charged.' : 'everything is already up to date.'));
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect('pages/penalty_rules/index.php');
