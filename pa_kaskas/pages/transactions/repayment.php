<?php
/**
 * Transactions - repayments of a borrowed purchase (POST), by the card owner:
 *   confirm - a repayment the borrower reported (then it counts);
 *   reject  - a reported repayment that did not arrive (kept in the history).
 * There is no "record" here any more: repayments come from the borrower's
 * portal, and money handed over in person goes through Receive Payment.
 * The transaction is found through the owner-scoped finder: only the card
 * owner can do this (includes/borrowed.php).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/transaction_functions.php';
require_once APP_ROOT . '/pages/borrower_payments/payment_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/transactions/index.php');
}
$txn = find_or_redirect(transaction_find(request_id()), 'pages/transactions/index.php', 'Transaction');
$back = 'pages/transactions/view.php?id=' . $txn['id'];
require_csrf($back);

$purchase = borrowed_owner_purchases(null, $txn['id'])[0] ?? null;
if (!$purchase || $purchase['ledger']['mode'] !== 'transaction') {
    flash('error', $purchase ? 'This purchase is tracked as an obligation; post its payments on Borrower Payments.' : 'This transaction has no borrower.');
    redirect($back);
}
$ledger = $purchase['ledger'];
$action = input_string($_POST, 'action');

try {
    if (in_array($action, ['confirm', 'reject'], true)) {
        $payment = db_row('SELECT * FROM borrower_payments WHERE id = :id AND transaction_id = :t', [':id' => input_string($_POST, 'payment_id'), ':t' => $txn['id']]);
        if (!$payment || !in_array($payment['status'], BORROWED_REPORTED_STATUSES, true)) {
            flash('error', 'Repayment not found, or it was already decided.');
            redirect($back);
        }
        $reason = mb_substr(input_string($_POST, 'reason'), 0, 1000);
        if ($action === 'reject' && $reason === '') {
            flash('error', 'Give a reason for rejecting the repayment (the borrower sees it).');
            redirect($back);
        }
        if ($action === 'confirm' && (float) $payment['amount'] > $ledger['outstanding'] + 0.004) {
            flash('error', 'This repayment (' . format_money($payment['amount']) . ') is more than what is outstanding (' . format_money($ledger['outstanding']) . '). Reject it and ask the borrower to report the right amount.');
            redirect($back);
        }
        $decision = $action === 'confirm' ? 'approved' : 'rejected';
        db_transaction(static function () use ($payment, $decision, $reason, $currentUser) {
            $status = db_value('SELECT status FROM borrower_payments WHERE id = :id FOR UPDATE', [':id' => $payment['id']]);
            if ($status !== $payment['status']) {
                throw new DomainException('This repayment was changed meanwhile. Please check it again.');
            }
            payment_record_decision($payment, $decision, $reason === '' ? null : $reason, [
                'verified_amount' => $decision === 'approved' ? $payment['amount'] : null, 'reference_verified' => 0, 'proof_verified' => 0, 'payment_date_verified' => 0,
            ], $currentUser['id']);
            borrowed_after_decision($payment, $decision);
        });
        notify_borrower($txn['borrower_id'], 'payment_' . $decision, 'Repayment ' . ($decision === 'approved' ? 'confirmed' : 'not accepted') . ': ' . format_money($payment['amount']),
            ($decision === 'approved' ? 'The card owner confirmed your repayment for ' : 'The card owner did not accept your repayment for ') . $txn['transaction_number']
            . ($reason !== '' ? '. Reason: ' . $reason : '.'), 'borrowed_purchase', $txn['id']);
        log_activity($action . '_repayment', 'transaction', $txn['id'], ucfirst($action) . 'ed repayment ' . $payment['payment_number'] . ' of ' . format_money($payment['amount']));
        flash('success', 'Repayment ' . $payment['payment_number'] . ($decision === 'approved' ? ' confirmed.' : ' rejected.'));
    } else {
        flash('error', 'Unknown action.');
    }
} catch (DomainException $e) {
    flash('error', $e->getMessage());
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect($back);
