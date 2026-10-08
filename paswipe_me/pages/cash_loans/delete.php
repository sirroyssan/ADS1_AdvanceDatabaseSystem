<?php
/**
 * Cash Loans - delete an offer that was never taken (offered/rejected/cancelled,
 * no allocations, no payments on the schedule).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/cash_loan_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/cash_loans/index.php');
}

$loan = find_or_redirect(cash_loan_find(request_id()), 'pages/cash_loans/index.php', 'Cash loan');
require_csrf('pages/cash_loans/view.php?id=' . $loan['id']);

try {
    db_transaction(function () use ($loan) {
        $status = db_value('SELECT status FROM cash_loans WHERE id = :id FOR UPDATE', [':id' => $loan['id']]);
        $blocked = (int) db_value(
            'SELECT (SELECT COUNT(*) FROM cash_loan_allocations WHERE cash_loan_id = :a)
                  + (SELECT COUNT(*) FROM cash_loan_schedules WHERE cash_loan_id = :b AND amount_paid > 0)',
            [':a' => $loan['id'], ':b' => $loan['id']]
        );
        if (!in_array($status, ['offered', 'rejected', 'cancelled'], true) || $blocked > 0) {
            throw new DomainException('This cash loan has been taken or allocated, so it must be kept. Set its status to Cancelled instead.');
        }
        db_exec('DELETE FROM cash_loan_schedules WHERE cash_loan_id = :id', [':id' => $loan['id']]);
        db_exec('DELETE FROM cash_loans WHERE id = :id', [':id' => $loan['id']]);
    });

    log_activity('delete', 'cash_loan', $loan['id'], 'Deleted cash loan offer ' . $loan['loan_reference'], $loan);
    flash('success', 'Cash loan ' . $loan['loan_reference'] . ' was deleted.');
    redirect('pages/cash_loans/index.php');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect('pages/cash_loans/view.php', ['id' => $loan['id']]);
