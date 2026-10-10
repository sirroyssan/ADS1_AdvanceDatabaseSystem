<?php
/**
 * Obligations - balance, payments applied, penalties and status actions.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/obligation_functions.php';

$currentUser = require_admin();

$obligation = find_or_redirect(obligation_find(request_id()), 'pages/obligations/index.php', 'Obligation');
$id = $obligation['id'];

$allocations = db_all(
    'SELECT a.allocated_amount, a.allocation_type, a.created_at, p.id AS payment_id, p.payment_number, p.payment_date
       FROM borrower_payment_allocations a JOIN borrower_payments p ON p.id = a.borrower_payment_id
      WHERE a.obligation_id = :id ORDER BY a.created_at',
    [':id' => $id]
);
[$schedule, $scheduleTitle, $scheduleNote] = obligation_schedule_view($obligation);
$penalties = db_all(
    'SELECT ap.*, r.name AS rule_name FROM applied_penalties ap JOIN penalty_rules r ON r.id = ap.penalty_rule_id
      WHERE ap.obligation_id = :id ORDER BY ap.applied_date',
    [':id' => $id]
);
$unpaidPenalties = array_sum(array_column($penalties, 'remaining_amount'));
$assignedRules = penalty_rules_for_obligation($obligation);
$obligationIssuer = obligation_card_issuer($obligation);
$selectedPenalty = input_string($_GET, 'penalty');
$terminable = array_filter($penalties, static fn ($p) => (float) $p['remaining_amount'] > 0.004 || (float) $p['paid_amount'] <= 0.004);
$terminateOptions = [];
foreach ($terminable as $p) {
    $terminateOptions[$p['id']] = format_date($p['applied_date']) . ' — ' . $p['rule_name'] . ' — ' . format_money($p['remaining_amount']) . ' unpaid';
}
$isClosed = in_array($obligation['status'], ['cancelled', 'written_off', 'paid'], true);
$daysLate = $obligation['due_date'] && $obligation['due_date'] < date('Y-m-d') ? (int) ((strtotime(date('Y-m-d')) - strtotime($obligation['due_date'])) / 86400) : 0;

$statusChoices = array_values(array_diff(OBLIGATION_MANUAL_STATUSES, [$obligation['status']]));
if ((float) $obligation['total_paid'] > 0) {
    $statusChoices = array_values(array_diff($statusChoices, ['cancelled', 'pending']));
}

$related = url('pages/obligations/related.php');
$penaltyDefaults = form_defaults(penalty_fields(), ['calculation_base' => $obligation['outstanding_amount'], 'overdue_days' => $daysLate]);

$pageTitle = 'Obligation Details';
$activeNav = 'obligations';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Obligations' => url('pages/obligations/index.php'), $obligation['obligation_number'] => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Statement of Account — <?= e($obligation['obligation_number']) ?></h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($obligation['obligation_number']) ?></h2>
        <p class="page-header__subtitle"><?= e(borrower_name($obligation)) ?> · <?= badge($obligation['status']) ?><?= $daysLate > 0 && !$isClosed ? ' · <span class="text-danger">' . e($daysLate) . ' day(s) past due</span>' : '' ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/obligations/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <?php if (!$isClosed): ?>
            <a href="<?= e(url('pages/borrower_payments/create.php', ['borrower_id' => $obligation['borrower_id']])) ?>" class="btn btn--outline"><?= icon('receipt') ?> <span>Record payment</span></a>
            <a href="<?= e(url('pages/obligations/edit.php', ['id' => $id])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
        <?php endif; ?>
    </div>
</section>

<section class="figure-grid">
    <div class="figure"><p class="figure__label">Total due</p><p class="figure__value"><?= e(format_money($obligation['total_amount_due'])) ?></p></div>
    <div class="figure"><p class="figure__label">Paid</p><p class="figure__value text-success"><?= e(format_money($obligation['total_paid'])) ?></p></div>
    <div class="figure"><p class="figure__label">Outstanding</p><p class="figure__value"><?= e(format_money($obligation['outstanding_amount'])) ?></p></div>
    <div class="figure"><p class="figure__label">Overdue</p><p class="figure__value<?= (float) $obligation['overdue_amount'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($obligation['overdue_amount'])) ?></p></div>
    <div class="figure"><p class="figure__label">Unpaid penalties</p><p class="figure__value"><?= e(format_money($unpaidPenalties)) ?></p></div>
    <div class="figure"><p class="figure__label">Total to collect</p><p class="figure__value"><?= e(format_money((float) $obligation['outstanding_amount'] + $unpaidPenalties)) ?></p></div>
</section>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Obligation details</h3></header>
        <?= render_details([
            'Obligation number' => e($obligation['obligation_number']),
            'Borrower'          => related_link($obligation['borrower_id'], borrower_name($obligation) . ' (' . $obligation['borrower_number'] . ')', 'pages/borrowers/view.php'),
            'Transaction'       => related_link($obligation['transaction_id'], $obligation['transaction_number'], 'pages/transactions/view.php'),
            'Cash loan'         => related_link($obligation['cash_loan_id'], $obligation['loan_reference'], 'pages/cash_loans/view.php'),
            'Description'       => show($obligation['description']),
            'Obligation date'   => e(format_date($obligation['obligation_date'])),
            'Due date'          => e(format_date($obligation['due_date'])),
            'Original amount'   => e(format_money($obligation['original_amount'])),
            'Agreed interest'   => e(format_money($obligation['agreed_interest'])),
            'Agreed fees'       => e(format_money($obligation['agreed_fees'])),
            'Total amount due'  => '<strong>' . e(format_money($obligation['total_amount_due'])) . '</strong>',
            'Total paid'        => e(format_money($obligation['total_paid'])),
            'Outstanding'       => '<strong>' . e(format_money($obligation['outstanding_amount'])) . '</strong>',
            'Overdue amount'    => e(format_money($obligation['overdue_amount'])),
            'Status'            => badge($obligation['status']),
            'Completed'         => show($obligation['completed_at'] ? format_datetime($obligation['completed_at']) : null),
            'Notes'             => show_multiline($obligation['notes']),
            'Created'           => e(format_datetime($obligation['created_at'])),
            'Last updated'      => e(format_datetime($obligation['updated_at'])),
        ]) ?>
    </section>

    <div class="detail-side">
        <?php if ($statusChoices && $obligation['status'] !== 'paid'): ?>
            <section class="panel no-print">
                <header class="panel__header"><h3>Change status</h3></header>
                <form method="post" action="<?= e($related) ?>" class="status-form" data-confirm="Change the status of this obligation?" data-confirm-title="Change status?" data-confirm-button="Change status">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($id) ?>">
                    <input type="hidden" name="action" value="status">
                    <div class="form-group">
                        <label for="obl_status">New status</label>
                        <select id="obl_status" name="status" required>
                            <?php foreach ($statusChoices as $s): ?><option value="<?= e($s) ?>"><?= e(humanize($s)) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn--primary"><?= icon('check') ?> <span class="btn__label">Apply</span></button>
                </form>
                <p class="field-help">Cancel is only possible before any payment. Obligations are never deleted.</p>
            </section>
        <?php endif; ?>

        <section class="panel">
            <header class="panel__header"><h3>Payments applied</h3></header>
            <?php if (!$allocations): ?>
                <p class="empty-inline">No payments applied yet.</p>
            <?php else: ?>
                <ul class="child-list">
                    <?php foreach ($allocations as $a): ?>
                        <li><a href="<?= e(url('pages/borrower_payments/view.php', ['id' => $a['payment_id']])) ?>"><?= e($a['payment_number']) ?></a>
                            <span><?= e(format_date($a['payment_date'])) ?> · <strong><?= e(format_money($a['allocated_amount'])) ?></strong></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php if ($schedule): ?>
    <section class="panel subpanel">
        <header class="panel__header"><h3><?= icon('calendar') ?> <?= e($scheduleTitle) ?></h3></header>
        <?php if ($scheduleNote): ?><p class="field-help"><?= e(str_replace('your share', "this borrower's share", $scheduleNote)) ?></p><?php endif; ?>
        <?= render_schedule_table($schedule, '', [], false) ?>
    </section>
<?php endif; ?>

<section class="panel subpanel">
    <header class="panel__header">
        <h3><?= icon('scale') ?> Penalties</h3>
        <?php if (!$isClosed): ?>
            <div class="action-bar no-print">
                <?= post_button($related, ['id' => $id, 'action' => 'run_penalties'], 'Check penalties now', 'repeat', 'btn btn--sm btn--outline') ?>
                <button type="button" class="btn btn--sm btn--outline" data-toggle-panel="add-penalty"><?= icon('plus') ?> <span>Apply penalty</span></button>
            </div>
        <?php endif; ?>
    </header>
    <div class="penalty-rules-note">
        <strong>Penalty rules assigned</strong> (<?= $obligationIssuer ? 'active rules for ' . e($obligationIssuer) : 'this obligation is not linked to a card' ?>):
        <?php if (!$assignedRules): ?>
            <span class="text-muted">none<?= $obligationIssuer ? ' — <a href="' . e(url('pages/penalty_rules/index.php')) . '">set a rule\'s Bank to ' . e($obligationIssuer) . '</a>' : '' ?>.</span>
        <?php else: ?>
            <ul>
                <?php foreach ($assignedRules as $r): ?>
                    <li><?= e($r['name']) ?> — <?= e(penalty_rule_summary($r)) ?> · charges from <?= e(format_date($r['charges_from'])) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php if (!$isClosed): ?>
        <form method="post" action="<?= e($related) ?>" class="inline-panel no-print" id="add-penalty" hidden novalidate data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($id) ?>">
            <input type="hidden" name="action" value="add_penalty">
            <?php if (!penalty_rule_options()): ?>
                <p class="field-help">No active penalty rules. <a href="<?= e(url('pages/penalty_rules/create.php')) ?>">Create a rule</a> first.</p>
            <?php else: ?>
                <?= render_fields(penalty_fields(), $penaltyDefaults, []) ?>
                <div class="inline-panel__actions">
                    <button type="button" class="btn btn--outline" data-toggle-panel="add-penalty">Cancel</button>
                    <button type="submit" class="btn btn--primary" data-loading-text="Saving…"><?= icon('check') ?> <span class="btn__label">Apply penalty</span></button>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
    <?php if (!$penalties): ?>
        <p class="empty-inline">No penalties applied.</p>
    <?php else: ?>
        <form method="post" action="<?= e($related) ?>" id="terminate-penalty" data-bulk-form novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($id) ?>">
            <input type="hidden" name="action" value="terminate_penalties">
            <div class="table-wrap">
                <table class="table table--cards table--compact">
                    <thead><tr>
                        <th scope="col" class="col-check no-print"><?php if ($terminateOptions): ?><input type="checkbox" data-check-all aria-label="Select all penalties that can be waived or deleted"><?php endif; ?></th>
                        <th scope="col">Date</th><th scope="col">Rule</th><th scope="col" class="num">Days late</th><th scope="col" class="num">Base</th><th scope="col" class="num">Amount</th><th scope="col" class="num">Paid</th><th scope="col" class="num">Remaining</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($penalties as $p): ?>
                        <tr>
                            <td class="col-check no-print">
                                <?php if (isset($terminateOptions[$p['id']])): ?>
                                    <input type="checkbox" name="item_ids[]" value="<?= e($p['id']) ?>" data-check-item <?= $selectedPenalty === $p['id'] ? 'checked' : '' ?>
                                           aria-label="Select penalty of <?= e(format_date($p['applied_date'])) ?>, <?= e($p['rule_name']) ?>">
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
            <?php if ($terminateOptions): ?>
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
    <p class="field-help">Penalties are charged automatically every month while the obligation is overdue. Payments pay penalties first (oldest first), then interest and fees, then principal.</p>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
