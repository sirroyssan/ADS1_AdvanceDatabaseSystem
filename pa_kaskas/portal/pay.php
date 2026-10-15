<?php
/**
 * Borrower portal - submit a payment (or fix one the owner sent back).
 * The form and its saving are shared with the card purchase "Repay" button
 * (includes/payment_form.php), so every repayment is recorded the same way:
 *   - obligations: one payment with the borrower's choices, applied when the owner approves it;
 *   - card purchases made on the owner's card: a repayment of that purchase, confirmed by the owner.
 * ?id=...: edit the borrower's own payment while it is submitted or needs correction.
 * ?purchase=...: open with that card purchase's amount due this period ticked.
 * Proof of payment is optional for family members (relationship_is_family) and required for everyone else.
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once APP_ROOT . '/includes/payment_form.php';

[$currentUser, $borrower] = require_borrower();
$bid = $borrower['id'];

$payment = null;
$editId = input_string($_GET, 'id') ?: input_string($_POST, 'id');
if ($editId !== '') {
    $payment = portal_find('SELECT * FROM borrower_payments WHERE id = :id AND borrower_id = :b', $editId, $bid, 'portal/payments.php', 'Payment');
    if (!in_array($payment['status'], PAYMENT_EDITABLE_STATUSES, true)) {
        flash('error', 'A payment that is ' . humanize($payment['status']) . ' can no longer be changed.');
        redirect('portal/payment.php', ['id' => $payment['id']]);
    }
}

// What can be paid: a payment being fixed keeps its kind; a new one lists obligations and card purchases.
if ($payment && $payment['transaction_id']) {
    $groups = payment_purchase_groups($bid, $payment['transaction_id'], (float) $payment['amount']);
} elseif ($payment) {
    $groups = payment_obligation_groups($bid);
} else {
    $groups = array_merge(payment_obligation_groups($bid), payment_purchase_groups($bid));
}
$purchase = input_string($_GET, 'purchase');
$preselect = [];
foreach ($groups as $g) {
    if ($g['kind'] === 'purchase' && $g['id'] === $purchase) {
        $preselect = $g['suggest'] ?: array_column(array_slice($g['rows'], 0, 1), 'key');
    }
}

$action = url('portal/pay.php', array_filter(['id' => $payment['id'] ?? null, 'purchase' => $purchase ?: null]));
$state = payment_form_handle([
    'borrower' => $borrower, 'user' => $currentUser, 'payment' => $payment, 'groups' => $groups, 'preselect' => $preselect,
    'csrf_back' => $payment ? 'portal/pay.php?id=' . $payment['id'] : 'portal/pay.php',
]);
if (isset($state['saved'])) {
    if ($state['message']) {
        flash('success', $state['message']);
    }
    redirect($state['saved'] ? 'portal/payment.php' : 'portal/payments.php', $state['saved'] ? ['id' => $state['saved'][0]] : []);
}

$pageTitle = $payment ? 'Fix Payment' : 'Submit Payment';
$pageScripts = ['assets/js/portal-pay.js'];
$activeNav = $payment ? 'portal_payments' : 'portal_pay';
$layout = 'portal';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb($payment
    ? ['My Payments' => url('portal/payments.php'), $payment['payment_number'] => url('portal/payment.php', ['id' => $payment['id']]), 'Fix' => null]
    : ['Submit Payment' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= $payment ? 'Fix payment ' . e($payment['payment_number']) : 'Submit a payment' ?></h2>
        <p class="page-header__subtitle">Pay first using one of the owner's accounts, then record it here with the reference number and proof.</p>
    </div>
</section>

<?= render_payment_form($state, $action, $payment ? url('portal/payment.php', ['id' => $payment['id']]) : url('portal/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
