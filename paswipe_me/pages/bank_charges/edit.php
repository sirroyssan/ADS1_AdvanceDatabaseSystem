<?php
/**
 * Bank Charges - correct a charge (logged with old/new values).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/bank_charge_functions.php';

$currentUser = require_admin();

$charge = find_or_redirect(bank_charge_find(request_id()), 'pages/bank_charges/index.php', 'Bank charge');
$fields = bank_charge_fields($charge);
$data = form_data_from_row($fields, $charge);
$errors = [];

if (is_post()) {
    require_csrf('pages/bank_charges/edit.php?id=' . $charge['id']);
    [$data, $errors] = bank_charge_validate($fields, $_POST, $charge['id']);

    if (!$errors) {
        try {
            card_usage_apply([$charge['credit_card_id'], $data['credit_card_id']], fn () => db_update('bank_charges', $data, $charge['id']));
            log_activity('update', 'bank_charge', $charge['id'], 'Corrected bank charge', array_intersect_key($charge, $data), $data);
            flash('success', 'Bank charge was updated successfully.');
            statement_move_notice($charge['credit_card_id'], $charge['charge_date'], $data['credit_card_id'] ?? $charge['credit_card_id'], $data['charge_date'] ?? $charge['charge_date']);
            redirect('pages/bank_charges/view.php', ['id' => $charge['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Bank Charge';
$activeNav = 'bank_charges';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Bank Charges' => url('pages/bank_charges/index.php'),
    $charge['charge_type'] => url('pages/bank_charges/view.php', ['id' => $charge['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit bank charge</h2>
        <p class="page-header__subtitle">Corrections are recorded in the activity log.</p>
    </div>
</section>

<?= render_record_form(url('pages/bank_charges/edit.php', ['id' => $charge['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/bank_charges/view.php', ['id' => $charge['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
