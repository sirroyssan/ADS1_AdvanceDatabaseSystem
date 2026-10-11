<?php
/**
 * Amounts to Repay - record a repayment to the card owner. It is saved as a
 * borrower payment for this purchase (status "submitted"); the card owner
 * confirms it on Borrower Payments, and only then is it taken off what is
 * owed. Partial repayments are fine.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/pages/borrower_payments/payment_functions.php';

$currentUser = require_admin();

$id = request_id();
$item = is_valid_uuid($id) ? (borrowed_items($id)[0] ?? null) : null;
$item = find_or_redirect($item, 'pages/borrowed/index.php', 'Borrowed purchase');
$open = round((float) $item['outstanding_amount'] - (float) $item['pending'], 2);
if ($item['repay_status'] === 'paid' || $open <= 0.004) {
    flash('info', 'Nothing left to record for this purchase' . ((float) $item['pending'] > 0 ? ': your repayments are waiting for the card owner\'s confirmation.' : '.'));
    redirect('pages/borrowed/view.php', ['id' => $item['id']]);
}

$fields = [
    'amount'           => ['label' => 'Amount repaid', 'type' => 'decimal', 'required' => true, 'min_exclusive' => 0,
                           'help' => 'Up to ' . format_money($open) . '. A partial repayment is fine.'],
    'payment_date'     => ['label' => 'Date you paid', 'type' => 'date', 'required' => true, 'initial' => date('Y-m-d')],
    'reference_number' => ['label' => 'Reference number', 'type' => 'text', 'max' => 150, 'help' => 'Optional: GCash / bank reference, or "cash".'],
    'notes'            => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 2],
];
$data = form_defaults($fields, ['amount' => number_format($open, 2, '.', '')]);
$errors = [];

if (is_post()) {
    require_csrf('pages/borrowed/repay.php?id=' . $item['id']);
    [$data, $errors] = validate_fields($fields, $_POST);
    if (!isset($errors['amount']) && (float) $data['amount'] > $open + 0.004) {
        $errors['amount'] = 'Only ' . format_money($open) . ' is left to repay on this purchase.';
    }
    if (!isset($errors['payment_date']) && $data['payment_date'] > date('Y-m-d')) {
        $errors['payment_date'] = 'The payment date cannot be in the future.';
    }

    if (!$errors) {
        try {
            $paymentId = new_uuid();
            $number = generate_reference('PAY', 'borrower_payments', 'payment_number');
            db_transaction(function () use ($paymentId, $number, $data, $item, $currentUser) {
                db_insert('borrower_payments', [
                    'id' => $paymentId, 'payment_number' => $number, 'borrower_id' => $item['borrower_id'],
                    'payment_date' => $data['payment_date'], 'amount' => $data['amount'], 'reference_number' => $data['reference_number'],
                    'notes' => $data['notes'], 'status' => 'submitted', 'allocation_status' => 'unallocated', 'submitted_at' => date('Y-m-d H:i:s'),
                ]);
                // What the repayment is for: applied to this purchase when the owner confirms it.
                payment_selections_save($paymentId, [['obligation_id' => $item['id'], 'installment_schedule_id' => null, 'amount' => (float) $data['amount']]]);
                payment_log(['id' => $paymentId, 'status' => null], 'submitted', 'submitted', 'Repayment recorded by the borrower (Amounts to Repay)', $currentUser['id']);
                notify_lender($item['borrower_id'], 'payment_submitted', 'Repayment to confirm: ' . $number,
                    user_display_name($currentUser) . ' recorded a repayment of ' . format_money($data['amount']) . ' (paid ' . format_date($data['payment_date']) . ') for '
                    . ($item['merchant_name'] ?: $item['obligation_number']) . '. Please confirm it.', 'borrower_payment', $paymentId);
            });
            log_activity('create', 'borrower_payment', $paymentId, 'Recorded repayment ' . $number . ' of ' . format_money($data['amount']) . ' for ' . $item['obligation_number']);
            flash('success', 'Repayment of ' . format_money($data['amount']) . ' recorded. The card owner confirms it, then it is taken off what you owe.');
            redirect('pages/borrowed/view.php', ['id' => $item['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Record Repayment';
$activeNav = 'borrowed';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Amounts to Repay' => url('pages/borrowed/index.php'), $item['obligation_number'] => url('pages/borrowed/view.php', ['id' => $item['id']]), 'Repay' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Record repayment</h2>
        <p class="page-header__subtitle"><?= e($item['merchant_name'] ?: $item['obligation_number']) ?> · <?= e(format_money($item['outstanding_amount'])) ?> remaining<?= $item['due_date'] ? ', due ' . e(format_date($item['due_date'])) : '' ?> · to <?= e($item['lender_name']) ?></p>
    </div>
</section>

<?= render_record_form(url('pages/borrowed/repay.php', ['id' => $item['id']]), $fields, $data, $errors, 'Record repayment', url('pages/borrowed/view.php', ['id' => $item['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
