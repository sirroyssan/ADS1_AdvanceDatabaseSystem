<?php
/**
 * Amounts to Repay - report a repayment of a borrowed purchase (amount, date,
 * reference). It is saved as reported; the card owner confirms it, and only
 * then is it taken off what is owed. Partial repayments are fine.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/pages/borrower_payments/payment_functions.php';

[$currentUser, $layout] = borrowed_require_user();

$id = request_id();
$item = find_or_redirect(is_valid_uuid($id) ? (borrowed_my_purchases($id)[0] ?? null) : null, 'pages/borrowed/index.php', 'Borrowed purchase');
$l = $item['ledger'];
$open = round($l['outstanding'] - $l['reported'], 2);
if ($open <= 0.004) {
    flash('info', 'Nothing left to report for this purchase' . ($l['reported'] > 0 ? ': your repayments are waiting for the card owner\'s confirmation.' : '.'));
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
    // The same report sent twice (double click, refresh) is saved once.
    if (!$errors && !form_once_use('borrowed_repay', input_string($_POST, 'once'))) {
        flash('info', 'This repayment was already reported.');
        redirect('pages/borrowed/view.php', ['id' => $item['id']]);
    }

    if (!$errors) {
        try {
            if ($l['mode'] === 'obligation') {
                // Older workflow: the repayment is reported for its obligation (applied when the owner approves it).
                $paymentId = new_uuid();
                db_transaction(static function () use ($paymentId, $data, $item, $l, $currentUser) {
                    db_insert('borrower_payments', [
                        'id' => $paymentId, 'payment_number' => generate_reference('PAY', 'borrower_payments', 'payment_number'), 'borrower_id' => $item['borrower_id'],
                        'payment_date' => $data['payment_date'], 'amount' => $data['amount'], 'reference_number' => $data['reference_number'], 'notes' => $data['notes'],
                        'status' => 'submitted', 'allocation_status' => 'unallocated', 'submitted_at' => date('Y-m-d H:i:s'),
                    ]);
                    payment_selections_save($paymentId, [['obligation_id' => $l['obligation']['id'], 'installment_schedule_id' => null, 'amount' => (float) $data['amount']]]);
                    payment_log(['id' => $paymentId, 'status' => null], 'submitted', 'submitted', 'Repayment reported by the borrower', $currentUser['id']);
                });
                notify_lender($item['borrower_id'], 'payment_submitted', 'Repayment to confirm',
                    'A repayment of ' . format_money($data['amount']) . ' was reported for ' . $item['transaction_number'] . '. Please confirm it.', 'borrower_payment', $paymentId);
            } else {
                borrowed_record_repayment($item, $data, $currentUser['id'], false);
            }
            flash('success', 'Repayment of ' . format_money($data['amount']) . ' reported. The card owner confirms it, then it is taken off what you owe.');
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

<?= render_breadcrumb(['Amounts to Repay' => url('pages/borrowed/index.php'), $item['transaction_number'] => url('pages/borrowed/view.php', ['id' => $item['id']]), 'Repay' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Record repayment</h2>
        <p class="page-header__subtitle"><?= e($item['merchant_name'] ?: $item['transaction_number']) ?> · <?= e(format_money($l['outstanding'])) ?> outstanding · repay by <?= e(borrowed_due_text($l)) ?> · to <?= e($item['lender_name']) ?></p>
    </div>
</section>

<?= render_record_form(url('pages/borrowed/repay.php', ['id' => $item['id']]), $fields, $data, $errors, 'Report repayment', url('pages/borrowed/view.php', ['id' => $item['id']]),
    form_once_field('borrowed_repay')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
