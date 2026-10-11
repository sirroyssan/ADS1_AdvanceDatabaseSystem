<?php
/**
 * Bank Charges - create.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/bank_charge_functions.php';

$currentUser = require_admin();

$fields = bank_charge_fields();
$preset = input_string($_GET, 'credit_card_id');
$data = form_defaults($fields, owns('credit_cards', $preset) ? ['credit_card_id' => $preset] : []);
// Opened from a statement ("Add missing transaction"): start from the
// statement's date and report afterwards which statement the record went to.
$reconcile = preg_match('/^\d{4}-\d{2}-\d{2}$/', input_string($_GET, 'period')) ? input_string($_GET, 'period') : null;   // cutoff of the statement period being viewed
if (!is_post() && preg_match('/^\d{4}-\d{2}-\d{2}$/', input_string($_GET, 'charge_date'))) {
    $data['charge_date'] = input_string($_GET, 'charge_date');
}
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
            statement_placement_notice($data['credit_card_id'], $data['charge_date'], $reconcile);
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

<?= render_record_form(url('pages/bank_charges/create.php', array_filter(['period' => $reconcile])), $fields, $data, $errors, 'Save charge', url('pages/bank_charges/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
