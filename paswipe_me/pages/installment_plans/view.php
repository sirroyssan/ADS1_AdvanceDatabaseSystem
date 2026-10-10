<?php
/**
 * Installment Plans - plan terms and payment schedule.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/plan_functions.php';

$currentUser = require_admin();

$plan = find_or_redirect(plan_find(request_id()), 'pages/installment_plans/index.php', 'Installment plan');
$schedule = db_all('SELECT * FROM installment_schedules WHERE installment_plan_id = :id ORDER BY installment_number', [':id' => $plan['id']]);
// Bank installments: which card statement bills each one (by its due date).
$isBankPlan = db_value('SELECT payment_type FROM transactions WHERE id = :t', [':t' => $plan['transaction_id']]) === 'installment';
if ($isBankPlan) {
    statement_sync_card($plan['credit_card_id']);
}
$locked = plan_is_locked($plan['id']);
$remaining = array_sum(array_map(static fn ($r) => in_array($r['status'], ['waived', 'cancelled'], true) ? 0 : (float) $r['remaining_amount'], $schedule));

$pageTitle = 'Installment Plan';
$activeNav = 'installments';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Installment Plans' => url('pages/installment_plans/index.php'), $plan['transaction_number'] => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Installment Schedule — <?= e($plan['transaction_number']) ?></h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($plan['number_of_installments']) ?> × <?= e(format_money($plan['installment_amount'])) ?></h2>
        <p class="page-header__subtitle"><?= e(humanize($plan['frequency'])) ?> · <?= e(format_date($plan['first_due_date'])) ?> to <?= e(format_date($plan['end_date'])) ?> · <?= badge($plan['status']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/installment_plans/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print schedule</span></button>
        <a data-modal-form href="<?= e(url('pages/installment_plans/edit.php', ['id' => $plan['id']])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
    </div>
</section>

<section class="figure-grid">
    <div class="figure"><p class="figure__label">Total repayment</p><p class="figure__value"><?= e(format_money($plan['total_repayment'])) ?></p></div>
    <div class="figure"><p class="figure__label">Paid</p><p class="figure__value"><?= e(format_money($plan['total_paid'])) ?></p></div>
    <div class="figure"><p class="figure__label">Remaining</p><p class="figure__value"><?= e(format_money($remaining)) ?></p></div>
    <div class="figure"><p class="figure__label">Transaction amount</p><p class="figure__value"><?= e(format_money($plan['transaction_amount'])) ?></p></div>
</section>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Plan terms</h3></header>
        <?= render_details([
            'Transaction'            => related_link($plan['transaction_id'], $plan['transaction_number'], 'pages/transactions/view.php'),
            'Card'                   => related_link($plan['credit_card_id'], $plan['card_nickname'] . ' •••• ' . $plan['last_four'], 'pages/credit_cards/view.php'),
            'Borrower'               => $plan['borrower_id'] ? related_link($plan['borrower_id'], borrower_name($plan), 'pages/borrowers/view.php') : 'Owner',
            'Total principal'        => e(format_money($plan['total_principal'])),
            'Interest amount'        => e(format_money($plan['interest_amount'])),
            'Fees amount'            => e(format_money($plan['fees_amount'])),
            'Total repayment'        => '<strong>' . e(format_money($plan['total_repayment'])) . '</strong>',
            'Installments'           => e($plan['number_of_installments']) . ' × ' . e(format_money($plan['installment_amount'])),
            'Frequency'              => e(humanize($plan['frequency'])),
            'Start date'             => e(format_date($plan['start_date'])),
            'First due date'         => e(format_date($plan['first_due_date'])),
            'End date'               => e(format_date($plan['end_date'])),
            'Status'                 => badge($plan['status']),
            'Created'                => e(format_datetime($plan['created_at'])),
            'Last updated'           => e(format_datetime($plan['updated_at'])),
        ]) ?>
    </section>

    <div class="detail-side">
        <section class="panel no-print">
            <header class="panel__header"><h3>About this schedule</h3></header>
            <p class="field-help">Use <strong>Pay</strong> on a row to record what was paid to the bank for that installment; the plan becomes Completed when every row is paid or waived.</p>
            <p class="field-help">Borrower repayments are recorded separately in Borrower Payments.</p>
        </section>
        <section class="panel panel--danger no-print">
            <header class="panel__header"><h3>Delete plan</h3></header>
            <?php if ($locked): ?>
                <p class="field-help">Payments are recorded on this schedule, so the plan is kept. Set its status to Cancelled instead.</p>
            <?php else: ?>
                <p class="field-help">No payments yet. Deleting removes the plan and its schedule.</p>
                <?= post_button(url('pages/installment_plans/delete.php'), ['id' => $plan['id']], 'Delete plan', 'trash', 'btn btn--danger btn--block',
                    'Delete this installment plan and its schedule? This cannot be undone.') ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<section class="panel subpanel">
    <header class="panel__header"><h3><?= icon('calendar') ?> Payment schedule</h3></header>
    <p class="field-help no-print">Updated automatically from the borrower's approved payments (oldest installment first); statuses follow today's date. The amount paid toward the bank installments is recorded as a card payment automatically.</p>
    <?= render_schedule_table($schedule, '', [], false) ?>
</section>

<?php if ($isBankPlan && $schedule): ?>
    <section class="panel subpanel">
        <header class="panel__header"><h3><?= icon('layers') ?> Billing on the card statements</h3></header>
        <p class="field-help">Each installment is billed once, on the statement whose period contains its due date. Changing the plan regenerates the schedule rows, so no charge is billed twice.</p>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col" class="num">#</th><th scope="col">Billing date</th><th scope="col" class="num">Installment charge</th><th scope="col">Statement</th><th scope="col" class="num">Not billed yet after it</th></tr></thead>
                <tbody>
                <?php $left = array_sum(array_map(static fn ($r) => in_array($r['status'], ['waived', 'cancelled'], true) ? 0 : (float) $r['amount_due'], $schedule)); ?>
                <?php foreach ($schedule as $r): if (in_array($r['status'], ['waived', 'cancelled'], true)) { continue; } $left -= (float) $r['amount_due']; $where = statement_for_date($plan['credit_card_id'], $r['due_date']); ?>
                    <tr>
                        <td data-label="#" class="num"><?= e($r['installment_number']) ?> of <?= e($plan['number_of_installments']) ?></td>
                        <td data-label="Billing date"><?= e(format_date($r['due_date'])) ?></td>
                        <td data-label="Installment charge" class="num"><?= e(format_money($r['amount_due'])) ?></td>
                        <td data-label="Statement"><?= $where['statement']
                            ? '<a href="' . e(url('pages/statements/view.php', ['id' => $where['statement']['id']])) . '">Cut off ' . e(format_date($where['statement']['cutoff_date'] ?: $where['statement']['statement_date'])) . '</a>'
                            : '<span class="text-muted">' . e(ucfirst($where['label'])) . '</span>' ?></td>
                        <td data-label="Not billed yet after it" class="num"><?= e(format_money(max(0, $left))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
