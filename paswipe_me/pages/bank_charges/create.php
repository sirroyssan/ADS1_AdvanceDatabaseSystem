<?php
/**
 * Bank Charges - create.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/bank_charge_functions.php';

$currentUser = require_admin();

$fields = bank_charge_fields();
$preset = input_string($_GET, 'credit_card_id');
$data = form_defaults($fields, is_valid_uuid($preset) ? ['credit_card_id' => $preset] : []);
$errors = [];

if (is_post()) {
    require_csrf('pages/bank_charges/create.php');
    [$data, $errors] = bank_charge_validate($fields, $_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            card_usage_apply([$data['credit_card_id']], fn () => db_insert('bank_charges', ['id' => $id] + $data));
            log_activity('create', 'bank_charge', $id, 'Recorded bank charge "' . $data['charge_type'] . '" of ' . format_money($data['amount']), null, $data);
            flash('success', 'Bank charge was recorded.');
            redirect('pages/bank_charges/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Add Bank Charge';
$activeNav = 'bank_charges';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Bank Charges' => url('pages/bank_charges/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add bank charge</h2>
        <p class="page-header__subtitle">Record a fee or charge from the card issuer.</p>
    </div>
</section>

<?= render_record_form(url('pages/bank_charges/create.php'), $fields, $data, $errors, 'Save charge', url('pages/bank_charges/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
