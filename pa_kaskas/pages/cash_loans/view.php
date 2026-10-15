<?php
/**
 * Cash Loans - loan details, repayment schedule and borrower allocations.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/cash_loan_functions.php';

$currentUser = require_admin();

$loan = find_or_redirect(cash_loan_find(request_id()), 'pages/cash_loans/index.php', 'Cash loan');
$id = $loan['id'];

$schedule = db_all('SELECT * FROM cash_loan_schedules WHERE cash_loan_id = :id ORDER BY installment_number', [':id' => $id]);
$allocations = db_all(
    'SELECT a.*, b.first_name, b.last_name, o.obligation_number, o.outstanding_amount, o.status AS obligation_status
       FROM cash_loan_allocations a
       LEFT JOIN borrowers b ON b.id = a.borrower_id
       LEFT JOIN borrower_obligations o ON o.id = a.obligation_id
      WHERE a.cash_loan_id = :id ORDER BY a.created_at',
    [':id' => $id]
);
$distributable = cash_loan_distributable($loan);
$charge = cash_loan_card_charge($loan);
$cardUsage = card_usage($loan['credit_card_id']);
$isOpen = !in_array($loan['status'], ['cancelled', 'rejected', 'completed'], true);
$canGenerate = !$schedule && $loan['term_months'] && $loan['first_due_date'] && (float) $loan['total_repayment'] > 0;
$related = url('pages/cash_loans/related.php');
// The bank's monthly installment: the first scheduled one, else the loan's monthly payment.
$bankInstallment = $schedule ? (float) $schedule[0]['amount_due'] : ($loan['monthly_payment'] !== null ? (float) $loan['monthly_payment'] : null);
$monthlyTotal = ['principal' => 0.0, 'bank_share' => 0.0, 'owed' => 0.0];
foreach ($allocations as $i => $a) {
    $allocations[$i]['monthly'] = allocation_monthly($loan, (float) $a['allocated_amount'], $bankInstallment);
    if ($allocations[$i]['monthly']) {
        $monthlyTotal['principal'] += $allocations[$i]['monthly']['principal'];
        $monthlyTotal['bank_share'] += (float) $allocations[$i]['monthly']['bank_share'];
        if (in_array($a['allocation_type'], CASH_LOAN_DEBT_TYPES, true)) {
            $monthlyTotal['owed'] += $allocations[$i]['monthly']['principal'];
        }
    }
}

$pageTitle = 'Cash Loan';
$activeNav = 'cash_loans';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Cash Loans' => url('pages/cash_loans/index.php'), $loan['loan_reference'] => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Cash Loan <?= e($loan['loan_reference']) ?></h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<?php $voidable = $allocations || array_filter($schedule, static fn ($s) => (float) $s['amount_paid'] > 0) || !in_array($loan['status'], ['offered', 'rejected', 'cancelled'], true); ?>
<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($loan['loan_reference']) ?></h2>
        <p class="page-header__subtitle"><?= e($loan['card_nickname']) ?> •••• <?= e($loan['last_four']) ?> · <?= badge($loan['status']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/cash_loans/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <?php if ($voidable && $loan['status'] !== 'cancelled'): ?>
            <a data-modal-form class="btn btn--danger" href="<?= e(url('pages/cash_loans/void.php', ['id' => $id])) ?>"><?= icon('trash') ?> <span>Void cash loan</span></a>
        <?php elseif (!$voidable): ?>
            <?= post_button(url('pages/cash_loans/delete.php'), ['id' => $id], 'Delete loan', 'trash', 'btn btn--danger',
                'Delete cash loan ' . $loan['loan_reference'] . '? This cannot be undone.') ?>
        <?php endif; ?>
        <a data-modal-form href="<?= e(url('pages/cash_loans/edit.php', ['id' => $id])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
        <p class="page-header__note field-help"><?php if (!$voidable): ?>This offer has no allocations or payments, so it can be deleted.<?php elseif ($loan['status'] === 'cancelled'): ?>This loan is cancelled. It no longer counts on the card, its statements or any balance.<?php else: ?>Recorded by mistake (e.g. entered twice)? Void it: the loan, its schedule and its borrowers' obligations are cancelled with your reason and stop counting everywhere. Nothing is erased.<?php endif; ?></p>
    </div>
</section>

<section class="figure-grid">
    <div class="figure"><p class="figure__label">Accepted</p><p class="figure__value"><?= e(format_money($loan['accepted_amount'])) ?></p></div>
    <div class="figure"><p class="figure__label">Cash received</p><p class="figure__value"><?= e(format_money($loan['actual_cash_received'])) ?></p></div>
    <div class="figure"><p class="figure__label">Total repayment</p><p class="figure__value"><?= e(format_money($loan['total_repayment'])) ?></p></div>
    <div class="figure"><p class="figure__label">Total card charge</p><p class="figure__value"><?= e(format_money($charge['counts'] ? $charge['total'] : 0)) ?></p></div>
    <div class="figure"><p class="figure__label">Allocated to borrowers</p><p class="figure__value"><?= e(format_money($loan['allocated'])) ?></p></div>
    <div class="figure"><p class="figure__label">Not yet allocated</p><p class="figure__value"><?= e(format_money(max(0, $distributable - (float) $loan['allocated']))) ?></p></div>
</section>

<?php if (!$charge['counts']): ?>
    <div class="alert alert--warning" role="status">
        <?= icon('alert') ?>
        <span>This loan is <strong><?= e(humanize($loan['status'])) ?></strong>, so it does not use the card's credit<?= in_array($loan['status'], ['offered'], true) ? '. Edit it and set the status to Accepted (or Disbursed / Active) once you take the loan' : '' ?>.</span>
    </div>
<?php endif; ?>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Loan details</h3></header>
        <?= render_details([
            'Loan reference'       => e($loan['loan_reference']),
            'Offer number'         => show($loan['offer_number']),
            'Card'                 => related_link($loan['credit_card_id'], $loan['card_nickname'] . ' •••• ' . $loan['last_four'], 'pages/credit_cards/view.php'),
            'Requested'            => e(format_money($loan['requested_amount'])),
            'Offered'              => e(format_money($loan['offered_amount'])),
            'Approved'             => e(format_money($loan['approved_amount'])),
            'Accepted'             => e(format_money($loan['accepted_amount'])),
            'Actual cash received' => e(format_money($loan['actual_cash_received'])),
            'Processing fee'       => e(format_money($loan['processing_fee'])),
            'Other fees'           => e(format_money($loan['other_fees'])),
            'Interest rate'        => show($loan['interest_rate'] !== null ? $loan['interest_rate'] . '%' : null),
            'Total repayment'      => '<strong>' . e(format_money($loan['total_repayment'])) . '</strong>',
            'Term'                 => show($loan['term_months'] ? $loan['term_months'] . ' month(s)' : null),
            'Monthly payment'      => show_money($loan['monthly_payment']),
            'Payment frequency'    => show($loan['payment_frequency'] ? humanize($loan['payment_frequency']) : null),
            'Start date'           => show($loan['start_date'] ? format_date($loan['start_date']) : null),
            'First due date'       => show($loan['first_due_date'] ? format_date($loan['first_due_date']) : null),
            'End date'             => show($loan['end_date'] ? format_date($loan['end_date']) : null),
            'Status'               => badge($loan['status']),
            'Notes'                => show_multiline($loan['notes']),
            'Created'              => e(format_datetime($loan['created_at'])),
            'Last updated'         => e(format_datetime($loan['updated_at'])),
        ]) ?>
    </section>

    <div class="detail-side">
        <section class="panel">
            <header class="panel__header"><h3>Charge to <?= e($loan['card_nickname']) ?> •••• <?= e($loan['last_four']) ?></h3></header>
            <dl class="usage-breakdown">
                <div><dt>Principal</dt><dd><?= e(format_money($charge['principal'])) ?></dd></div>
                <div><dt>Interest (total repayment − principal)</dt><dd><?= e(format_money($charge['interest'])) ?></dd></div>
                <div><dt>Fees billed to the card</dt><dd><?= e(format_money($charge['fees'])) ?></dd></div>
                <?php if ($charge['fees_deducted'] > 0): ?>
                    <div><dt>Fees taken from the cash received</dt><dd class="text-muted"><?= e(format_money($charge['fees_deducted'])) ?></dd></div>
                <?php endif; ?>
                <div class="is-total"><dt>Total card charge</dt><dd><?= e(format_money($charge['total'])) ?></dd></div>
                <div><dt>Status</dt><dd><?= $charge['counts'] ? 'Counted in used credit' : 'Not counted' ?></dd></div>
            </dl>
            <p class="field-help">The whole card now: <?= e(format_money($cardUsage['used'])) ?> used of <?= e(format_money($cardUsage['credit_limit'])) ?>,
                <?= e(format_money($cardUsage['available'])) ?> available (<?= e(number_format($cardUsage['utilization'], 2)) ?>%).
                Card payments lower the used credit; tick "Also a card payment" when paying an installment below.</p>
        </section>
    </div>
</div>

<section class="panel subpanel">
    <header class="panel__header">
        <h3><?= icon('users') ?> Who carries the loan</h3>
        <?php if ($isOpen): ?>
            <button type="button" class="btn btn--sm btn--outline no-print" data-modal-panel="add-allocation"><?= icon('plus') ?> <span>Add allocation</span></button>
        <?php endif; ?>
    </header>
    <?php if ($isOpen): ?>
        <form method="post" action="<?= e($related) ?>" class="inline-panel no-print" id="add-allocation" hidden novalidate data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($id) ?>">
            <input type="hidden" name="action" value="add_allocation">
            <?= render_fields(allocation_fields(), form_defaults(allocation_fields(), ['allocated_amount' => number_format(max(0, $distributable - (float) $loan['allocated']), 2, '.', '')]), []) ?>
            <div class="inline-panel__actions">
                <button type="button" class="btn btn--outline" data-toggle-panel="add-allocation">Cancel</button>
                <button type="submit" class="btn btn--primary" data-loading-text="Saving…"><?= icon('check') ?> <span class="btn__label">Save allocation</span></button>
            </div>
        </form>
    <?php endif; ?>
    <p class="field-help">Responsible to the bank: <strong>you, the card owner (account holder)</strong>, for every installment of <?= e($loan['card_nickname']) ?> •••• <?= e($loan['last_four']) ?>.
        The parts below only say who repays you or helps; they do not make anyone a co-borrower of the bank.</p>
    <?php if (!$allocations): ?>
        <p class="empty-inline">The loan has not been split to anyone.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Person</th><th scope="col">Responsibility</th><th scope="col">Description</th><th scope="col" class="num">Amount</th>
                    <th scope="col" class="num">Per month</th><th scope="col" class="num">Share of bank installment</th><th scope="col">Obligation</th><th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($allocations as $a): ?>
                    <?php $isDebt = in_array($a['allocation_type'], CASH_LOAN_DEBT_TYPES, true); ?>
                    <tr>
                        <td data-label="Person"><?= $a['borrower_id'] ? related_link($a['borrower_id'], borrower_name($a), 'pages/borrowers/view.php') : '<span class="text-muted">You (card owner)</span>' ?></td>
                        <td data-label="Responsibility"><?= e(allocation_type_label($a['allocation_type'])) ?></td>
                        <td data-label="Description"><?= show($a['description']) ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($a['allocated_amount'])) ?></td>
                        <td data-label="Per month" class="num"><?php if ($a['monthly']): ?><?= e(format_money($a['monthly']['principal'])) ?>
                            <?php if (abs($a['monthly']['last'] - $a['monthly']['principal']) > 0.004): ?><br><span class="text-muted">last <?= e(format_money($a['monthly']['last'])) ?></span><?php endif; ?>
                            <?php else: ?><span class="text-muted">Set the term</span><?php endif; ?></td>
                        <td data-label="Share of bank installment" class="num"><?= $a['monthly'] && $a['monthly']['bank_share'] !== null ? e(format_money($a['monthly']['bank_share'])) : '<span class="text-muted">—</span>' ?></td>
                        <td data-label="Obligation"><?= $a['obligation_id'] ? related_link($a['obligation_id'], $a['obligation_number'], 'pages/obligations/view.php') . ' ' . badge($a['obligation_status']) : '<span class="text-muted">None</span>' ?></td>
                        <td class="actions no-print"><div class="row-actions">
                            <?php if (!$a['obligation_id'] && $a['borrower_id'] && $isDebt): ?>
                                <?= post_button($related, ['id' => $id, 'action' => 'create_obligation', 'item_id' => $a['id']], 'Create obligation', 'file-text', 'btn btn--sm btn--outline',
                                    'Create a borrower obligation of ' . format_money($a['allocated_amount']) . ' for this allocation?', false, 'Create obligation?') ?>
                            <?php endif; ?>
                            <?php if (!$a['obligation_id']): ?>
                                <?= post_button($related, ['id' => $id, 'action' => 'delete_allocation', 'item_id' => $a['id']], 'Remove allocation', 'trash', 'icon-button icon-button--danger',
                                    'Remove this allocation?', true) ?>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if ($loan['term_months']): ?>
                    <tfoot><tr><th scope="row" colspan="3">Combined per month<?= $monthlyTotal['owed'] > 0 ? ' · owed to you ' . e(format_money($monthlyTotal['owed'])) . ' a month' : '' ?></th>
                        <td class="num"><?= e(format_money($loan['allocated'])) ?></td><td class="num"><strong><?= e(format_money($monthlyTotal['principal'])) ?></strong></td>
                        <td class="num"><?= $bankInstallment !== null ? e(format_money($monthlyTotal['bank_share'])) : '—' ?></td><td colspan="2"></td></tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel subpanel">
    <header class="panel__header">
        <h3><?= icon('calendar') ?> Repayment schedule</h3>
        <?php if ($canGenerate && $isOpen): ?>
            <?= post_button($related, ['id' => $id, 'action' => 'generate_schedule'], 'Generate schedule', 'repeat', 'btn btn--sm btn--primary no-print') ?>
        <?php endif; ?>
        <?php if ($schedule && $isOpen): ?>
            <button type="button" class="btn btn--sm btn--outline no-print" data-modal-panel="shift-dates"
                    data-modal-subtitle="The bank changed the due date? Move every installment to the new monthly due day."><?= icon('calendar') ?> <span>Change due dates</span></button>
        <?php endif; ?>
    </header>
    <?php if ($schedule && $isOpen): ?>
        <?= render_schedule_shift_form($related, $id, $schedule[0]['due_date'], $loan['payment_frequency'], count($schedule)) ?>
    <?php endif; ?>
    <?php if (!$schedule && !$canGenerate): ?>
        <div class="alert alert--warning"><?= icon('alert') ?><span>No repayment schedule yet: <strong>edit the loan and enter the first due date</strong> (and the term and total repayment). Until then the borrowers' shares have no due date and a monthly interest rate counts only one month.</span></div>
    <?php endif; ?>
    <?= render_schedule_table($schedule, '', [], false) ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
