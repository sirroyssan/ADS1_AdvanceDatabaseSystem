<?php
/**
 * Transactions - edit (not allowed once cancelled or refunded).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/transaction_functions.php';

$currentUser = require_admin();

$txn = find_or_redirect(transaction_find(request_id()), 'pages/transactions/index.php', 'Transaction');

if (in_array($txn['status'], TXN_LOCKED_STATUSES, true)) {
    flash('error', 'A ' . $txn['status'] . ' transaction can no longer be edited.');
    redirect('pages/transactions/view.php', ['id' => $txn['id']]);
}

$fields = transaction_fields($txn);
$fields['transaction_number']['required'] = true;
unset($fields['transaction_number']['help']);
$data = form_data_from_row($fields, $txn);
$errors = [];

if (is_post()) {
    require_csrf('pages/transactions/edit.php?id=' . $txn['id']);
    [$data, $errors] = transaction_validate($fields, $_POST, $txn['id']);

    if (!$errors && $data['status'] === 'cancelled' && (int) db_value(
        "SELECT COUNT(*) FROM borrower_obligations WHERE transaction_id = :id AND status <> 'cancelled'", [':id' => $txn['id']]
    ) > 0) {
        $errors['status'] = 'Cancel the borrower obligations of this transaction first.';
    }

    if (!$errors) {
        try {
            card_usage_apply([$txn['credit_card_id'], $data['credit_card_id'] ?? null], fn () => db_update('transactions', $data + ['updated_by' => $currentUser['id']], $txn['id']));
            log_activity('update', 'transaction', $txn['id'], 'Updated transaction ' . $data['transaction_number'], array_intersect_key($txn, $data), $data);
            flash('success', 'Transaction ' . $data['transaction_number'] . ' was updated successfully.');
            redirect('pages/transactions/view.php', ['id' => $txn['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Transaction';
$activeNav = 'transactions';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Transactions' => url('pages/transactions/index.php'),
    $txn['transaction_number'] => url('pages/transactions/view.php', ['id' => $txn['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit transaction</h2>
        <p class="page-header__subtitle">Last updated <?= e(format_datetime($txn['updated_at'])) ?></p>
    </div>
</section>

<?= render_record_form(url('pages/transactions/edit.php', ['id' => $txn['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/transactions/view.php', ['id' => $txn['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
