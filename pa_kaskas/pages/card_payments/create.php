<?php
/**
 * Card Payments - record a payment to the bank.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/card_payment_functions.php';

$currentUser = require_admin();

$fields = card_payment_fields();
$preset = [];
foreach (['credit_card_id' => 'credit_cards', 'statement_id' => 'credit_card_statements'] as $key => $table) {
    $value = input_string($_GET, $key);
    if (owns($table, $value)) {
        $preset[$key] = $value;
    }
}
$data = form_defaults($fields, $preset);
// Opened from a statement ("Add missing transaction"): start from the
// statement's date and report afterwards which statement the record went to.
$reconcile = preg_match('/^\d{4}-\d{2}-\d{2}$/', input_string($_GET, 'period')) ? input_string($_GET, 'period') : null;   // cutoff of the statement period being viewed
if (!is_post() && preg_match('/^\d{4}-\d{2}-\d{2}$/', input_string($_GET, 'payment_date'))) {
    $data['payment_date'] = input_string($_GET, 'payment_date');
}
$errors = [];

if (is_post()) {
    require_csrf('pages/card_payments/create.php');
    [$data, $errors] = card_payment_validate($fields, $_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            card_usage_apply([$data['credit_card_id']], fn () => db_insert('credit_card_payments', ['id' => $id] + $data));
            log_activity('create', 'card_payment', $id, 'Recorded card payment of ' . format_money($data['amount']), null, $data);
            flash('success', 'Card payment of ' . format_money($data['amount']) . ' was recorded.');
            statement_placement_notice($data['credit_card_id'], $data['payment_date'], $reconcile);
            redirect('pages/card_payments/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Record Card Payment';
$activeNav = 'card_payments';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Card Payments' => url('pages/card_payments/index.php'), 'Record' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Record card payment</h2>
        <p class="page-header__subtitle">The statement list shows only statements of the selected card.</p>
    </div>
</section>

<?= render_record_form(url('pages/card_payments/create.php', array_filter(['period' => $reconcile])), $fields, $data, $errors, 'Save payment', url('pages/card_payments/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
