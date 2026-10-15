<?php
/**
 * Installment Rules - delete (unused only) or deactivate.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/rule_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/installment_rules/index.php');
}

$rule = find_or_redirect(installment_rule_find(request_id()), 'pages/installment_rules/index.php', 'Installment rule');
require_csrf('pages/installment_rules/view.php?id=' . $rule['id']);

if (input_string($_POST, 'action') === 'deactivate') {
    db_update('installment_rules', ['is_active' => 0], $rule['id']);
    log_activity('deactivate', 'installment_rule', $rule['id'], 'Deactivated installment rule "' . $rule['name'] . '"', ['is_active' => 1], ['is_active' => 0]);
    flash('success', 'Installment rule "' . $rule['name'] . '" was deactivated.');
    redirect('pages/installment_rules/view.php', ['id' => $rule['id']]);
}

try {
    db_transaction(function () use ($rule) {
        db_value('SELECT id FROM installment_rules WHERE id = :id FOR UPDATE', [':id' => $rule['id']]);
        $usage = installment_rule_usage($rule['id']);
        if ($usage['total'] > 0) {
            throw new DomainException('This rule cannot be deleted because it is used by ' . usage_text($usage) . '. Deactivate it instead.');
        }
        db_exec('DELETE FROM installment_rules WHERE id = :id', [':id' => $rule['id']]);
    });

    log_activity('delete', 'installment_rule', $rule['id'], 'Deleted installment rule "' . $rule['name'] . '"', $rule);
    flash('success', 'Installment rule "' . $rule['name'] . '" was deleted.');
    redirect('pages/installment_rules/index.php');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect('pages/installment_rules/view.php', ['id' => $rule['id']]);
