<?php
/**
 * Borrower portal - home: balance, what is due next, recent payments, notices.
 */

require_once __DIR__ . '/../includes/auth_check.php';

[$currentUser, $borrower] = require_borrower();
$bid = $borrower['id'];

$summary = portal_balance_summary($bid);
$nextDue = db_row(
    "SELECT id, obligation_number, description, outstanding_amount, due_date FROM borrower_obligations
      WHERE borrower_id = :b AND outstanding_amount > 0 AND status NOT IN ('cancelled', 'written_off', 'paid')
      ORDER BY due_date IS NULL, due_date LIMIT 1",
    [':b' => $bid]
);
$collect = collection_totals($bid);
$openObligations = db_all(
    "SELECT o.id, o.obligation_number, o.description, o.outstanding_amount, o.due_date, o.status,
            COALESCE(pen.penalties_unpaid, 0) AS penalties_unpaid, o.outstanding_amount + COALESCE(pen.penalties_unpaid, 0) AS total_due
       FROM borrower_obligations o " . penalty_totals_join() . "
      WHERE o.borrower_id = :b AND o.status NOT IN ('cancelled', 'written_off')
        AND (o.outstanding_amount > 0 OR COALESCE(pen.penalties_unpaid, 0) > 0)
      ORDER BY o.due_date IS NULL, o.due_date LIMIT 10",
    [':b' => $bid]
);
$unpaidPenalties = db_all(
    "SELECT ap.id, ap.applied_date, ap.overdue_days, ap.amount, ap.paid_amount, ap.remaining_amount, r.name AS rule_name,
            o.id AS obligation_id, o.obligation_number
       FROM applied_penalties ap
       JOIN borrower_obligations o ON o.id = ap.obligation_id
       JOIN penalty_rules r ON r.id = ap.penalty_rule_id
      WHERE o.borrower_id = :b AND o.status NOT IN ('cancelled', 'written_off') AND ap.remaining_amount > 0
      ORDER BY ap.applied_date, o.obligation_number",
    [':b' => $bid]
);
$recentPayments = db_all(
    'SELECT id, payment_number, payment_date, amount, status FROM borrower_payments WHERE borrower_id = :b ORDER BY submitted_at DESC LIMIT 5',
    [':b' => $bid]
);
$needsCorrection = (int) db_value("SELECT COUNT(*) FROM borrower_payments WHERE borrower_id = :b AND status = 'correction_required'", [':b' => $bid]);
$pendingPayments = db_row(
    "SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM borrower_payments WHERE borrower_id = :b AND status IN ('submitted', 'under_review')",
    [':b' => $bid]
);
$newLetters = (int) db_value("SELECT COUNT(*) FROM demand_letters WHERE borrower_id = :b AND status = 'sent'", [':b' => $bid]);

$pageTitle = 'My Account';
$activeNav = 'portal_home';
$layout = 'portal';
require APP_ROOT . '/includes/header.php';
?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Hello, <?= e($borrower['nickname'] ?: $borrower['first_name']) ?></h2>
        <p class="page-header__subtitle">Borrower <?= e($borrower['borrower_number']) ?> · <?= badge($borrower['status']) ?> · as of <?= e(date('F j, Y g:i A')) ?></p>
    </div>
    <div class="page-header__actions">
        <a data-modal-form href="<?= e(url('portal/pay.php')) ?>" class="btn btn--primary"><?= icon('wallet') ?> <span>Submit a payment</span></a>
    </div>
</section>

<?php if ($needsCorrection > 0): ?>
    <div class="alert alert--warning" role="status"><?= icon('alert') ?>
        <span><?= e($needsCorrection) ?> of your payments need a correction. <a href="<?= e(url('portal/payments.php', ['status' => 'correction_required'])) ?>">Open them</a> to see what to fix.</span></div>
<?php endif; ?>
<?php if ($newLetters > 0): ?>
    <div class="alert alert--error" role="status"><?= icon('mail-alert') ?>
        <span>You have <?= e($newLetters) ?> new notice(s) from the owner. <a href="<?= e(url('portal/letters.php')) ?>">Read them</a>.</span></div>
<?php endif; ?>

<section class="balance-equation" aria-label="Total to pay">
    <div class="balance-equation__part">
        <p class="balance-equation__label"><a href="<?= e(url('portal/obligations.php')) ?>">Outstanding</a></p>
        <p class="balance-equation__value"><?= e(format_money($collect['outstanding'])) ?></p>
    </div>
    <span class="balance-equation__op" aria-hidden="true">+</span>
    <div class="balance-equation__part">
        <p class="balance-equation__label"><a href="<?= e(url('portal/penalties.php')) ?>">Penalties (late charges)</a></p>
        <p class="balance-equation__value<?= (float) $collect['penalties'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($collect['penalties'])) ?></p>
    </div>
    <span class="balance-equation__op" aria-hidden="true">=</span>
    <div class="balance-equation__part balance-equation__part--total">
        <p class="balance-equation__label">Total to pay</p>
        <p class="balance-equation__value"><?= e(format_money($collect['total_due'])) ?></p>
    </div>
</section>

<section class="stat-grid" aria-label="My balance">
    <article class="stat-card">
        <div class="stat-card__icon stat-card__icon--danger"><?= icon('alert') ?></div>
        <div>
            <p class="stat-card__label">Overdue</p>
            <p class="stat-card__value stat-card__value--money"><?= e(format_money($summary['overdue'])) ?></p>
            <p class="stat-card__meta">Past the due date</p>
        </div>
    </article>
    <article class="stat-card">
        <div class="stat-card__icon stat-card__icon--info"><?= icon('calendar') ?></div>
        <div>
            <p class="stat-card__label">Next due</p>
            <p class="stat-card__value stat-card__value--money"><?= $nextDue ? e(format_money($nextDue['outstanding_amount'])) : '—' ?></p>
            <p class="stat-card__meta"><?= $nextDue ? ($nextDue['due_date'] ? 'Due ' . e(format_date($nextDue['due_date'])) : 'No due date set') : 'Nothing due' ?></p>
        </div>
    </article>
    <article class="stat-card">
        <div class="stat-card__icon stat-card__icon--primary"><?= icon('check') ?></div>
        <div>
            <p class="stat-card__label"><a href="<?= e(url('portal/payments.php')) ?>">Total paid</a></p>
            <p class="stat-card__value stat-card__value--money"><?= e(format_money($summary['total_paid'])) ?></p>
            <p class="stat-card__meta"><?= (int) $pendingPayments['n'] > 0 ? e(format_money($pendingPayments['total'])) . ' waiting for approval' : 'Approved payments only' ?></p>
        </div>
    </article>
</section>

<section class="panel balance-panel" aria-labelledby="open-title">
    <header class="panel__header">
        <h3 id="open-title"><?= icon('file-text') ?> What I owe</h3>
        <a class="btn btn--sm btn--outline" href="<?= e(url('portal/obligations.php')) ?>">View all</a>
    </header>
    <?php if (!$openObligations): ?>
        <p class="empty-inline">You have no open balance. Thank you!</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Item</th><th scope="col">Due date</th><th scope="col" class="num">Outstanding</th><th scope="col" class="num">Penalties</th><th scope="col" class="num">Total</th><th scope="col">Status</th></tr></thead>
                <tbody>
                <?php foreach ($openObligations as $o): ?>
                    <tr>
                        <td data-label="Item"><a data-modal-form class="table-link" href="<?= e(url('portal/obligation.php', ['id' => $o['id']])) ?>"><?= e($o['obligation_number']) ?></a>
                            <?php if ($o['description']): ?><br><span class="text-muted"><?= e($o['description']) ?></span><?php endif; ?></td>
                        <td data-label="Due date"><?= $o['due_date'] ? e(format_date($o['due_date'])) : '<span class="text-muted">—</span>' ?></td>
                        <td data-label="Outstanding" class="num"><?= e(format_money($o['outstanding_amount'])) ?></td>
                        <td data-label="Penalties" class="num<?= (float) $o['penalties_unpaid'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($o['penalties_unpaid'])) ?></td>
                        <td data-label="Total" class="num"><strong><?= e(format_money($o['total_due'])) ?></strong></td>
                        <td data-label="Status"><?= badge($o['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="2">Total</td><td class="num"><?= e(format_money($collect['outstanding'])) ?></td><td class="num"><?= e(format_money($collect['penalties'])) ?></td>
                    <td class="num"><strong><?= e(format_money($collect['total_due'])) ?></strong></td><td></td></tr></tfoot>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel balance-panel" aria-labelledby="pen-title">
    <header class="panel__header">
        <h3 id="pen-title"><?= icon('alert') ?> My penalties (late charges) to pay</h3>
        <a class="btn btn--sm btn--outline" href="<?= e(url('portal/penalties.php')) ?>">All penalties</a>
    </header>
    <?php if (!$unpaidPenalties): ?>
        <p class="empty-inline">No unpaid penalties. Keep paying on time!</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Date charged</th><th scope="col">Item</th><th scope="col">Charge</th><th scope="col" class="num">Days late</th><th scope="col" class="num">Amount</th><th scope="col" class="num">Paid</th><th scope="col" class="num">To pay</th></tr></thead>
                <tbody>
                <?php foreach ($unpaidPenalties as $p): ?>
                    <tr>
                        <td data-label="Date charged"><?= e(format_date($p['applied_date'])) ?></td>
                        <td data-label="Item"><a data-modal-form class="table-link" href="<?= e(url('portal/obligation.php', ['id' => $p['obligation_id']])) ?>"><?= e($p['obligation_number']) ?></a></td>
                        <td data-label="Charge"><?= e($p['rule_name']) ?></td>
                        <td data-label="Days late" class="num"><?= e($p['overdue_days']) ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($p['amount'])) ?></td>
                        <td data-label="Paid" class="num"><?= e(format_money($p['paid_amount'])) ?></td>
                        <td data-label="To pay" class="num text-danger"><strong><?= e(format_money($p['remaining_amount'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="6"><?= count($unpaidPenalties) ?> unpaid penalt<?= count($unpaidPenalties) === 1 ? 'y' : 'ies' ?></td><td class="num"><strong><?= e(format_money($collect['penalties'])) ?></strong></td></tr></tfoot>
            </table>
        </div>
        <p class="field-help">Your payments pay penalties first (oldest first), then interest and fees, then the principal. Penalties are added every month while an item is overdue.</p>
    <?php endif; ?>
</section>

<div class="panel-grid">
    <section class="panel" aria-labelledby="pay-title">
        <header class="panel__header">
            <h3 id="pay-title"><?= icon('receipt') ?> My recent payments</h3>
            <a class="btn btn--sm btn--outline" href="<?= e(url('portal/payments.php')) ?>">View all</a>
        </header>
        <?php if (!$recentPayments): ?>
            <p class="empty-inline">No payments yet. Use “Submit a payment” after you pay.</p>
        <?php else: ?>
            <ul class="child-list">
                <?php foreach ($recentPayments as $p): ?>
                    <li><a href="<?= e(url('portal/payment.php', ['id' => $p['id']])) ?>"><?= e($p['payment_number']) ?></a>
                        <span><?= e(format_money($p['amount'])) ?> · <?= e(format_date($p['payment_date'])) ?> <?= badge($p['status']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p class="field-help">A payment counts toward your balance after the owner approves it.</p>
    </section>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
