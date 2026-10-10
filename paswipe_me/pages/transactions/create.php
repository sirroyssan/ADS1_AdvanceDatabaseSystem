<?php
/**
 * Transactions - record a card swipe.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/transaction_functions.php';

$currentUser = require_admin();

$fields = transaction_fields();
$preset = [];
foreach (['credit_card_id', 'borrower_id'] as $key) {
    $value = input_string($_GET, $key);
    if (is_valid_uuid($value)) {
        $preset[$key] = $value;
    }
}
$data = form_defaults($fields, $preset);
// Opened from a statement's reconciliation ("Add missing transaction"): start from the
// statement's date and report afterwards which statement the record went to.
$reconcile = is_valid_uuid(input_string($_GET, 'reconcile')) ? input_string($_GET, 'reconcile') : null;
if (!is_post() && preg_match('/^\d{4}-\d{2}-\d{2}$/', input_string($_GET, 'transaction_date'))) {
    $data['transaction_date'] = input_string($_GET, 'transaction_date');
}
$errors = [];

if (is_post()) {
    require_csrf('pages/transactions/create.php');
    [$data, $errors] = transaction_validate($fields, $_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            $data['transaction_number'] ??= generate_reference('TXN', 'transactions', 'transaction_number');
            card_usage_apply([$data['credit_card_id']], fn () => db_insert('transactions', ['id' => $id] + $data + ['created_by' => $currentUser['id'], 'updated_by' => $currentUser['id']]));
            log_activity('create', 'transaction', $id, 'Recorded transaction ' . $data['transaction_number'] . ' for ' . format_money($data['amount']), null, $data);
            flash('success', 'Transaction ' . $data['transaction_number'] . ' was recorded. You can now add the purchased items.');
            statement_placement_notice($data['credit_card_id'], $data['transaction_date'], $reconcile);
            redirect('pages/transactions/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Add Transaction';
$activeNav = 'transactions';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Transactions' => url('pages/transactions/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add transaction</h2>
        <p class="page-header__subtitle">Products, receipts and installment plans are added from the transaction page after saving.</p>
    </div>
</section>

<?= render_record_form(url('pages/transactions/create.php', array_filter(['reconcile' => $reconcile])), $fields, $data, $errors, 'Save transaction', url('pages/transactions/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
