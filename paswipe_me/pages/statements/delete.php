<?php
/**
 * Statements - delete a statement entered by mistake (only when nothing links to it).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/statement_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/statements/index.php');
}

$statement = find_or_redirect(statement_find(request_id()), 'pages/statements/index.php', 'Statement');
require_csrf('pages/statements/view.php?id=' . $statement['id']);

try {
    db_transaction(function () use ($statement) {
        db_value('SELECT id FROM credit_card_statements WHERE id = :id FOR UPDATE', [':id' => $statement['id']]);
        $usage = statement_usage($statement['id']);
        if ($usage['total'] > 0) {
            throw new DomainException('This statement cannot be deleted because it is linked to ' . usage_text($usage) . '.');
        }
        db_exec('DELETE FROM credit_card_statements WHERE id = :id', [':id' => $statement['id']]);
    });

    log_activity('delete', 'statement', $statement['id'], 'Deleted statement ' . statement_label($statement), $statement);
    flash('success', 'Statement was deleted.');
    redirect('pages/statements/index.php');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect('pages/statements/view.php', ['id' => $statement['id']]);
