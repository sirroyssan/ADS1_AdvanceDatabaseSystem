<?php
/**
 * Borrower portal - one obligation: what it is for, payments applied,
 * installment schedule (when the purchase is on installment) and charges.
 */

require_once __DIR__ . '/../includes/auth_check.php';

[$currentUser, $borrower] = require_borrower();
$bid = $borrower['id'];

$o = portal_find(
    "SELECT o.*, t.transaction_number, t.transaction_date, t.amount AS transaction_amount, m.merchant_name,
            cl.loan_reference
       FROM borrower_obligations o
       LEFT JOIN transactions t ON t.id = o.transaction_id
       LEFT JOIN merchants m ON m.id = t.merchant_id
       LEFT JOIN cash_loan_allocations a ON a.id = o.cash_loan_allocation_id
       LEFT JOIN cash_loans cl ON cl.id = a.cash_loan_id
      WHERE o.id = :id AND o.borrower_id = :b AND o.status <> 'cancelled'",
    input_string($_GET, 'id'), $bid, 'portal/obligations.php', 'Item'
);
$id = $o['id'];

$items = $o['transaction_id'] ? db_all(
    'SELECT product_name, brand, model, quantity, total_amount FROM transaction_items WHERE transaction_id = :t ORDER BY created_at',
    [':t' => $o['transaction_id']]
) : [];
$schedule = $o['transaction_id'] ? db_all(
    'SELECT s.* FROM installment_schedules s JOIN installment_plans p ON p.id = s.installment_plan_id
      WHERE p.transaction_id = :t AND p.status <> \'cancelled\' ORDER BY s.installment_number',
    [':t' => $o['transaction_id']]
) : [];
$applied = db_all(
    'SELECT a.allocated_amount, a.allocation_type, a.created_at, p.id AS payment_id, p.payment_number, p.payment_date
       FROM borrower_payment_allocations a JOIN borrower_payments p ON p.id = a.borrower_payment_id
      WHERE a.obligation_id = :id AND p.borrower_id = :b ORDER BY a.created_at',
    [':id' => $id, ':b' => $bid]
);
$penalties = db_all(
    'SELECT ap.applied_date, ap.overdue_days, ap.amount, ap.paid_amount, ap.remaining_amount, r.name AS rule_name
       FROM applied_penalties ap JOIN penalty_rules r ON r.id = ap.penalty_rule_id
      WHERE ap.obligation_id = :id ORDER BY ap.applied_date',
    [':id' => $id]
);
$daysLate = $o['due_date'] && (float) $o['outstanding_amount'] > 0 && $o['due_date'] < date('Y-m-d')
    ? (int) ((strtotime(date('Y-m-d')) - strtotime($o['due_date'])) / 86400) : 0;

$pageTitle = 'What I Owe';
$activeNav = 'portal_obligations';
$layout = 'portal';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['What I Owe' => url('portal/obligations.php'), $o['obligation_number'] => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title"><?= e($o['obligation_number']) ?> — <?= e(borrower_name($borrower)) ?></h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($o['obligation_number']) ?></h2>
        <p class="page-header__subtitle"><?= e($o['description'] ?: 'Obligation') ?> · <?= badge($o['status']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('portal/obligations.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <?php if ((float) $o['outstanding_amount'] > 0): ?>
            <a href="<?= e(url('portal/pay.php')) ?>" class="btn btn--primary"><?= icon('wallet') ?> <span>Submit a payment</span></a>
        <?php endif; ?>
    </div>
</section>

<?php if ($daysLate > 0): ?>
    <div class="alert alert--error" role="status"><?= icon('alert') ?>
        <span>This is <?= e($daysLate) ?> day(s) past its due date (<?= e(format_date($o['due_date'])) ?>).</span></div>
<?php endif; ?>

<section class="figure-grid">
    <div class="figure"><p class="figure__label">Amount due</p><p class="figure__value"><?= e(format_money($o['total_amount_due'])) ?></p></div>
    <div class="figure"><p class="figure__label">Paid</p><p class="figure__value"><?= e(format_money($o['total_paid'])) ?></p></div>
    <div class="figure"><p class="figure__label">Remaining</p><p class="figure__value"><?= e(format_money($o['outstanding_amount'])) ?></p></div>
    <div class="figure"><p class="figure__label">Late charges unpaid</p><p class="figure__value"><?= e(format_money(array_sum(array_column($penalties, 'remaining_amount')))) ?></p></div>
    <div class="figure"><p class="figure__label">Due date</p><p class="figure__value"><?= $o['due_date'] ? e(format_date($o['due_date'])) : '—' ?></p></div>
</section>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Details</h3></header>
        <?= render_details([
            'Number'          => e($o['obligation_number']),
            'Description'     => show($o['description']),
            'For'             => $o['transaction_number']
                ? e('Purchase ' . $o['transaction_number']) . ($o['merchant_name'] ? ' · ' . e($o['merchant_name']) : '') . ' · ' . e(format_date($o['transaction_date']))
                : ($o['loan_reference'] ? e('Share of cash loan ' . $o['loan_reference']) : '<span class="text-muted">—</span>'),
            'Original amount' => e(format_money($o['original_amount'])),
            'Agreed interest' => e(format_money($o['agreed_interest'])),
            'Agreed fees'     => e(format_money($o['agreed_fees'])),
            'Total amount due' => '<strong>' . e(format_money($o['total_amount_due'])) . '</strong>',
            'Paid'            => e(format_money($o['total_paid'])),
            'Remaining'       => '<strong>' . e(format_money($o['outstanding_amount'])) . '</strong>',
            'Overdue amount'  => e(format_money($o['overdue_amount'])),
            'Date'            => e(format_date($o['obligation_date'])),
            'Due date'        => show($o['due_date'] ? format_date($o['due_date']) : null),
            'Status'          => badge($o['status']),
            'Fully paid on'   => show($o['completed_at'] ? format_datetime($o['completed_at']) : null),
        ]) ?>
    </section>

    <div class="detail-side">
        <section class="panel">
            <header class="panel__header"><h3>Payments applied</h3></header>
            <?php if (!$applied): ?>
                <p class="empty-inline">No approved payment has been applied yet.</p>
            <?php else: ?>
                <ul class="child-list">
                    <?php foreach ($applied as $a): ?>
                        <li><a href="<?= e(url('portal/payment.php', ['id' => $a['payment_id']])) ?>"><?= e($a['payment_number']) ?></a>
                            <span><?= e(format_money($a['allocated_amount'])) ?> to <?= e(strtolower(humanize($a['allocation_type']))) ?> · <?= e(format_date($a['payment_date'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php if ($items): ?>
            <section class="panel">
                <header class="panel__header"><h3>Items purchased</h3></header>
                <ul class="child-list">
                    <?php foreach ($items as $item): ?>
                        <li><span><?= e($item['product_name']) ?><?= $item['brand'] || $item['model'] ? ' <span class="text-muted">' . e(trim($item['brand'] . ' ' . $item['model'])) . '</span>' : '' ?></span>
                            <span><?= e(rtrim(rtrim($item['quantity'], '0'), '.')) ?> × · <?= e(format_money($item['total_amount'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php if ($schedule): ?>
    <section class="panel subpanel">
        <header class="panel__header"><h3><?= icon('calendar') ?> Installment schedule</h3></header>
        <?= render_schedule_table($schedule, '', [], false) ?>
    </section>
<?php endif; ?>

<?php if ($penalties): ?>
    <section class="panel subpanel">
        <header class="panel__header"><h3><?= icon('alert') ?> Late charges</h3></header>
        <p class="field-help">Your payments pay late charges first, then interest and fees, then the principal.</p>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Date</th><th scope="col">Charge</th><th scope="col" class="num">Days late</th><th scope="col" class="num">Amount</th><th scope="col" class="num">Paid</th><th scope="col" class="num">Remaining</th></tr></thead>
                <tbody>
                <?php foreach ($penalties as $p): ?>
                    <tr>
                        <td data-label="Date"><?= e(format_date($p['applied_date'])) ?></td>
                        <td data-label="Charge"><?= e($p['rule_name']) ?></td>
                        <td data-label="Days late" class="num"><?= e($p['overdue_days']) ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($p['amount'])) ?></td>
                        <td data-label="Paid" class="num"><?= e(format_money($p['paid_amount'])) ?></td>
                        <td data-label="Remaining" class="num"><?= e(format_money($p['remaining_amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
