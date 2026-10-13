<?php
/**
 * Transactions - what one repayment of a borrowed purchase paid (opened in a
 * modal from the purchase's repayment history). The card owner records the
 * borrower's split - late charges, which installment - for a repayment saved
 * without it (e.g. before choices were kept). Only the card owner; the
 * borrower is told. Money not split here fills the installments oldest first.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/transaction_functions.php';

$currentUser = require_admin();

$txn = find_or_redirect(transaction_find(request_id()), 'pages/transactions/index.php', 'Transaction');
$back = 'pages/transactions/view.php?id=' . $txn['id'];
$purchase = borrowed_owner_purchases(null, $txn['id'])[0] ?? null;
$paymentId = input_string(is_post() ? $_POST : $_GET, 'payment_id');
$payment = $purchase && is_valid_uuid($paymentId)
    ? db_row("SELECT * FROM borrower_payments WHERE id = :p AND transaction_id = :t AND status IN ('approved', 'submitted', 'under_review')", [':p' => $paymentId, ':t' => $txn['id']])
    : null;
if (!$purchase || $purchase['ledger']['mode'] !== 'transaction' || !$payment) {
    flash('error', 'This repayment cannot be split here.');
    redirect($back);
}

// The charges it can pay: late charges, then each installment (or the purchase itself).
$ledger = $purchase['ledger'];
$targets = ['penalty' => ['label' => 'Late charges', 'part' => 'penalty', 'schedule_id' => null, 'owes' => $ledger['penalties']]];
foreach ($ledger['rows'] as $i => $r) {
    $key = $r['schedule_id'] ?? 'purchase';
    $targets[$key] = ['label' => $ledger['installments'] > 0 ? 'Installment ' . ($i + 1) . ' of ' . $ledger['installments'] : 'This purchase',
                      'part' => $r['schedule_id'] ? 'installment' : 'purchase', 'schedule_id' => $r['schedule_id'], 'owes' => $r['amount'],
                      'due' => $r['due']['date']];
}
$current = [];
foreach (db_all('SELECT part, installment_schedule_id, amount FROM borrower_payment_purchase_parts WHERE borrower_payment_id = :p', [':p' => $payment['id']]) as $pp) {
    $key = $pp['part'] === 'penalty' ? 'penalty' : ($pp['installment_schedule_id'] ?? 'purchase');
    $current[$key] = round(($current[$key] ?? 0) + (float) $pp['amount'], 2);
}
$values = array_map(static fn ($v) => number_format($v, 2, '.', ''), $current);
$error = null;

if (is_post()) {
    require_csrf($back);
    $parts = [];
    $values = [];
    $sum = 0.0;
    foreach ($targets as $key => $t) {
        $raw = str_replace([',', ' ', '₱'], '', (string) ($_POST['part'][$key] ?? ''));
        $values[$key] = $raw;
        if ($raw === '' || (float) $raw == 0.0) {
            continue;
        }
        if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $raw)) {
            $error = 'Enter a valid amount for ' . $t['label'] . '.';
            break;
        }
        $amount = round((float) $raw, 2);
        if ($amount > $t['owes'] + 0.004) {
            $error = $t['label'] . ' is only ' . format_money($t['owes']) . '.';
            break;
        }
        $parts[] = ['part' => $t['part'], 'installment_schedule_id' => $t['schedule_id'], 'amount' => $amount, 'label' => $t['label']];
        $sum += $amount;
    }
    if ($error === null && $sum > (float) $payment['amount'] + 0.004) {
        $error = 'You split ' . format_money($sum) . ', more than the ' . format_money($payment['amount']) . ' repayment.';
    }
    if ($error === null) {
        $before = borrowed_parts_label($payment['id']);
        db_transaction(static function () use ($payment, $txn, $parts) {
            db_value('SELECT id FROM transactions WHERE id = :id FOR UPDATE', [':id' => $txn['id']]);
            borrowed_parts_save($payment['id'], $txn['id'], $parts);
            borrowed_sync_penalty_payments($txn['id']);
        });
        $after = borrowed_parts_label($payment['id']);
        log_activity('split_repayment', 'transaction', $txn['id'], 'Repayment ' . $payment['payment_number'] . ' of ' . $txn['transaction_number'] . ' now pays: ' . ($after ?: 'oldest installments first'),
            ['for' => $before], ['for' => $after]);
        notify_borrower($txn['borrower_id'], 'payment_updated', 'Repayment ' . $payment['payment_number'] . ' updated',
            'Your repayment ' . $payment['payment_number'] . ' of ' . format_money($payment['amount']) . ' for ' . $txn['transaction_number'] . ' now pays: '
            . ($after ?: 'the oldest installments first') . '.', 'borrowed_purchase', $txn['id']);
        flash('success', 'Repayment ' . $payment['payment_number'] . ' now pays: ' . ($after ?: 'the oldest installments first') . '.');
        redirect($back);
    }
}

$pageTitle = 'What it paid';
$activeNav = 'transactions';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Transactions' => url('pages/transactions/index.php'),
    $txn['transaction_number'] => url($back),
    'What it paid' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">What repayment <?= e($payment['payment_number']) ?> paid</h2>
        <p class="page-header__subtitle"><?= e(format_money($payment['amount'])) ?> · paid <?= e(format_date($payment['payment_date'])) ?> · <?= e($txn['transaction_number']) ?></p>
    </div>
</section>

<form method="post" action="<?= e(url('pages/transactions/repayment_parts.php')) ?>" class="form-card"
      data-confirm="Save what this repayment paid? The borrower is told." data-confirm-title="Save the split?" data-confirm-button="Save">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e($txn['id']) ?>">
    <input type="hidden" name="payment_id" value="<?= e($payment['id']) ?>">
    <?php if ($error !== null): ?><div class="alert alert--error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <p class="field-help">Enter the amounts the borrower chose. Whatever is left of the <?= e(format_money($payment['amount'])) ?> pays the installments oldest first.</p>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th scope="col">Charge</th><th scope="col">Repay by</th><th scope="col" class="num">Amount</th><th scope="col" class="num">This repayment pays</th></tr></thead>
        <tbody>
        <?php foreach ($targets as $key => $t): ?>
            <?php if ($key === 'penalty' && $t['owes'] <= 0.004) { continue; } ?>
            <tr><td><?= e($t['label']) ?></td><td><?= isset($t['due']) && $t['due'] ? e(format_date($t['due'])) : '—' ?></td>
                <td class="num"><?= e(format_money($t['owes'])) ?></td>
                <td class="num"><input type="text" inputmode="decimal" class="input-num" data-scale="2" name="part[<?= e($key) ?>]" value="<?= e($values[$key] ?? '') ?>"
                       aria-label="<?= e($t['label']) ?> amount" placeholder="0.00" style="max-width: 140px"></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="form-actions">
        <a href="<?= e(url($back)) ?>" class="btn btn--outline">Cancel</a>
        <button type="submit" class="btn btn--primary"><?= icon('check') ?> <span class="btn__label">Save</span></button>
    </div>
</form>

<?php require APP_ROOT . '/includes/footer.php'; ?>
