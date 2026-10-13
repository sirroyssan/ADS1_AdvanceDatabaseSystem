<?php
/**
 * Transactions - delete a DRAFT that has no items, plan, obligations or agreements.
 * Every other transaction is kept and cancelled instead.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/transaction_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/transactions/index.php');
}

$txn = find_or_redirect(transaction_find(request_id()), 'pages/transactions/index.php', 'Transaction');
if ($advanceOf = cash_share_advance($txn)) {
    flash('info', 'This is cash advance ' . $advanceOf['reference_number'] . ' given to a borrower. Change it on the cash advance.');
    redirect('pages/cash_advances/view.php', ['id' => $advanceOf['id']]);
}
require_csrf('pages/transactions/view.php?id=' . $txn['id']);

try {
    card_usage_apply([$txn['credit_card_id']], function () use ($txn) {
        $status = db_value('SELECT status FROM transactions WHERE id = :id FOR UPDATE', [':id' => $txn['id']]);
        if ($status !== 'draft') {
            throw new DomainException('Only draft transactions can be deleted. Cancel this transaction instead.');
        }
        $usage = transaction_usage($txn['id']);
        if ($usage['total'] > 0) {
            throw new DomainException('This draft cannot be deleted because it has ' . usage_text($usage) . '.');
        }
        db_exec('DELETE FROM transaction_attachments WHERE transaction_id = :id', [':id' => $txn['id']]);
        db_exec('DELETE FROM transactions WHERE id = :id', [':id' => $txn['id']]);
    });

    log_activity('delete', 'transaction', $txn['id'], 'Deleted draft transaction ' . $txn['transaction_number'], $txn);
    flash('success', 'Draft ' . $txn['transaction_number'] . ' was deleted.');
    redirect('pages/transactions/index.php');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect('pages/transactions/view.php', ['id' => $txn['id']]);
