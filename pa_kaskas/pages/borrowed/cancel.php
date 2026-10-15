<?php
/**
 * Amounts to Repay - cancel my own reported repayment (POST) while the card
 * owner has not started confirming it. It stays in the history as cancelled.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/pages/borrower_payments/payment_functions.php';

[$currentUser, $layout] = borrowed_require_user();

if (!is_post()) {
    redirect('pages/borrowed/index.php');
}
$itemId = input_string($_POST, 'item');
$back = 'pages/borrowed/view.php?id=' . (is_valid_uuid($itemId) ? $itemId : '');
require_csrf($back);

$id = input_string($_POST, 'id');
$payment = is_valid_uuid($id) ? db_row(
    'SELECT * FROM borrower_payments WHERE id = :id AND borrower_id IN ' . borrowed_my_borrowers_sql(),
    [':id' => $id]
) : null;
if (!$payment) {
    flash('error', 'Repayment not found.');
    redirect('pages/borrowed/index.php');
}

$cancelled = db_transaction(function () use ($payment, $currentUser) {
    $status = db_value('SELECT status FROM borrower_payments WHERE id = :id FOR UPDATE', [':id' => $payment['id']]);
    if (!in_array($status, PORTAL_CANCELLABLE_STATUSES, true)) {
        return false;
    }
    db_update('borrower_payments', ['status' => 'cancelled'], $payment['id']);
    payment_log(['id' => $payment['id'], 'status' => $status], 'cancelled', 'cancelled', 'Cancelled by the borrower', $currentUser['id']);
    notify_lender($payment['borrower_id'], 'payment_cancelled', 'Repayment cancelled: ' . $payment['payment_number'],
        user_display_name($currentUser) . ' cancelled the repayment ' . $payment['payment_number'] . ' of ' . format_money($payment['amount']) . '.', 'borrower_payment', $payment['id']);

    return true;
});

if ($cancelled) {
    log_activity('cancel', 'borrower_payment', $payment['id'], 'Borrower cancelled repayment ' . $payment['payment_number']);
    flash('success', 'Repayment ' . $payment['payment_number'] . ' was cancelled.');
} else {
    flash('error', 'The card owner is already reviewing this repayment, so it can no longer be cancelled.');
}
redirect($back);
