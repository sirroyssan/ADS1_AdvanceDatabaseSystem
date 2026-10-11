<?php
/**
 * Borrower portal - cancel my own payment (POST) while the owner has not started reviewing it.
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once APP_ROOT . '/pages/borrower_payments/payment_functions.php';

[$currentUser, $borrower] = require_borrower();

if (!is_post()) {
    redirect('portal/payments.php');
}

$payment = portal_find('SELECT * FROM borrower_payments WHERE id = :id AND borrower_id = :b', input_string($_POST, 'id'), $borrower['id'], 'portal/payments.php', 'Payment');
require_csrf('portal/payment.php?id=' . $payment['id']);

try {
    $cancelled = db_transaction(function () use ($payment, $currentUser, $borrower) {
        // Re-read under lock: the owner may have started the review meanwhile.
        $status = db_value('SELECT status FROM borrower_payments WHERE id = :id FOR UPDATE', [':id' => $payment['id']]);
        if (!in_array($status, PORTAL_CANCELLABLE_STATUSES, true)) {
            return false;
        }
        db_update('borrower_payments', ['status' => 'cancelled'], $payment['id']);
        payment_log(['id' => $payment['id'], 'status' => $status], 'cancelled', 'cancelled', 'Cancelled by the borrower in the portal', $currentUser['id']);
        notify_lender($borrower['id'], 'payment_cancelled', 'Payment cancelled: ' . $payment['payment_number'],
            borrower_name($borrower) . ' cancelled payment ' . $payment['payment_number'] . ' of ' . format_money($payment['amount']) . '.',
            'borrower_payment', $payment['id']);
        return true;
    });

    if ($cancelled) {
        log_activity('cancel', 'borrower_payment', $payment['id'], 'Borrower cancelled payment ' . $payment['payment_number']);
        flash('success', 'Payment ' . $payment['payment_number'] . ' was cancelled.');
    } else {
        flash('error', 'This payment is already being reviewed, so it can no longer be cancelled. Contact the owner.');
    }
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect('portal/payment.php', ['id' => $payment['id']]);
