<?php
/**
 * Borrower Payments - payment details, review panel, allocations, proof
 * attachments and the verification history.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/payment_functions.php';

$currentUser = require_admin();

$payment = find_or_redirect(payment_find(request_id()), 'pages/borrower_payments/index.php', 'Payment');
$id = $payment['id'];
$unallocated = (float) $payment['amount'] - (float) $payment['allocated'];

$verification = db_row('SELECT * FROM payment_verifications WHERE borrower_payment_id = :id', [':id' => $id]);
$logs = db_all(
    "SELECT l.*, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email) AS performed_by_name
       FROM payment_verification_logs l JOIN users u ON u.id = l.performed_by
      WHERE l.borrower_payment_id = :id ORDER BY l.performed_at",
    [':id' => $id]
);
$allocations = db_all(
    'SELECT a.allocated_amount, a.allocation_type, a.created_at, o.id AS obligation_id, o.obligation_number, s.installment_number
       FROM borrower_payment_allocations a
       JOIN borrower_obligations o ON o.id = a.obligation_id
       LEFT JOIN installment_schedules s ON s.id = a.installment_schedule_id
      WHERE a.borrower_payment_id = :id ORDER BY a.created_at',
    [':id' => $id]
);
$attachments = db_all(
    'SELECT pa.id AS link_id, pa.attachment_type AS type_label, d.id AS document_id, d.original_filename, d.document_type, d.metadata
       FROM payment_attachments pa JOIN documents d ON d.id = pa.document_id
      WHERE pa.borrower_payment_id = :id ORDER BY pa.created_at',
    [':id' => $id]
);

$selections = payment_selections($id);
$selectedTotal = array_sum(array_map(static fn ($x) => (float) $x['amount'], $selections));
$decisions = PAYMENT_TRANSITIONS[$payment['status']] ?? [];
// A repayment of a card purchase is applied to that purchase by the borrower's split, not allocated here.
$purchase = $payment['transaction_id']
    ? db_row('SELECT t.id, t.transaction_number, m.merchant_name FROM transactions t LEFT JOIN merchants m ON m.id = t.merchant_id WHERE t.id = :t', [':t' => $payment['transaction_id']])
    : null;
$canAllocate = !$purchase && $payment['status'] === 'approved' && $unallocated > 0.004;
[$obligationOptions, $scheduleOptions, $scheduleData] = $canAllocate ? payment_allocation_choices($payment['borrower_id']) : [[], [], []];
$related = url('pages/borrower_payments/related.php');

$pageTitle = 'Borrower Payment';
$activeNav = 'borrower_payments';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Borrower Payments' => url('pages/borrower_payments/index.php'), $payment['payment_number'] => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Payment Acknowledgement — <?= e($payment['payment_number']) ?></h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($payment['payment_number']) ?> · <?= e(format_money($payment['amount'])) ?></h2>
        <p class="page-header__subtitle"><?= e(borrower_name($payment)) ?> · <?= e(format_date($payment['payment_date'])) ?> · <?= badge($payment['status']) ?> <?= badge($payment['allocation_status']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/borrower_payments/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <?php if (user_is_admin($currentUser) && $payment['status'] !== 'cancelled'): ?>
            <a data-modal-form href="<?= e(url('pages/borrower_payments/void.php', ['id' => $id])) ?>" class="btn btn--outline btn--danger-outline"><?= icon('trash') ?> <span>Delete</span></a>
        <?php endif; ?>
        <?php /* No Edit / Override: the borrower submits the amount; here it is only approved or rejected (bottom right). */ ?>
    </div>
</section>


<?php if (!$purchase): ?>
<section class="figure-grid">
    <div class="figure"><p class="figure__label">Amount</p><p class="figure__value"><?= e(format_money($payment['amount'])) ?></p></div>
    <div class="figure"><p class="figure__label">Allocated</p><p class="figure__value"><?= e(format_money($payment['allocated'])) ?></p></div>
    <div class="figure"><p class="figure__label">Unallocated</p><p class="figure__value"><?= e(format_money($unallocated)) ?></p></div>
</section>
<?php endif; ?>

<?php if ($purchase): ?>
<?php $parts = borrowed_parts($id); $rest = round((float) $payment['amount'] - array_sum(array_column($parts, 'amount')), 2); ?>
<section class="panel subpanel">
    <header class="panel__header"><h3><?= icon('credit-card') ?> Repayment of card purchase <?= e($purchase['transaction_number']) ?></h3>
</header>
    <p><a href="<?= e(url('pages/transactions/view.php', ['id' => $purchase['id']])) ?>"><?= e($purchase['merchant_name'] ?: $purchase['transaction_number']) ?></a></p>
    <div class="table-wrap">
        <table class="table table--cards table--compact">
            <thead><tr><th scope="col">The borrower chose to pay</th><th scope="col" class="num">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($parts as $part): ?>
                <tr><td data-label="Charge"><?= e($part['label']) ?></td><td data-label="Amount" class="num"><?= e(format_money($part['amount'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if ($rest > 0.004): ?>
                <tr><td data-label="Charge" class="text-muted"><?= $parts ? 'The rest — oldest unpaid installment first' : 'No row chosen — oldest unpaid installment first' ?></td><td data-label="Amount" class="num"><?= e(format_money($rest)) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="field-help">The borrower chooses where the payment goes. Once confirmed it is applied to the purchase exactly as listed; there is nothing to allocate by hand.</p>
</section>
<?php endif; ?>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Payment details</h3></header>
        <?= render_details([
            'Payment number'    => e($payment['payment_number']),
            'Borrower'          => related_link($payment['borrower_id'], borrower_name($payment) . ' (' . $payment['borrower_number'] . ')', 'pages/borrowers/view.php'),
            'Payment date'      => e(format_date($payment['payment_date'])),
            'Amount'            => '<strong>' . e(format_money($payment['amount'])) . '</strong>',
            'Payment method'    => related_link($payment['payment_method_id'], $payment['method_name'], 'pages/payment_methods/view.php'),
            'Reference number'  => show($payment['reference_number']),
            'Notes'             => show_multiline($payment['notes']),
            'Status'            => badge($payment['status']),
            'Allocation'        => badge($payment['allocation_status']),
            'Submitted'         => e(format_datetime($payment['submitted_at'])),
            'Reviewed'          => $payment['reviewed_at'] ? e(format_datetime($payment['reviewed_at'])) . ($payment['reviewer_name'] ? ' by ' . e($payment['reviewer_name']) : '') : '',
            'Approved'          => show($payment['approved_at'] ? format_datetime($payment['approved_at']) : null),
            'Rejected'          => show($payment['rejected_at'] ? format_datetime($payment['rejected_at']) : null),
            'Rejection reason'  => show_multiline($payment['rejection_reason']),
            'Correction reason' => show_multiline($payment['correction_reason']),
        ]) ?>
    </section>

    <div class="detail-side">

        <section class="panel">
            <header class="panel__header"><h3>Latest verification</h3></header>
            <?php if (!$verification): ?>
                <p class="empty-inline">Not reviewed yet.</p>
            <?php else: ?>
                <?= render_details([
                    'Decision'        => badge($verification['decision']),
                    'Verified amount' => show_money($verification['verified_amount']),
                    'Reference'       => (int) $verification['reference_verified'] === 1 ? 'Verified' : 'Not verified',
                    'Proof'           => (int) $verification['proof_verified'] === 1 ? 'Checked' : 'Not checked',
                    'Payment date'    => (int) $verification['payment_date_verified'] === 1 ? 'Verified' : 'Not verified',
                    'Reason'          => show_multiline($verification['reason']),
                    'Reviewed'        => e(format_datetime($verification['reviewed_at'])),
                ]) ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php if (!$purchase): ?>
<section class="panel subpanel">
    <header class="panel__header"><h3><?= icon('check') ?> What the borrower wants to pay</h3></header>
    <?php if (!$selections): ?>
        <p class="empty-inline">The borrower did not choose any item. Post the whole amount with Allocate below.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Item</th><th scope="col">Installment</th><th scope="col" class="num">Amount chosen</th></tr></thead>
                <tbody>
                <?php foreach ($selections as $sel): ?>
                    <tr>
                        <td data-label="Item"><a href="<?= e(url('pages/obligations/view.php', ['id' => $sel['obligation_id']])) ?>"><?= e($sel['obligation_number']) ?></a>
                            <?= $sel['description'] ? '<br><span class="text-muted">' . e(excerpt($sel['description'], 60)) . '</span>' : '' ?></td>
                        <td data-label="Installment"><?= $sel['installment_number'] ? '#' . e($sel['installment_number']) . ' · due ' . e(format_date($sel['installment_due'])) : '<span class="text-muted">Whole item / late charges</span>' ?></td>
                        <td data-label="Amount chosen" class="num"><?= e(format_money($sel['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="2">Chosen · not assigned by the borrower: <?= e(format_money(max(0, (float) $payment['amount'] - $selectedTotal))) ?></td><td class="num"><?= e(format_money($selectedTotal)) ?></td></tr></tfoot>
            </table>
        </div>
    <?php endif; ?>
    <p class="field-help">On approval the payment is applied as chosen (each item: late charges first, then interest, then principal). Anything not chosen stays unallocated for you to post below.</p>
</section>

<section class="panel subpanel">
    <header class="panel__header">
        <h3><?= icon('file-text') ?> Allocations to obligations</h3>
        <?php if ($canAllocate && $obligationOptions): ?>
            <div class="action-bar no-print">
                <button type="button" class="btn btn--sm btn--primary" data-toggle-panel="allocate-form"><?= icon('plus') ?> <span>Allocate</span></button>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($payment['status'] !== 'approved'): ?>
        <p class="field-help">Payments can be allocated only after they are approved.</p>
    <?php elseif ($canAllocate && !$obligationOptions): ?>
        <p class="field-help">This borrower has no open obligations to apply the remaining <?= e(format_money($unallocated)) ?> to.</p>
    <?php endif; ?>

    <?php if ($canAllocate && $obligationOptions): ?>
        <form method="post" action="<?= e($related) ?>" class="inline-panel no-print" id="allocate-form" hidden novalidate data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($id) ?>">
            <input type="hidden" name="action" value="allocate">
            <?= render_fields([
                'obligation_id'           => ['label' => 'Obligation', 'type' => 'select', 'options' => $obligationOptions, 'required' => true, 'empty_label' => 'Select obligation'],
                'installment_schedule_id' => ['label' => 'Installment (optional)', 'type' => 'select', 'options' => $scheduleOptions, 'option_data' => $scheduleData,
                                              'attrs' => ['data-depends-on' => 'f_obligation_id'], 'empty_label' => '— Whole obligation —'],
                'allocated_amount'        => ['label' => 'Amount', 'type' => 'decimal', 'required' => true,
                                              'help' => 'Applied in this order: unpaid penalties (oldest first), then interest and fees, then principal.'],
            ], ['allocated_amount' => number_format($unallocated, 2, '.', '')], []) ?>
            <div class="inline-panel__actions">
                <button type="button" class="btn btn--outline" data-toggle-panel="allocate-form">Cancel</button>
                <button type="submit" class="btn btn--primary" data-loading-text="Applying…"><?= icon('check') ?> <span class="btn__label">Apply payment</span></button>
            </div>
        </form>
    <?php endif; ?>

    <?php if (!$allocations): ?>
        <p class="empty-inline">Nothing allocated yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Obligation</th><th scope="col">Installment</th><th scope="col">Type</th><th scope="col">Date</th><th scope="col" class="num">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($allocations as $a): ?>
                    <tr>
                        <td data-label="Obligation"><a href="<?= e(url('pages/obligations/view.php', ['id' => $a['obligation_id']])) ?>"><?= e($a['obligation_number']) ?></a></td>
                        <td data-label="Installment"><?= $a['installment_number'] ? '#' . e($a['installment_number']) : '<span class="text-muted">—</span>' ?></td>
                        <td data-label="Type"><?= e(humanize($a['allocation_type'])) ?></td>
                        <td data-label="Date"><?= e(format_datetime($a['created_at'])) ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($a['allocated_amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php endif; ?>

<?= render_attachments_panel($attachments, $related, ['id' => $id], true, $payment['status'] !== 'cancelled') ?>

<section class="panel subpanel">
    <header class="panel__header"><h3><?= icon('history') ?> Verification history</h3></header>
    <?php if (!$logs): ?>
        <p class="empty-inline">No history.</p>
    <?php else: ?>
        <ul class="activity-list">
            <?php foreach ($logs as $log): ?>
                <li><span class="activity-list__dot" aria-hidden="true"></span>
                    <div>
                        <p><strong><?= e($log['performed_by_name']) ?></strong> · <?= e(humanize($log['action'])) ?>:
                            <?= $log['from_status'] ? badge($log['from_status']) . ' → ' : '' ?><?= badge($log['to_status']) ?></p>
                        <?php if ($log['reason']): ?><p class="text-muted"><?= e($log['reason']) ?></p><?php endif; ?>
                        <time class="text-muted"><?= e(format_datetime($log['performed_at'])) ?></time>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if (in_array('approved', $decisions, true)): ?>
    <?= decision_bar([url('pages/borrower_payments/review.php'), ['id' => $id, 'decision' => 'approved'],
        'Approve payment ' . $payment['payment_number'] . ' of ' . format_money($payment['amount']) . '? It is applied as the borrower chose and the borrower is told.', 'Approve payment?'],
        'payment', ['id' => $id]) ?>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
