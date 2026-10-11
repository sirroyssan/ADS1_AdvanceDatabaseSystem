<?php
/**
 * Borrower Payments - edit while submitted or waiting for correction.
 * Saving a corrected payment sends it back to "submitted" for review.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/payment_functions.php';

$currentUser = require_admin();

$payment = find_or_redirect(payment_find(request_id()), 'pages/borrower_payments/index.php', 'Payment');

if (!in_array($payment['status'], PAYMENT_EDITABLE_STATUSES, true)) {
    flash('error', 'A payment that is ' . humanize($payment['status']) . ' can no longer be edited.');
    redirect('pages/borrower_payments/view.php', ['id' => $payment['id']]);
}

$fields = payment_fields($payment);
$fields['borrower_id']['readonly'] = true;
if (!user_is_admin($currentUser)) {
    // Only the owner may change an amount (see override.php).
    $fields['amount']['readonly'] = true;
    $fields['amount']['help'] = 'Only the owner can change the amount.';
}
$data = form_data_from_row($fields, $payment);
$errors = [];

if (is_post()) {
    require_csrf('pages/borrower_payments/edit.php?id=' . $payment['id']);
    [$data, $errors] = payment_validate($fields, $_POST, $payment['id'], $payment['borrower_id']);

    if (!$errors) {
        try {
            db_transaction(function () use ($payment, $data, $currentUser) {
                $update = $data;
                if ($payment['status'] === 'correction_required') {
                    $update['status'] = 'submitted';
                    payment_log($payment, 'submitted', 'corrected', 'Payment details corrected', $currentUser['id']);
                }
                db_update('borrower_payments', $update, $payment['id']);
            });
            log_activity('update', 'borrower_payment', $payment['id'], 'Updated payment ' . $payment['payment_number'], array_intersect_key($payment, $data), $data);
            flash('success', 'Payment ' . $payment['payment_number'] . ' was updated.');
            redirect('pages/borrower_payments/view.php', ['id' => $payment['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Borrower Payment';
$activeNav = 'borrower_payments';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Borrower Payments' => url('pages/borrower_payments/index.php'),
    $payment['payment_number'] => url('pages/borrower_payments/view.php', ['id' => $payment['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit payment <?= e($payment['payment_number']) ?></h2>
        <p class="page-header__subtitle"><?= $payment['status'] === 'correction_required'
            ? 'Correction requested: ' . e($payment['correction_reason'] ?? '') . ' — saving sends it back for review.'
            : 'The payment has not been reviewed yet.' ?></p>
    </div>
</section>

<?= render_record_form(url('pages/borrower_payments/edit.php', ['id' => $payment['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/borrower_payments/view.php', ['id' => $payment['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
