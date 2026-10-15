<?php
/**
 * Card Payments - correct a recorded payment (changes are written to the activity log).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/card_payment_functions.php';

$currentUser = require_admin();

$payment = find_or_redirect(card_payment_find(request_id()), 'pages/card_payments/index.php', 'Card payment');
$fields = card_payment_fields($payment);
$data = form_data_from_row($fields, $payment);
$errors = [];

if (is_post()) {
    require_csrf('pages/card_payments/edit.php?id=' . $payment['id']);
    [$data, $errors] = card_payment_validate($fields, $_POST, $payment['id']);

    if (!$errors) {
        try {
            card_usage_apply([$payment['credit_card_id'], $data['credit_card_id']], fn () => db_update('credit_card_payments', $data, $payment['id']));
            log_activity('update', 'card_payment', $payment['id'], 'Corrected card payment', array_intersect_key($payment, $data), $data);
            flash('success', 'Card payment was updated successfully.');
            statement_move_notice($payment['credit_card_id'], $payment['payment_date'], $data['credit_card_id'] ?? $payment['credit_card_id'], $data['payment_date'] ?? $payment['payment_date']);
            redirect('pages/card_payments/view.php', ['id' => $payment['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Card Payment';
$activeNav = 'card_payments';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Card Payments' => url('pages/card_payments/index.php'),
    format_date($payment['payment_date']) => url('pages/card_payments/view.php', ['id' => $payment['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit card payment</h2>
        <p class="page-header__subtitle">Corrections are recorded in the activity log with the old and new values.</p>
    </div>
</section>

<?= render_record_form(url('pages/card_payments/edit.php', ['id' => $payment['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/card_payments/view.php', ['id' => $payment['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
