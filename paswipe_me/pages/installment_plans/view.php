<?php
/**
 * Installment Plans - plan terms and payment schedule.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/plan_functions.php';

$currentUser = require_admin();

$plan = find_or_redirect(plan_find(request_id()), 'pages/installment_plans/index.php', 'Installment plan');
$schedule = db_all('SELECT * FROM installment_schedules WHERE installment_plan_id = :id ORDER BY installment_number', [':id' => $plan['id']]);
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
        <a href="<?= e(url('pages/installment_plans/edit.php', ['id' => $plan['id']])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
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
    <p class="field-help no-print">Statuses follow today's date (unpaid and past due = Overdue). A borrower's approved payment is applied here automatically, oldest installment first — use Pay only for an amount nobody recorded as a borrower payment.</p>
    <?= render_schedule_table($schedule, url('pages/installment_plans/related.php'), ['id' => $plan['id']], $plan['status'] === 'active', true) ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
