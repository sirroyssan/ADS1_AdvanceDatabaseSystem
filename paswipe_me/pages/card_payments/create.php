<?php
/**
 * Card Payments - record a payment to the bank.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/card_payment_functions.php';

$currentUser = require_admin();

$fields = card_payment_fields();
$preset = [];
foreach (['credit_card_id', 'statement_id'] as $key) {
    $value = input_string($_GET, $key);
    if (is_valid_uuid($value)) {
        $preset[$key] = $value;
    }
}
$data = form_defaults($fields, $preset);
$errors = [];

if (is_post()) {
    require_csrf('pages/card_payments/create.php');
    [$data, $errors] = card_payment_validate($fields, $_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            db_insert('credit_card_payments', ['id' => $id] + $data);
            log_activity('create', 'card_payment', $id, 'Recorded card payment of ' . format_money($data['amount']), null, $data);
            flash('success', 'Card payment of ' . format_money($data['amount']) . ' was recorded.');
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

<?= render_record_form(url('pages/card_payments/create.php'), $fields, $data, $errors, 'Save payment', url('pages/card_payments/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
