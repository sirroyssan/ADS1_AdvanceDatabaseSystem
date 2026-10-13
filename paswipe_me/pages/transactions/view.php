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
// The borrower's side of a cash advance: shown as that cash advance's repayments, changed only on the cash advance.
$advanceOf = cash_share_advance($txn);

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
// Penalties of a purchase the borrower repays (tracked on the transaction): like an obligation's.
$txnPenalties = [];
if ($purchase && $purchase['ledger']['mode'] === 'transaction') {
    require_once APP_ROOT . '/pages/obligations/obligation_functions.php';
    $txnPenalties = db_all(
        'SELECT ap.*, r.name AS rule_name FROM applied_penalties ap JOIN penalty_rules r ON r.id = ap.penalty_rule_id
          WHERE ap.transaction_id = :id ORDER BY ap.applied_date',
        [':id' => $id]
    );
    $txnRules = borrowed_rules_for_card($txn['credit_card_id']);
    $txnBank = bank_canonical((string) db_value('SELECT issuer FROM credit_cards WHERE id = :c', [':c' => $txn['credit_card_id']]));
    $txnTerminable = array_filter($txnPenalties, static fn ($p) => (float) $p['remaining_amount'] > 0.004 || (float) $p['paid_amount'] <= 0.004);
    $firstOverdue = null;
    foreach ($purchase['ledger']['rows'] as $row) {
        if ($row['overdue'] && $row['due']['date']) {
            $firstOverdue = $row['due']['date'];
            break;
        }
    }
    $txnPenaltyDefaults = form_defaults(penalty_fields(), [
        'calculation_base' => number_format((float) ($purchase['ledger']['overdue_amount'] ?: $purchase['ledger']['remaining']), 2, '.', ''),
        'overdue_days' => $firstOverdue ? max(0, (int) ((strtotime(date('Y-m-d')) - strtotime($firstOverdue)) / 86400)) : 0,
    ]);
}

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

<?= $advanceOf
    ? render_breadcrumb(['Cash Advances' => url('pages/cash_advances/index.php'), $advanceOf['reference_number'] => url('pages/cash_advances/view.php', ['id' => $advanceOf['id']]), 'Repayments' => null])
    : render_breadcrumb(['Transactions' => url('pages/transactions/index.php'), $txn['transaction_number'] => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title"><?= $advanceOf ? "Cash advance " . e($advanceOf["reference_number"]) : "Transaction " . e($txn["transaction_number"]) ?></h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= $advanceOf ? 'Cash advance ' . e($advanceOf['reference_number']) : e($txn['transaction_number']) ?> · <?= e(format_money($txn['amount'])) ?></h2>
        <p class="page-header__subtitle"><?= e(format_date($txn['transaction_date'])) ?> · <?= e($txn['card_nickname']) ?> •••• <?= e($txn['last_four']) ?> · <?= badge($txn['status']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/transactions/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <?php if ($advanceOf): ?>
            <a data-modal-form href="<?= e(url('pages/cash_advances/view.php', ['id' => $advanceOf['id']])) ?>" class="btn btn--primary"><?= icon('banknote') ?> <span>Open cash advance</span></a>
        <?php elseif (!$isLocked && $canManage): ?>
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
        <header class="panel__header"><h3><?= $advanceOf ? 'Cash advance given to the borrower' : 'Transaction details' ?></h3></header>
        <?php if ($advanceOf): ?><p class="field-help">The borrower's side of cash advance <?= e($advanceOf['reference_number']) ?> — kept here for their repayments and late charges. It is not a card transaction: change it, or convert it to installments, on the cash advance.</p><?php endif; ?>
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

        <?php if ($txn['status'] === 'draft' && $canManage && !$advanceOf): ?>
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
                    if (!$canManage || !in_array($r['status'], array_merge(['approved'], BORROWED_REPORTED_STATUSES), true)) {
                        return '';
                    }
                    // What it paid: late charges / which installment (the borrower's split).
                    $split = '<a data-modal-form class="btn btn--sm btn--outline" href="' . e(url('pages/transactions/repayment_parts.php', ['id' => $id, 'payment_id' => $r['id']])) . '">'
                        . icon('edit') . ' <span>What it paid</span></a> ';
                    if ($r['status'] === 'approved') {
                        return $split;
                    }
                    $url = url('pages/transactions/repayment.php');

                    return $split . post_button($url, ['id' => $id, 'action' => 'confirm', 'payment_id' => $r['id']], 'Confirm', 'check', 'btn btn--sm btn--primary',
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

<?php if ($purchase && $purchase['ledger']['mode'] === 'transaction'): ?>
<section class="panel subpanel" id="penalties">
    <header class="panel__header">
        <h3><?= icon('scale') ?> Penalties</h3>
        <?php if ($canManage && !$isLocked): ?>
            <div class="action-bar no-print">
                <?= post_button($related, ['id' => $id, 'action' => 'run_penalties'], 'Check penalties now', 'repeat', 'btn btn--sm btn--outline') ?>
                <button type="button" class="btn btn--sm btn--outline" data-modal-panel="add-txn-penalty"><?= icon('plus') ?> <span>Apply penalty</span></button>
            </div>
        <?php endif; ?>
    </header>
    <div class="penalty-rules-note">
        <strong>Penalty rules assigned</strong> (active rules for <?= e($txnBank ?: 'this card\'s bank') ?>):
        <?php if (!$txnRules): ?>
            <span class="text-muted">none — <a href="<?= e(url('pages/penalty_rules/index.php')) ?>">set a rule's Bank to this card's bank</a>.</span>
        <?php else: ?>
            <ul>
                <?php foreach ($txnRules as $r): ?>
                    <li><?= e($r['name']) ?> — <?= e(penalty_rule_summary($r)) ?> · charges from <?= e(format_date($r['charges_from'])) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php if ($canManage && !$isLocked): ?>
        <form method="post" action="<?= e($related) ?>" class="inline-panel no-print" id="add-txn-penalty" hidden novalidate data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($id) ?>">
            <input type="hidden" name="action" value="add_penalty">
            <?php if (!penalty_rule_options()): ?>
                <p class="field-help">No active penalty rules. <a data-modal-form href="<?= e(url('pages/penalty_rules/create.php')) ?>">Create a rule</a> first.</p>
            <?php else: ?>
                <?= render_fields(penalty_fields(), $txnPenaltyDefaults, []) ?>
                <div class="inline-panel__actions">
                    <button type="button" class="btn btn--outline" data-toggle-panel="add-txn-penalty">Cancel</button>
                    <button type="submit" class="btn btn--primary" data-loading-text="Saving…"><?= icon('check') ?> <span class="btn__label">Apply penalty</span></button>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
    <?php if (!$txnPenalties): ?>
        <p class="empty-inline">No penalties applied.</p>
    <?php else: ?>
        <form method="post" action="<?= e($related) ?>" id="terminate-txn-penalty" data-bulk-form novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($id) ?>">
            <input type="hidden" name="action" value="terminate_penalties">
            <div class="table-wrap">
                <table class="table table--cards table--compact">
                    <thead><tr>
                        <th scope="col" class="col-check no-print"><?php if ($canManage && $txnTerminable): ?><input type="checkbox" data-check-all aria-label="Select all penalties that can be waived or deleted"><?php endif; ?></th>
                        <th scope="col">Date</th><th scope="col">Rule</th><th scope="col" class="num">Days late</th><th scope="col" class="num">Base</th><th scope="col" class="num">Amount</th><th scope="col" class="num">Paid</th><th scope="col" class="num">Remaining</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($txnPenalties as $k => $p): ?>
                        <tr>
                            <td class="col-check no-print">
                                <?php if ($canManage && isset($txnTerminable[$k])): ?>
                                    <input type="checkbox" name="item_ids[]" value="<?= e($p['id']) ?>" data-check-item aria-label="Select penalty of <?= e(format_date($p['applied_date'])) ?>, <?= e($p['rule_name']) ?>">
                                <?php endif; ?>
                            </td>
                            <td data-label="Date"><?= e(format_date($p['applied_date'])) ?></td>
                            <td data-label="Rule"><?= e($p['rule_name']) ?><?= $p['notes'] ? '<br><span class="text-muted">' . nl2br(e($p['notes'])) . '</span>' : '' ?></td>
                            <td data-label="Days late" class="num"><?= e($p['overdue_days']) ?></td>
                            <td data-label="Base" class="num"><?= e(format_money($p['calculation_base'])) ?></td>
                            <td data-label="Amount" class="num"><?= e(format_money($p['amount'])) ?></td>
                            <td data-label="Paid" class="num"><?= e(format_money($p['paid_amount'])) ?></td>
                            <td data-label="Remaining" class="num"><?= e(format_money($p['remaining_amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($canManage && $txnTerminable): ?>
                <div class="inline-panel bulk-panel no-print">
                    <p class="bulk-panel__count"><strong data-check-count>0</strong> selected · tick the penalties above (or the box in the header for all), then:</p>
                    <?= render_fields([
                        'mode'   => ['label' => 'Action', 'type' => 'select', 'options' => ['waive' => 'Waive selected', 'delete' => 'Delete selected'], 'required' => true,
                                     'help' => 'Waive keeps the records and sets what is unpaid to zero. Delete removes penalties that have nothing paid.'],
                        'reason' => ['label' => 'Reason (applies to all selected)', 'type' => 'textarea', 'rows' => 2, 'required' => true, 'max' => 1000,
                                     'placeholder' => 'e.g. Cash payment received offline but not yet recorded in the system',
                                     'help' => 'Required (at least ' . PENALTY_REASON_MIN . ' characters). Saved with each penalty and in the activity log. Owners and administrators only.'],
                    ], ['mode' => 'waive'], []) ?>
                    <div class="inline-panel__actions">
                        <button type="submit" class="btn btn--danger" data-check-submit data-loading-text="Saving…" disabled><?= icon('check') ?> <span class="btn__label">Apply to selected</span></button>
                    </div>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
    <p class="field-help">Penalties are charged automatically every month while a repayment of this purchase is overdue (for an installment purchase: per overdue installment). Repayments pay penalties first (oldest first), then the installments.</p>
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
