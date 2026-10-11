<?php
/**
 * Borrower portal - one of my payments: status, owner's reason, proof files,
 * where it was applied and its history. Fix or cancel while still open.
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once APP_ROOT . '/pages/borrower_payments/payment_functions.php';
require_once APP_ROOT . '/pages/documents/document_functions.php';

[$currentUser, $borrower] = require_borrower();
$bid = $borrower['id'];

$payment = portal_find(
    'SELECT p.*, pm.display_name AS method_name, pm.method_type FROM borrower_payments p
       LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
      WHERE p.id = :id AND p.borrower_id = :b',
    input_string($_GET, 'id'), $bid, 'portal/payments.php', 'Payment'
);
$id = $payment['id'];

$proofs = db_all(
    'SELECT d.id, d.original_filename, d.mime_type, d.file_size, d.uploaded_at FROM payment_attachments pa
       JOIN documents d ON d.id = pa.document_id
      WHERE pa.borrower_payment_id = :p AND d.is_deleted = 0 ORDER BY d.uploaded_at',
    [':p' => $id]
);
$applied = db_all(
    'SELECT a.allocated_amount, o.id AS obligation_id, o.obligation_number, o.description FROM borrower_payment_allocations a
       JOIN borrower_obligations o ON o.id = a.obligation_id
      WHERE a.borrower_payment_id = :p AND o.borrower_id = :b ORDER BY a.created_at',
    [':p' => $id, ':b' => $bid]
);
$history = db_all(
    'SELECT action, to_status, reason, performed_by, performed_at FROM payment_verification_logs WHERE borrower_payment_id = :p ORDER BY performed_at, id',
    [':p' => $id]
);
$selections = payment_selections($id);
// A repayment of a card purchase made on the owner's card (same form, linked to the purchase).
$purchase = $payment['transaction_id']
    ? db_row('SELECT t.id, t.transaction_number, t.notes, m.merchant_name FROM transactions t LEFT JOIN merchants m ON m.id = t.merchant_id WHERE t.id = :t AND t.borrower_id = :b',
        [':t' => $payment['transaction_id'], ':b' => $payment['borrower_id']])
    : null;
$canEdit = in_array($payment['status'], PAYMENT_EDITABLE_STATUSES, true);
$canCancel = in_array($payment['status'], PORTAL_CANCELLABLE_STATUSES, true);

$pageTitle = 'My Payment';
$activeNav = 'portal_payments';
$layout = 'portal';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['My Payments' => url('portal/payments.php'), $payment['payment_number'] => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Payment <?= e($payment['payment_number']) ?> — <?= e(borrower_name($borrower)) ?></h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($payment['payment_number']) ?></h2>
        <p class="page-header__subtitle"><?= e(format_money($payment['amount'])) ?> paid <?= e(format_date($payment['payment_date'])) ?> · <?= badge($payment['status']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('portal/payments.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <?php if ($canCancel): ?>
            <?= post_button(url('portal/payment_cancel.php'), ['id' => $id], 'Cancel payment', 'x', 'btn btn--outline',
                'Cancel payment ' . $payment['payment_number'] . '? Use this only if you submitted it by mistake.', false, 'Cancel this payment?') ?>
        <?php endif; ?>
        <?php if ($canEdit): ?>
            <a data-modal-form href="<?= e(url('portal/pay.php', ['id' => $id])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span><?= $payment['status'] === 'correction_required' ? 'Fix and resubmit' : 'Edit' ?></span></a>
        <?php endif; ?>
    </div>
</section>

<?php if ($payment['status'] === 'correction_required'): ?>
    <div class="alert alert--warning" role="status"><?= icon('alert') ?>
        <span><strong>The owner asked you to fix this payment:</strong> <?= e($payment['correction_reason'] ?: 'See the history below.') ?></span></div>
<?php elseif ($payment['status'] === 'rejected'): ?>
    <div class="alert alert--error" role="status"><?= icon('alert') ?>
        <span><strong>This payment was not accepted:</strong> <?= e($payment['rejection_reason'] ?: 'Please contact the owner.') ?></span></div>
<?php elseif (in_array($payment['status'], ['submitted', 'under_review'], true)): ?>
    <div class="alert alert--info" role="status"><?= icon('info') ?>
        <span>Waiting for the owner to review. It will count toward your balance once approved.</span></div>
<?php endif; ?>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Payment details</h3></header>
        <?= render_details([
            'Payment no.'  => e($payment['payment_number']),
            'Amount'       => '<strong>' . e(format_money($payment['amount'])) . '</strong>',
            'Payment date' => e(format_date($payment['payment_date'])),
            'Method'       => show($payment['method_name']),
            'Reference'    => show($payment['reference_number']),
            'Notes'        => show_multiline($payment['notes']),
            'Status'       => badge($payment['status']),
            'Submitted'    => e(format_datetime($payment['submitted_at'])),
            'Reviewed'     => show($payment['reviewed_at'] ? format_datetime($payment['reviewed_at']) : null),
            'Applied'      => badge($payment['allocation_status']),
        ]) ?>
    </section>

    <div class="detail-side">
        <section class="panel">
            <header class="panel__header"><h3>Proof of payment</h3></header>
            <?php if (!$proofs): ?>
                <p class="empty-inline">No file attached.</p>
            <?php else: ?>
                <ul class="child-list">
                    <?php foreach ($proofs as $d): ?>
                        <li><a href="<?= e(url('portal/download.php', ['id' => $d['id'], 'inline' => '1'])) ?>" target="_blank" rel="noopener"><?= e($d['original_filename']) ?></a>
                            <span><?= e(format_bytes((int) $d['file_size'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <p class="field-help no-print">Add another file with <a data-modal-form href="<?= e(url('portal/pay.php', ['id' => $id])) ?>">Edit</a>.</p>
            <?php endif; ?>
        </section>

        <section class="panel">
            <header class="panel__header"><h3>You chose to pay</h3></header>
            <?php if ($purchase): ?>
                <ul class="child-list">
                    <li><a href="<?= e(url('pages/borrowed/view.php', ['id' => $purchase['id']])) ?>">Card purchase <?= e($purchase['merchant_name'] ?: ($purchase['notes'] ? excerpt($purchase['notes'], 40) : $purchase['transaction_number'])) ?></a>
                        <span><?= e(format_money($payment['amount'])) ?></span></li>
                </ul>
                <p class="field-help">Taken off this purchase once the card owner confirms it (late charges first, then the oldest installment).</p>
            <?php elseif (!$selections): ?>
                <p class="empty-inline">No item chosen — the owner will post this payment.</p>
            <?php else: ?>
                <ul class="child-list">
                    <?php foreach ($selections as $sel): ?>
                        <li><a href="<?= e(url('portal/obligation.php', ['id' => $sel['obligation_id']])) ?>"><?= e(payment_selection_label($sel)) ?></a>
                            <span><?= e(format_money($sel['amount'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="panel">
            <header class="panel__header"><h3>Applied to</h3></header>
            <?php if ($purchase): ?>
                <p class="empty-inline"><?= $payment['status'] === 'approved' ? 'Confirmed by the card owner and taken off the purchase.' : 'Not confirmed yet.' ?></p>
            <?php elseif (!$applied): ?>
                <p class="empty-inline"><?= $payment['status'] === 'approved' ? 'Approved — the owner will apply it shortly.' : 'Not applied yet.' ?></p>
            <?php else: ?>
                <ul class="child-list">
                    <?php foreach ($applied as $a): ?>
                        <li><a href="<?= e(url('portal/obligation.php', ['id' => $a['obligation_id']])) ?>"><?= e($a['obligation_number']) ?></a>
                            <span><?= e(format_money($a['allocated_amount'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>

<section class="panel subpanel">
    <header class="panel__header"><h3><?= icon('history') ?> History</h3></header>
    <?php if (!$history): ?>
        <p class="empty-inline">No history yet.</p>
    <?php else: ?>
        <ul class="activity-list">
            <?php foreach ($history as $h): ?>
                <li>
                    <span class="activity-list__dot" aria-hidden="true"></span>
                    <div>
                        <p><strong><?= e(humanize($h['action'])) ?></strong> → <?= badge($h['to_status']) ?>
                            <span class="text-muted">by <?= $h['performed_by'] === $currentUser['id'] ? 'you' : 'the owner' ?></span></p>
                        <?php if ($h['reason']): ?><p class="text-muted"><?= e($h['reason']) ?></p><?php endif; ?>
                        <p class="text-muted"><?= e(format_datetime($h['performed_at'])) ?></p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
