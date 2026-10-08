<?php
/**
 * Penalty Rules - delete (unused only) or deactivate.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/penalty_rule_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/penalty_rules/index.php');
}

$rule = find_or_redirect(penalty_rule_find(request_id()), 'pages/penalty_rules/index.php', 'Penalty rule');
require_csrf('pages/penalty_rules/view.php?id=' . $rule['id']);

if (input_string($_POST, 'action') === 'deactivate') {
    db_update('penalty_rules', ['is_active' => 0], $rule['id']);
    log_activity('deactivate', 'penalty_rule', $rule['id'], 'Deactivated penalty rule "' . $rule['name'] . '"', ['is_active' => 1], ['is_active' => 0]);
    flash('success', 'Penalty rule "' . $rule['name'] . '" was deactivated.');
    redirect('pages/penalty_rules/view.php', ['id' => $rule['id']]);
}

try {
    db_transaction(function () use ($rule) {
        db_value('SELECT id FROM penalty_rules WHERE id = :id FOR UPDATE', [':id' => $rule['id']]);
        $usage = penalty_rule_usage($rule['id']);
        if ($usage['total'] > 0) {
            throw new DomainException('This rule cannot be deleted because it is used by ' . usage_text($usage) . '. Deactivate it instead.');
        }
        db_exec('DELETE FROM penalty_rules WHERE id = :id', [':id' => $rule['id']]);
    });

    log_activity('delete', 'penalty_rule', $rule['id'], 'Deleted penalty rule "' . $rule['name'] . '"', $rule);
    flash('success', 'Penalty rule "' . $rule['name'] . '" was deleted.');
    redirect('pages/penalty_rules/index.php');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect('pages/penalty_rules/view.php', ['id' => $rule['id']]);
