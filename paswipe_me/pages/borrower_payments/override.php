<?php
/**
 * Borrower Payments - override the amount (POST). OWNER ONLY, any status
 * except cancelled, reason required. Payments are never deleted.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/payment_functions.php';

$currentUser = require_role(['owner']);

if (!is_post()) {
    redirect('pages/borrower_payments/index.php');
}

$payment = find_or_redirect(payment_find(request_id()), 'pages/borrower_payments/index.php', 'Payment');
$back = 'pages/borrower_payments/view.php?id=' . $payment['id'];
require_csrf($back);

$error = payment_override_amount($payment, input_string($_POST, 'new_amount'), input_string($_POST, 'reason'), $currentUser);
if ($error !== null) {
    flash('error', 'Amount not changed: ' . $error);
    redirect($back . '#override-amount');
}

flash('success', 'The payment amount was overridden. The change and your reason were recorded.');
redirect($back);
