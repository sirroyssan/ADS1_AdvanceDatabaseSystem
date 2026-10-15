<?php
/**
 * Transactions - add or edit one purchased item (transaction_items).
 * item.php?transaction_id=...            -> add
 * item.php?transaction_id=...&item_id=... -> edit
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/transaction_functions.php';

$currentUser = require_admin();

$txnId = input_string($_GET, 'transaction_id');
$txn = find_or_redirect(transaction_find($txnId), 'pages/transactions/index.php', 'Transaction');
$back = 'pages/transactions/view.php?id=' . $txn['id'];

if (in_array($txn['status'], TXN_ITEM_LOCKED_STATUSES, true)) {
    flash('error', 'Items of a ' . $txn['status'] . ' transaction can no longer be changed.');
    redirect($back);
}

$itemId = input_string($_GET, 'item_id');
$item = null;
if ($itemId !== '') {
    $item = is_valid_uuid($itemId)
        ? db_row('SELECT * FROM transaction_items WHERE id = :i AND transaction_id = :t', [':i' => $itemId, ':t' => $txn['id']])
        : null;
    if ($item === null) {
        flash('error', 'Item not found.');
        redirect($back);
    }
}

$fields = transaction_item_fields();
if ($item) {
    $fields['total_amount']['attrs']['data-keep'] = '1';
}
$data = $item ? form_data_from_row($fields, $item) : form_defaults($fields);
$errors = [];

if (is_post()) {
    require_csrf('pages/transactions/item.php?transaction_id=' . $txn['id'] . ($item ? '&item_id=' . $item['id'] : ''));
    [$data, $errors] = transaction_item_validate($_POST);

    if (!$errors) {
        try {
            if ($item) {
                db_update('transaction_items', $data, $item['id']);
                log_activity('update_item', 'transaction', $txn['id'], 'Updated item "' . $data['product_name'] . '"', array_intersect_key($item, $data), $data);
                flash('success', 'Item "' . $data['product_name'] . '" was updated.');
            } else {
                db_insert('transaction_items', ['id' => new_uuid(), 'transaction_id' => $txn['id']] + $data);
                log_activity('add_item', 'transaction', $txn['id'], 'Added item "' . $data['product_name'] . '"', null, $data);
                flash('success', 'Item "' . $data['product_name'] . '" was added.');
            }
            redirect($back);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = $item ? 'Edit Item' : 'Add Item';
$activeNav = 'transactions';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Transactions' => url('pages/transactions/index.php'),
    $txn['transaction_number'] => url('pages/transactions/view.php', ['id' => $txn['id']]),
    ($item ? 'Edit item' : 'Add item') => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= $item ? 'Edit item' : 'Add item' ?></h2>
        <p class="page-header__subtitle">Transaction <?= e($txn['transaction_number']) ?> · <?= e(format_money($txn['amount'])) ?></p>
    </div>
</section>

<?= render_record_form(
    url('pages/transactions/item.php', ['transaction_id' => $txn['id']] + ($item ? ['item_id' => $item['id']] : [])),
    $fields, $data, $errors, $item ? 'Save item' : 'Add item', url('pages/transactions/view.php', ['id' => $txn['id']])
) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
