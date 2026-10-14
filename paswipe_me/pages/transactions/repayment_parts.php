<?php
/**
 * Transactions - one repayment of a borrowed purchase, read-only (opened in a
 * modal from the purchase's repayment history). It shows what the borrower
 * submitted and what it pays - exactly the borrower's choices; the rest goes
 * to the oldest unpaid installments. Nobody on the owner's side edits it:
 * a reported repayment is approved or rejected (with a reason, in a second
 * modal) at the bottom right; the borrower submits a new one if needed.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/transaction_functions.php';

$currentUser = require_admin();

$txn = find_or_redirect(transaction_find(request_id()), 'pages/transactions/index.php', 'Transaction');
$back = 'pages/transactions/view.php?id=' . $txn['id'];
$purchase = borrowed_owner_purchases(null, $txn['id'])[0] ?? null;
$paymentId = input_string($_GET, 'payment_id');
$payment = $purchase && is_valid_uuid($paymentId)
    ? db_row(
        'SELECT p.*, pm.display_name AS method_name, COALESCE(NULLIF(TRIM(CONCAT_WS(\' \', u.first_name, u.last_name)), \'\'), u.email) AS reviewer
           FROM borrower_payments p LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id LEFT JOIN users u ON u.id = p.reviewed_by
          WHERE p.id = :p AND p.transaction_id = :t',
        [':p' => $paymentId, ':t' => $txn['id']]
    )
    : null;
if (!$purchase || $purchase['ledger']['mode'] !== 'transaction' || !$payment) {
    flash('error', 'Repayment not found.');
    redirect($back);
}

$parts = borrowed_parts($payment['id']);
$chosen = round(array_sum(array_column($parts, 'amount')), 2);
$rest = round((float) $payment['amount'] - $chosen, 2);
$proofs = db_all(
    'SELECT d.id, d.original_filename FROM payment_attachments a JOIN documents d ON d.id = a.document_id
      WHERE a.borrower_payment_id = :p AND d.is_deleted = 0 ORDER BY a.created_at',
    [':p' => $payment['id']]
);
$pending = in_array($payment['status'], BORROWED_REPORTED_STATUSES, true);

$pageTitle = 'Repayment ' . $payment['payment_number'];
$activeNav = 'transactions';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Transactions' => url('pages/transactions/index.php'),
    $txn['transaction_number'] => url($back),
    $payment['payment_number'] => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Repayment <?= e($payment['payment_number']) ?></h2>
        <p class="page-header__subtitle"><?= e(format_money($payment['amount'])) ?> · paid <?= e(format_date($payment['payment_date'])) ?> · <?= e($txn['transaction_number']) ?> · <?= e(borrower_name($txn)) ?></p>
    </div>
</section>

<section class="panel">
    <?= render_details([
        'Amount'        => '<strong>' . e(format_money($payment['amount'])) . '</strong>',
        'Paid on'       => e(format_date($payment['payment_date'])),
        'Reference'     => e($payment['reference_number'] ?: '—'),
        'Method'        => e($payment['method_name'] ?: '—'),
        'Proof'         => $proofs ? implode('<br>', array_map(static fn ($d) => '<a href="' . e(url('pages/documents/download.php', ['id' => $d['id'], 'inline' => 1])) . '" target="_blank" rel="noopener">'
                            . icon('file-text', 'icon icon--sm') . ' ' . e($d['original_filename']) . '</a>', $proofs)) : '<span class="text-muted">None attached</span>',
        'Notes'         => $payment['notes'] ? nl2br(e($payment['notes'])) : '',
        'Status'        => badge($pending ? 'reported' : $payment['status'])
                           . ($payment['reviewed_at'] && !$pending ? ' <span class="text-muted">' . e(format_datetime($payment['reviewed_at'])) . ($payment['reviewer'] ? ' · by ' . e($payment['reviewer']) : '') . '</span>' : ''),
        'Reason'        => $payment['rejection_reason'] ? nl2br(e($payment['rejection_reason'])) : '',
    ]) ?>
</section>

<section class="panel">
    <header class="panel__header"><h3><?= icon('layers') ?> What it pays</h3></header>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th scope="col">Charge</th><th scope="col" class="num">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($parts as $p): ?>
            <tr><td><?= e($p['label']) ?></td><td class="num"><?= e(format_money($p['amount'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($rest > 0.004): ?>
            <tr><td><?= $parts ? 'The rest: the oldest unpaid installments first' : 'The oldest unpaid installments first (no choice made)' ?></td><td class="num"><?= e(format_money($rest)) ?></td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot><tr><td><strong>Total</strong></td><td class="num"><strong><?= e(format_money($payment['amount'])) ?></strong></td></tr></tfoot>
    </table></div>
    <p class="field-help">As the borrower submitted it. To change anything, reject it with the reason; the borrower submits a corrected repayment.</p>
</section>

<?php if ($pending): ?>
    <?= decision_bar([url('pages/transactions/repayment.php'), ['id' => $txn['id'], 'action' => 'confirm', 'payment_id' => $payment['id']],
        'Approve the repayment of ' . format_money($payment['amount']) . '? It is then taken off what the borrower owes.', 'Approve repayment?'],
        'repayment', ['id' => $txn['id'], 'payment_id' => $payment['id']]) ?>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
