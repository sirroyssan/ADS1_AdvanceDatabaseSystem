<?php
/**
 * Transactions - full transaction page: details, purchased items, attached
 * documents, installment plan, borrower obligations and status actions.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/transaction_functions.php';

$currentUser = require_staff_area();
// Staff see the record and can ask the owner to change it; changing it directly is the owner's.
$canManage = user_is_admin($currentUser);

$txn = find_or_redirect(transaction_find(request_id()), 'pages/transactions/index.php', 'Transaction');
$id = $txn['id'];

$items = db_all('SELECT id, product_name, brand, model, quantity, unit_price, total_amount, serial_number, imei FROM transaction_items WHERE transaction_id = :id ORDER BY created_at', [':id' => $id]);
$itemsTotal = array_sum(array_map(static fn ($i) => (float) $i['total_amount'], $items));
$attachments = db_all(
    'SELECT ta.id AS link_id, ta.attachment_type AS type_label, d.id AS document_id, d.original_filename, d.document_type
       FROM transaction_attachments ta JOIN documents d ON d.id = ta.document_id
      WHERE ta.transaction_id = :id ORDER BY ta.created_at',
    [':id' => $id]
);
$plan = db_row('SELECT id, number_of_installments, installment_amount, frequency, status FROM installment_plans WHERE transaction_id = :id', [':id' => $id]);
$obligations = db_all(
    'SELECT o.id, o.obligation_number, o.total_amount_due, o.outstanding_amount, o.status, b.first_name, b.last_name
       FROM borrower_obligations o JOIN borrowers b ON b.id = o.borrower_id
      WHERE o.transaction_id = :id ORDER BY o.obligation_date',
    [':id' => $id]
);
$usage = transaction_usage($id);
// Assigned to a borrower: what they owe for it, from this transaction (includes/borrowed.php).
$purchase = $txn['borrower_id'] ? (borrowed_owner_purchases(null, $id)[0] ?? null) : null;
$repayments = $purchase ? borrowed_repayments($id) : [];

$isLocked = in_array($txn['status'], TXN_LOCKED_STATUSES, true);
$itemsLocked = in_array($txn['status'], TXN_ITEM_LOCKED_STATUSES, true);
$related = url('pages/transactions/related.php');

// Status changes offered from this page (full status list is on the edit form).
$quickStatuses = match ($txn['status']) {
    'draft'    => ['posted', 'cancelled'],
    'posted'   => ['active', 'completed', 'disputed', 'cancelled'],
    'active'   => ['completed', 'disputed', 'refunded', 'cancelled'],
    'disputed' => ['active', 'refunded', 'cancelled'],
    'completed' => ['refunded'],
    default    => [],
};
if (!$canManage) {
    $quickStatuses = [];   // status changes, items and attachments are the owner's
    $itemsLocked = true;
}

$pageTitle = 'Transaction Details';
$activeNav = 'transactions';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Transactions' => url('pages/transactions/index.php'), $txn['transaction_number'] => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Transaction <?= e($txn['transaction_number']) ?></h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($txn['transaction_number']) ?> · <?= e(format_money($txn['amount'])) ?></h2>
        <p class="page-header__subtitle"><?= e(format_date($txn['transaction_date'])) ?> · <?= e($txn['card_nickname']) ?> •••• <?= e($txn['last_four']) ?> · <?= badge($txn['status']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/transactions/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <?php if (!$isLocked && $canManage): ?>
            <a data-modal-form href="<?= e(url('pages/transactions/edit.php', ['id' => $id])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
            <?php if ($txn['status'] !== 'draft'): ?>
                <a data-modal-form href="<?= e(url('pages/transactions/void.php', ['id' => $id])) ?>" class="btn btn--outline btn--danger-outline"><?= icon('trash') ?> <span>Delete</span></a>
            <?php endif; ?>
        <?php elseif (!$isLocked): ?>
            <a data-modal-form href="<?= e(url('pages/authorizations/request.php', ['type' => 'transaction', 'id' => $id, 'action' => 'edit'])) ?>" class="btn btn--outline"><?= icon('lock') ?> <span>Edit</span></a>
            <a data-modal-form href="<?= e(url('pages/authorizations/request.php', ['type' => 'transaction', 'id' => $id, 'action' => 'delete'])) ?>" class="btn btn--outline"><?= icon('trash') ?> <span>Delete</span></a>
        <?php endif; ?>
    </div>
</section>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Transaction details</h3></header>
        <?= render_details([
            'Transaction number' => e($txn['transaction_number']),
            'Credit card'        => related_link($txn['credit_card_id'], $txn['card_nickname'] . ' •••• ' . $txn['last_four'], 'pages/credit_cards/view.php'),
            'Transaction date'   => e(format_date($txn['transaction_date'])),
            'Posting date'       => show($txn['posting_date'] ? format_date($txn['posting_date']) : null),
            'Amount'             => '<strong>' . e(format_money($txn['amount'])) . '</strong> ' . e($txn['currency']),
            'Transaction type'   => e(humanize($txn['transaction_type'])),
            'Payment type'       => e(humanize($txn['payment_type'])),
            'Borrower'           => $txn['borrower_id'] ? ($canManage ? related_link($txn['borrower_id'], borrower_name($txn) . ' (' . $txn['borrower_number'] . ')', 'pages/borrowers/view.php') : e(borrower_name($txn) . ' (' . $txn['borrower_number'] . ')')) : 'Owner\'s own purchase',
            'Merchant'           => related_link($txn['merchant_id'], $txn['merchant_name'] ? $txn['merchant_name'] . ($txn['branch'] ? ' — ' . $txn['branch'] : '') : null, 'pages/merchants/view.php'),
            'Category'           => related_link($txn['category_id'], $txn['category_name'], 'pages/categories/view.php'),
            'Reference number'   => show($txn['reference_number']),
            'Receipt number'     => show($txn['receipt_number']),
            'Location'           => show_multiline($txn['location']),
            'Notes'              => show_multiline($txn['notes']),
            'Status'             => badge($txn['status']),
            'Recorded'           => e(format_datetime($txn['created_at'])) . ($txn['created_by_name'] ? ' by ' . e($txn['created_by_name']) : ''),
            'Last updated'       => e(format_datetime($txn['updated_at'])),
        ]) ?>
    </section>

    <div class="detail-side">

        <?php if ($quickStatuses): ?>
            <section class="panel no-print">
                <header class="panel__header"><h3>Change status</h3></header>
                <form method="post" action="<?= e($related) ?>" class="status-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($id) ?>">
                    <input type="hidden" name="action" value="status">
                    <div class="form-group">
                        <label for="new_status">New status</label>
                        <select id="new_status" name="status" required>
                            <?php foreach ($quickStatuses as $s): ?><option value="<?= e($s) ?>"><?= e(humanize($s)) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn--primary" data-loading-text="Saving…"><?= icon('check') ?> <span class="btn__label">Apply</span></button>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel">
            <header class="panel__header"><h3>Installment plan</h3></header>
            <?php if ($plan): ?>
                <p><?= related_link($plan['id'], $plan['number_of_installments'] . ' × ' . format_money($plan['installment_amount']) . ' (' . $plan['frequency'] . ')', 'pages/installment_plans/view.php') ?> <?= badge($plan['status']) ?></p>
            <?php elseif (!$isLocked && $canManage): ?>
                <p class="field-help">No installment plan.</p>
                <a data-modal-form class="btn btn--sm btn--outline no-print" href="<?= e(url('pages/installment_plans/create.php', ['transaction_id' => $id])) ?>"><?= icon('plus') ?> <span>Create plan</span></a>
            <?php else: ?>
                <p class="field-help">No installment plan.</p>
            <?php endif; ?>
        </section>

        <?php if ($obligations): ?>
        <section class="panel">
            <header class="panel__header">
                <h3>Borrower obligations</h3>
            </header>
            <?php if (!$obligations): ?>
                <p class="empty-inline"><?= $txn['borrower_id'] ? 'No obligation created yet.' : 'Owner\'s own purchase — no borrower obligation.' ?></p>
            <?php else: ?>
                <ul class="child-list">
                    <?php foreach ($obligations as $o): ?>
                        <li><a href="<?= e(url('pages/obligations/view.php', ['id' => $o['id']])) ?>"><?= e($o['obligation_number']) ?></a>
                            <span><?= e(format_money($o['outstanding_amount'])) ?> left <?= badge($o['status']) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($txn['status'] === 'draft' && $canManage): ?>
            <section class="panel panel--danger no-print">
                <header class="panel__header"><h3>Delete draft</h3></header>
                <?php if ($usage['total'] > 0): ?>
                    <p class="field-help">This draft has <?= e(usage_text($usage)) ?>. Remove them first, or cancel the transaction instead.</p>
                <?php else: ?>
                    <p class="field-help">Only drafts without items, plans or obligations can be deleted.</p>
                    <?= post_button(url('pages/transactions/delete.php'), ['id' => $id], 'Delete draft', 'trash', 'btn btn--danger btn--block',
                        'Are you sure you want to delete draft ' . $txn['transaction_number'] . '? This cannot be undone.') ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</div>

    <?php if ($purchase): ?>
        <?php $l = $purchase['ledger']; ?>
        <section class="panel borrowed-panel" aria-labelledby="repay-title">
            <header class="panel__header">
                <h3 id="repay-title"><?= icon('wallet') ?> Borrower repayment — <?= e(borrower_name($txn)) ?></h3>
                <?= borrowed_status_badge($l['status']) ?>
            </header>
            <p class="field-help">This transaction is what <?= e(borrower_name($txn)) ?> owes you: it counts once on your card, and the same record shows in their account.</p>
            <?= borrowed_render_ledger($purchase, $l, $canManage) ?>
            <?php if ($l['mode'] === 'transaction'): ?>
                <h4 class="form-section">Repayments</h4>
                <?= borrowed_render_repayments($repayments, static function ($r) use ($related, $id, $canManage) {
                    if (!$canManage || !in_array($r['status'], BORROWED_REPORTED_STATUSES, true)) {
                        return '';
                    }
                    $url = url('pages/transactions/repayment.php');

                    return post_button($url, ['id' => $id, 'action' => 'confirm', 'payment_id' => $r['id']], 'Confirm', 'check', 'btn btn--sm btn--primary',
                            'Confirm that you received ' . format_money($r['amount']) . '? It is then taken off what the borrower owes.', false, 'Confirm repayment?')
                        . '<form method="post" action="' . e($url) . '" class="inline-form">' . csrf_field()
                        . '<input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="action" value="reject"><input type="hidden" name="payment_id" value="' . e($r['id']) . '">'
                        . '<input type="text" name="reason" maxlength="1000" placeholder="Reason" aria-label="Reason for rejecting" required> '
                        . '<button type="submit" class="btn btn--sm btn--outline">' . icon('x') . ' <span>Reject</span></button></form>';
                }) ?>
                <?php if ($l['outstanding'] > 0.004 && !$isLocked && !$canManage): ?>
                    <p class="no-print"><a class="btn btn--sm btn--primary" href="<?= e(url('pages/staff/receive.php', ['borrower_id' => $txn['borrower_id']])) ?>"><?= icon('wallet') ?> <span>Receive a payment</span></a></p>
                <?php elseif ($l['outstanding'] > 0.004 && !$isLocked): ?>
                    <details class="no-print">
                        <summary>Record a repayment you received</summary>
                        <form method="post" action="<?= e(url('pages/transactions/repayment.php')) ?>" class="status-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= e($id) ?>">
                            <input type="hidden" name="action" value="record">
                            <?= form_once_field('transaction_repayment') ?>
                            <?= render_fields([
                                'amount'           => ['label' => 'Amount received', 'type' => 'decimal', 'required' => true, 'help' => 'Up to ' . format_money($l['outstanding']) . '. Partial amounts are fine.'],
                                'payment_date'     => ['label' => 'Date received', 'type' => 'date', 'required' => true],
                                'reference_number' => ['label' => 'Reference', 'type' => 'text', 'max' => 150],
                            ], ['payment_date' => date('Y-m-d')], []) ?>
                            <button type="submit" class="btn btn--primary" data-loading-text="Saving…"><?= icon('check') ?> <span class="btn__label">Record repayment</span></button>
                        </form>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

<section class="panel subpanel">
    <header class="panel__header">
        <h3><?= icon('layers') ?> Purchased items</h3>
        <?php if (!$itemsLocked): ?>
            <a data-modal-form class="btn btn--sm btn--outline no-print" href="<?= e(url('pages/transactions/item.php', ['transaction_id' => $id])) ?>"><?= icon('plus') ?> <span>Add item</span></a>
        <?php endif; ?>
    </header>
    <?php if (!$items): ?>
        <p class="empty-inline">No items recorded.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Product</th><th scope="col">Brand / Model</th><th scope="col">Serial / IMEI</th><th scope="col" class="num">Qty</th><th scope="col" class="num">Unit price</th><th scope="col" class="num">Total</th><th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td data-label="Product"><?= e($item['product_name']) ?></td>
                        <td data-label="Brand / Model"><?= show(trim(($item['brand'] ?? '') . ' ' . ($item['model'] ?? ''))) ?></td>
                        <td data-label="Serial / IMEI"><?= show(trim(($item['serial_number'] ?? '') . ' ' . ($item['imei'] ?? ''))) ?></td>
                        <td data-label="Qty" class="num"><?= e(rtrim(rtrim($item['quantity'], '0'), '.')) ?></td>
                        <td data-label="Unit price" class="num"><?= e(format_money($item['unit_price'])) ?></td>
                        <td data-label="Total" class="num"><?= e(format_money($item['total_amount'])) ?></td>
                        <td class="actions no-print"><div class="row-actions">
                            <?php if (!$itemsLocked): ?>
                                <?= icon_link(url('pages/transactions/item.php', ['transaction_id' => $id, 'item_id' => $item['id']]), 'edit', 'Edit ' . $item['product_name']) ?>
                                <?= post_button($related, ['id' => $id, 'action' => 'delete_item', 'item_id' => $item['id']], 'Remove ' . $item['product_name'], 'trash',
                                    'icon-button icon-button--danger', 'Remove "' . $item['product_name'] . '" from this transaction?', true) ?>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="5">Items total</td><td class="num"><?= e(format_money($itemsTotal)) ?></td><td class="no-print"></td></tr></tfoot>
            </table>
        </div>
        <?php if (abs($itemsTotal - (float) $txn['amount']) >= 0.01): ?>
            <p class="field-help text-danger">Items total (<?= e(format_money($itemsTotal)) ?>) differs from the transaction amount (<?= e(format_money($txn['amount'])) ?>).</p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?= render_attachments_panel($attachments, $related, ['id' => $id], false, !$isLocked && $canManage) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
