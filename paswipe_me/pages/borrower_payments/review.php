<?php
/**
 * Borrower Payments - review decision (POST): under_review, approved, rejected,
 * correction_required or cancelled. Writes payment_verifications and
 * payment_verification_logs in the same transaction as the status change.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/payment_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/borrower_payments/index.php');
}

$payment = find_or_redirect(payment_find(request_id()), 'pages/borrower_payments/index.php', 'Payment');
$back = 'pages/borrower_payments/view.php?id=' . $payment['id'];
require_csrf($back);

$decision = input_string($_POST, 'decision');
$reason = mb_substr(input_string($_POST, 'reason'), 0, 5000);
$allowed = PAYMENT_TRANSITIONS[$payment['status']] ?? [];

if (!in_array($decision, $allowed, true)) {
    flash('error', 'A ' . humanize($payment['status']) . ' payment cannot be changed to ' . humanize($decision) . '.');
    redirect($back);
}
if (in_array($decision, ['rejected', 'correction_required', 'cancelled'], true) && $reason === '') {
    flash('error', 'Please give a reason for this decision.');
    redirect($back);
}

$verifiedAmount = str_replace([',', ' ', '₱'], '', input_string($_POST, 'verified_amount'));
if ($verifiedAmount !== '' && !preg_match('/^\d+(\.\d{1,2})?$/', $verifiedAmount)) {
    flash('error', 'Verified amount must be a number.');
    redirect($back);
}
if ($decision === 'approved' && $verifiedAmount !== '' && abs((float) $verifiedAmount - (float) $payment['amount']) > 0.004) {
    flash('error', 'The verified amount differs from the payment amount. Request a correction instead of approving.');
    redirect($back);
}

$checks = [
    'verified_amount'       => $verifiedAmount === '' ? null : $verifiedAmount,
    'reference_verified'    => input_string($_POST, 'reference_verified') === '1' ? 1 : 0,
    'proof_verified'        => input_string($_POST, 'proof_verified') === '1' ? 1 : 0,
    'payment_date_verified' => input_string($_POST, 'payment_date_verified') === '1' ? 1 : 0,
];

try {
    db_transaction(function () use ($payment, $decision, $reason, $checks, $currentUser) {
        $status = db_value('SELECT status FROM borrower_payments WHERE id = :id FOR UPDATE', [':id' => $payment['id']]);
        if ($status !== $payment['status']) {
            throw new DomainException('This payment was changed by someone else. Please review it again.');
        }
        payment_record_decision($payment, $decision, $reason === '' ? null : $reason, $checks, $currentUser['id']);
    });

    log_activity('review', 'borrower_payment', $payment['id'], $payment['payment_number'] . ' ' . $decision . ($reason ? ': ' . $reason : ''),
        ['status' => $payment['status']], ['status' => $decision] + $checks);
    flash('success', 'Payment ' . $payment['payment_number'] . ' is now ' . humanize($decision) . '.'
        . ($decision === 'approved' ? ' You can now allocate it to obligations.' : ''));
} catch (DomainException $e) {
    flash('error', $e->getMessage());
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect($back);
