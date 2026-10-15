<?php
/**
 * Staff - receive a payment from a borrower in person:
 *   1. choose the borrower (only the owner's borrowers can be chosen);
 *   2. see what they owe and their recent payments, enter the amount tendered
 *      and put it on the items / installments being paid;
 *   3. review; 4. confirm - saved once (one-time token + row lock), applied at
 *      once, and an acknowledgement receipt is issued (includes/receipts.php).
 * Every step is validated again on the server; nothing is trusted from the review page.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/includes/receipts.php';

$currentUser = require_staff_area();

$borrowerId = input_string($_GET, 'borrower_id') ?: input_string($_POST, 'borrower_id');
$borrower = is_valid_uuid($borrowerId) && owns('borrowers', $borrowerId) ? db_row('SELECT * FROM borrowers WHERE id = :id', [':id' => $borrowerId]) : null;
if ($borrowerId !== '' && !$borrower) {
    flash('error', 'Borrower not found.');
    redirect('pages/staff/receive.php');
}

$step = 'form';
$errors = [];
$data = [];
$chosen = [];
$groups = [];
if ($borrower) {
    $groups = array_merge(payment_obligation_groups($borrower['id']), payment_purchase_groups($borrower['id']));
    $methods = portal_payment_method_options($borrower['id']);
    $fields = receive_fields($methods);
    $data = form_defaults($fields);
}

if ($borrower && is_post()) {
    require_csrf('pages/staff/receive.php?borrower_id=' . $borrower['id']);
    foreach ((array) ($_POST['select'] ?? []) as $key => $on) {
        if (is_string($key) && $on === '1') {
            $chosen[$key] = (string) ($_POST['select_amount'][$key] ?? '');
        }
    }
    [$data, $purchases, $selections, $errors] = receive_validate($borrower, $groups, $_POST);
    $action = input_string($_POST, 'step');

    if (!$errors && $action === 'review') {
        $step = 'review';
    } elseif (!$errors && $action === 'confirm') {
        if (!form_once_use('staff_receive', input_string($_POST, 'once'))) {
            flash('info', 'This payment was already recorded.');
            redirect('pages/staff/receive.php', ['borrower_id' => $borrower['id']]);
        }
        try {
            $receiptId = receive_save($borrower, $currentUser, $data, $purchases, $selections, $groups);
            flash('success', 'Payment of ' . format_money($data['amount']) . ' from ' . borrower_name($borrower) . ' was recorded. Print the receipt for the borrower.');
            redirect('pages/staff/receipt.php', ['id' => $receiptId]);
        } catch (DomainException $e) {
            $errors['_form'] = $e->getMessage();
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$items = $borrower ? portal_all_items($borrower['id']) : [];
$totals = portal_items_totals($items);
$recent = $borrower ? db_all(
    "SELECT p.id, p.payment_number, p.payment_date, p.amount, p.status, r.id AS receipt_id, r.receipt_number
       FROM borrower_payments p LEFT JOIN payment_receipt_items i ON i.borrower_payment_id = p.id LEFT JOIN payment_receipts r ON r.id = i.receipt_id
      WHERE p.borrower_id = :b ORDER BY p.created_at DESC LIMIT 6",
    [':b' => $borrower['id']]
) : [];

$pageTitle = 'Receive Payment';
$pageScripts = ['assets/js/portal-pay.js'];
$activeNav = 'staff_receive';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Staff' => url('pages/staff/index.php'), 'Receive payment' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Receive payment</h2>
        <p class="page-header__subtitle">Record money a borrower hands over. It is applied at once and an acknowledgement receipt is issued.</p>
    </div>
</section>

<form method="get" action="<?= e(url('pages/staff/receive.php')) ?>" class="toolbar receive-pick no-print" aria-label="Choose borrower">
    <div class="form-group">
        <label for="f_borrower">Borrower</label>
        <select id="f_borrower" name="borrower_id" required>
            <option value="">Select borrower…</option>
            <?php foreach (borrower_options() as $id => $label): ?>
                <option value="<?= e($id) ?>" <?= $borrower && $borrower['id'] === $id ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn--outline"><?= icon('search') ?> <span>Show balance</span></button>
</form>

<?php if (!$borrower): ?>
    <p class="empty-inline">Choose the borrower who is paying to see what they owe.</p>
<?php elseif ($step === 'review'): ?>
    <section class="panel" aria-labelledby="review-title">
        <header class="panel__header"><h3 id="review-title"><?= icon('check') ?> Review before saving</h3></header>
        <?= render_details([
            'Borrower'        => e(borrower_name($borrower) . ' (' . $borrower['borrower_number'] . ')'),
            'Amount tendered' => '<strong>' . e(format_money($data['amount'])) . '</strong>',
            'Payment date'    => e(format_date($data['payment_date'])),
            'Payment method'  => e($data['payment_method_id'] ? ($methods[$data['payment_method_id']] ?? '') : 'Cash / not specified'),
            'Reference'       => show($data['reference_number']),
            'Remarks'         => show($data['notes']),
            'Balance now'     => e(format_money($totals['total'])),
            'Balance after'   => e(format_money(max(0, $totals['total'] - (float) $data['amount']))),
        ]) ?>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Applied to</th><th scope="col" class="num">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($groups as $g): ?>
                    <?php foreach ($g['rows'] as $r): ?>
                        <?php if (isset($chosen[$r['key']]) && (float) $chosen[$r['key']] > 0): ?>
                            <tr><td data-label="Applied to"><?= e($g['title']) ?> — <?= e($r['label']) ?></td><td data-label="Amount" class="num"><?= e(format_money($chosen[$r['key']])) ?></td></tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="post" action="<?= e(url('pages/staff/receive.php', ['borrower_id' => $borrower['id']])) ?>" class="form-actions">
            <?= csrf_field() ?>
            <?= form_once_field('staff_receive') ?>
            <?php foreach (['amount', 'payment_date', 'payment_method_id', 'reference_number', 'notes'] as $k): ?>
                <input type="hidden" name="<?= e($k) ?>" value="<?= e((string) ($data[$k] ?? '')) ?>">
            <?php endforeach; ?>
            <?php foreach ($chosen as $k => $v): ?>
                <input type="hidden" name="select[<?= e($k) ?>]" value="1"><input type="hidden" name="select_amount[<?= e($k) ?>]" value="<?= e($v) ?>">
            <?php endforeach; ?>
            <button type="submit" name="step" value="edit" class="btn btn--outline" formnovalidate><?= icon('arrow-left') ?> <span>Back to edit</span></button>
            <button type="submit" name="step" value="confirm" class="btn btn--primary" data-loading-text="Saving…"><?= icon('check') ?> <span class="btn__label">Confirm and save</span></button>
        </form>
    </section>
<?php else: ?>
    <section class="figure-grid figure-grid--fill">
        <div class="figure"><p class="figure__label">Outstanding</p><p class="figure__value"><?= e(format_money($totals['outstanding'])) ?></p></div>
        <div class="figure"><p class="figure__label">Late charges</p><p class="figure__value<?= $totals['penalties'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($totals['penalties'])) ?></p></div>
        <div class="figure figure--total"><p class="figure__label">Total owed</p><p class="figure__value"><?= e(format_money($totals['total'])) ?></p></div>
        <div class="figure"><p class="figure__label">Due this period</p><p class="figure__value"><?= e(format_money($totals['due_now'])) ?></p></div>
        <div class="figure"><p class="figure__label">Overdue</p><p class="figure__value<?= $totals['overdue'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($totals['overdue'])) ?></p></div>
    </section>

    <?php if (!$groups): ?>
        <div class="alert alert--info"><?= icon('info') ?><span><?= e(borrower_name($borrower)) ?> has nothing to pay right now.</span></div>
    <?php else: ?>
        <form method="post" action="<?= e(url('pages/staff/receive.php', ['borrower_id' => $borrower['id']])) ?>" class="form-card form-card--full" novalidate data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="step" value="review">
            <?php if ($errors): ?>
                <div class="alert alert--error" role="alert"><?= icon('alert') ?><span><?= e($errors['_form'] ?? ($errors['selection'] ?? 'Please correct the highlighted fields below.')) ?></span></div>
            <?php endif; ?>
            <?= render_fields($fields, $data, $errors) ?>
            <?= render_payment_for_table($groups, $chosen, $errors,
                'Tick the items or installments being paid and how much goes to each. The whole amount tendered must be assigned, and it cannot be more than what is owed. Late charges on an item are paid first.') ?>
            <div class="form-actions">
                <a href="<?= e(url('pages/staff/index.php')) ?>" class="btn btn--outline">Cancel</a>
                <button type="submit" class="btn btn--primary"><?= icon('check') ?> <span class="btn__label">Review payment</span></button>
            </div>
        </form>
    <?php endif; ?>

    <section class="panel subpanel" aria-labelledby="recent-title">
        <header class="panel__header"><h3 id="recent-title"><?= icon('receipt') ?> Recent payments of <?= e(borrower_name($borrower)) ?></h3></header>
        <?php if (!$recent): ?>
            <p class="empty-inline">No payments yet.</p>
        <?php else: ?>
            <ul class="child-list">
                <?php foreach ($recent as $p): ?>
                    <li><span><?= e($p['payment_number']) ?> · <?= e(format_date($p['payment_date'])) ?> <?= badge($p['status']) ?>
                        <?= $p['receipt_id'] ? ' · <a href="' . e(url('pages/staff/receipt.php', ['id' => $p['receipt_id']])) . '">' . e($p['receipt_number']) . '</a>' : '' ?></span>
                        <strong><?= e(format_money($p['amount'])) ?></strong></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
