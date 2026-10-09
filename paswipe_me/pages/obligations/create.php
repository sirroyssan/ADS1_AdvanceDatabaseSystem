<?php
/**
 * Obligations - create what a borrower owes (optionally linked to a transaction).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/obligation_functions.php';

$currentUser = require_admin();

$fields = obligation_fields(true);
$preset = [];
foreach (['borrower_id', 'transaction_id'] as $key) {
    $value = input_string($_GET, $key);
    if (is_valid_uuid($value)) {
        $preset[$key] = $value;
    }
}
if (isset($preset['transaction_id'])) {
    $txn = db_row('SELECT amount, transaction_number, transaction_date FROM transactions WHERE id = :id', [':id' => $preset['transaction_id']]);
    if ($txn) {
        $preset += ['original_amount' => $txn['amount'], 'description' => 'Card purchase ' . $txn['transaction_number'], 'obligation_date' => $txn['transaction_date']];
    }
}
$data = form_defaults($fields, $preset);
$errors = [];

if (is_post()) {
    require_csrf('pages/obligations/create.php');
    [$data, $errors] = obligation_validate($fields, $_POST, null, 0.0);

    if (!$errors) {
        try {
            $id = new_uuid();
            $data['obligation_number'] ??= generate_reference('OBL', 'borrower_obligations', 'obligation_number');
            db_insert('borrower_obligations', ['id' => $id] + $data + ['total_paid' => '0.00', 'created_by' => $currentUser['id'], 'updated_by' => $currentUser['id']]);
            log_activity('create', 'obligation', $id, 'Created obligation ' . $data['obligation_number'] . ' for ' . format_money($data['total_amount_due']), null, $data);
            // Penalty rules apply from the moment the obligation is processed.
            penalty_assign_on_create($id);
            notify_borrower($data['borrower_id'], 'obligation_created', 'New item: ' . $data['obligation_number'],
                format_money($data['total_amount_due']) . ' was added to your balance' . ($data['due_date'] ? ', due ' . format_date($data['due_date']) : '') . '.', 'obligation', $id);
            flash('success', 'Obligation ' . $data['obligation_number'] . ' was created.');
            redirect('pages/obligations/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Add Obligation';
$activeNav = 'obligations';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Obligations' => url('pages/obligations/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add obligation</h2>
        <p class="page-header__subtitle">Total due = original amount + agreed interest + agreed fees. Payments are applied from Borrower Payments.</p>
    </div>
</section>

<?= render_record_form(url('pages/obligations/create.php'), $fields, $data, $errors, 'Save obligation', url('pages/obligations/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
