<?php
/**
 * Borrower Payments - override the amount (modal form). OWNER ONLY, any status
 * except cancelled, reason required. Payments are never deleted: a lower amount
 * takes the difference back from the newest allocations and restores those balances.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/payment_functions.php';

$currentUser = require_admin();

// No editing of a payment's amount by the owner or admin side.
flash('error', 'Payment amounts are not edited by the owner or admin. Reject the payment with the reason (or delete a cash receipt line) instead.');
redirect('pages/borrower_payments/view.php', ['id' => request_id()]);

$payment = find_or_redirect(payment_find(request_id()), 'pages/borrower_payments/index.php', 'Payment');
$back = 'pages/borrower_payments/view.php?id=' . $payment['id'];
if ($payment['status'] === 'cancelled') {
    flash('error', 'A void payment cannot be changed.');
    redirect($back);
}

$fields = [
    'new_amount' => ['label' => 'New amount', 'type' => 'decimal', 'required' => true, 'min_exclusive' => 0,
                     'help' => 'Now ' . format_money($payment['amount']) . ' · ' . format_money($payment['allocated']) . ' already applied.'],
    'reason'     => ['label' => 'Reason', 'type' => 'textarea', 'rows' => 3, 'required' => true, 'max' => 1000,
                     'placeholder' => 'e.g. GCash receipt shows ₱4,500, not ₱5,000',
                     'help' => 'At least ' . PENALTY_REASON_MIN . ' characters. Saved in the payment history and the activity log; the borrower is notified.'],
];
$data = ['new_amount' => number_format((float) $payment['amount'], 2, '.', ''), 'reason' => ''];
$errors = [];

if (is_post()) {
    require_csrf('pages/borrower_payments/override.php?id=' . $payment['id']);
    $data = ['new_amount' => input_string($_POST, 'new_amount'), 'reason' => input_string($_POST, 'reason')];
    $error = payment_override_amount($payment, $data['new_amount'], $data['reason'], $currentUser);
    if ($error === null) {
        require_once APP_ROOT . '/includes/receipts.php';
        receipt_note_change($payment['id'], 'Payment ' . $payment['payment_number'] . ' amount changed from ' . format_money($payment['amount'])
            . ' to ' . format_money($data['new_amount']) . ' by the owner: ' . trim($data['reason']), $currentUser['id']);
        flash('success', 'The payment amount was overridden. The change and your reason were recorded.');
        redirect($back);
    }
    $errors[str_contains($error, 'reason') ? 'reason' : (str_contains($error, 'amount') ? 'new_amount' : '_form')] = $error;
}

$pageTitle = 'Override Amount';
$activeNav = 'borrower_payments';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Borrower Payments' => url('pages/borrower_payments/index.php'), $payment['payment_number'] => url($back), 'Override amount' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Override amount · <?= e($payment['payment_number']) ?></h2>
        <p class="page-header__subtitle">Owner only. Changes the amount even after approval. If the new amount is lower than what is already applied, the difference is taken back from the newest allocations (principal, then interest, then penalties) and those balances are restored.</p>
    </div>
</section>

<?= render_record_form(url('pages/borrower_payments/override.php', ['id' => $payment['id']]), $fields, $data, $errors, 'Override amount', url($back)) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
