<?php
/**
 * Staff dashboard: today's work for the card owner's books - transactions,
 * payments received, borrower balances, card due dates, overdue repayments,
 * recent activity - all read from the database (owner-scoped).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/includes/receipts.php';

$currentUser = require_staff_area();
$today = date('Y-m-d');
$cards = owned_cards_sql();
$borrowers = owned_borrowers_sql();

$txn = db_row(
    "SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total,
            COALESCE(SUM(transaction_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0) AS month_n,
            COALESCE(SUM(IF(transaction_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01'), amount, 0)), 0) AS month_total,
            COALESCE(SUM(DATE(created_at) = CURDATE()), 0) AS today_n
       FROM transactions WHERE credit_card_id IN $cards AND status NOT IN ('cancelled', 'refunded')"
);
$received = db_row(
    "SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM borrower_payments
      WHERE borrower_id IN $borrowers AND status = 'approved' AND DATE(COALESCE(approved_at, reviewed_at, created_at)) = CURDATE()"
);
$toReview = (int) db_value("SELECT COUNT(*) FROM borrower_payments WHERE borrower_id IN $borrowers AND status IN ('submitted', 'under_review')");

// Borrower balances: every open item of every borrower (cash loans, advances, card purchases, ...).
$owed = ['total' => 0.0, 'overdue' => 0.0, 'due_now' => 0.0, 'borrowers' => 0];
$overdueList = [];
foreach (db_all("SELECT * FROM borrowers b WHERE b.id IN $borrowers AND b.status <> 'inactive'") as $b) {
    $sum = portal_items_totals(portal_all_items($b['id']));
    if ($sum['total'] <= 0.004) {
        continue;
    }
    $owed['total'] += $sum['total'];
    $owed['overdue'] += $sum['overdue'];
    $owed['due_now'] += $sum['due_now'];
    $owed['borrowers']++;
    if ($sum['overdue'] > 0.004) {
        $overdueList[] = ['borrower' => $b, 'overdue' => $sum['overdue'], 'total' => $sum['total']];
    }
}
usort($overdueList, static fn ($a, $b) => $b['overdue'] <=> $a['overdue']);

$due = array_values(array_filter(staff_due_dates(), static fn ($r) => $r['status'] !== 'paid'));
$dueSoon = count(array_filter($due, static fn ($r) => in_array($r['status'], ['due_soon', 'overdue'], true)));

$activity = db_all(
    "(SELECT 'transaction' AS kind, t.id, t.transaction_number AS ref, t.amount, t.created_at AS at, t.transaction_date AS date, c.card_nickname AS detail, NULL AS receipt_id, t.created_by AS by_user
        FROM transactions t JOIN credit_cards c ON c.id = t.credit_card_id WHERE t.credit_card_id IN $cards ORDER BY t.created_at DESC LIMIT 8)
     UNION ALL
     (SELECT 'payment', p.id, p.payment_number, p.amount, p.created_at, p.payment_date, CONCAT(b.first_name, ' ', b.last_name), i.receipt_id, p.reviewed_by
        FROM borrower_payments p JOIN borrowers b ON b.id = p.borrower_id LEFT JOIN payment_receipt_items i ON i.borrower_payment_id = p.id
       WHERE p.borrower_id IN $borrowers ORDER BY p.created_at DESC LIMIT 8)
     ORDER BY at DESC LIMIT 10"
);
$pendingRequests = (int) db_value("SELECT COUNT(*) FROM authorization_requests r WHERE r.status = 'pending' AND " . owner_scope('authorization_requests', 'r')
    . (user_is_staff($currentUser) ? ' AND r.requested_by = ' . db()->quote($currentUser['id']) : ''));

$pageTitle = 'Staff Dashboard';
$activeNav = 'staff_home';
require APP_ROOT . '/includes/header.php';
?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Good day, <?= e($currentUser['first_name'] ?: user_display_name($currentUser)) ?></h2>
        <p class="page-header__subtitle">Working on <?= e(user_name_of(owner_id())) ?>'s records · <?= e(date('l, F j, Y')) ?></p>
    </div>
</section>

<nav class="quick-actions" aria-label="Quick actions">
    <a data-modal-form class="btn btn--primary" href="<?= e(url('pages/transactions/create.php')) ?>"><?= icon('plus') ?> <span>Record transaction</span></a>
    <a class="btn btn--primary" href="<?= e(url('pages/staff/receive.php')) ?>"><?= icon('wallet') ?> <span>Record borrower payment</span></a>
    <a class="btn btn--outline" href="<?= e(url('pages/credit_cards/index.php')) ?>"><?= icon('credit-card') ?> <span>View credit cards</span></a>
    <a class="btn btn--outline" href="<?= e(url('pages/installment_plans/index.php')) ?>"><?= icon('repeat') ?> <span>View installments</span></a>
    <a class="btn btn--outline" href="<?= e(url('pages/staff/due_dates.php')) ?>"><?= icon('calendar') ?> <span>View due dates</span></a>
</nav>

<?php if ($pendingRequests > 0): ?>
    <div class="alert alert--info"><?= icon('shield') ?><span><?= e($pendingRequests) ?> change request(s) waiting for the owner's decision. <a href="<?= e(url('pages/authorizations/index.php')) ?>">Open requests</a></span></div>
<?php endif; ?>

<section class="stat-grid stat-grid--wide" aria-label="Today">
    <a class="stat-card" href="<?= e(url('pages/transactions/index.php')) ?>">
        <div class="stat-card__icon stat-card__icon--primary"><?= icon('layers') ?></div>
        <div><p class="stat-card__label">Card transactions recorded</p><p class="stat-card__value"><?= e(number_format((int) $txn['n'])) ?></p>
            <p class="stat-card__meta"><?= e(format_money($txn['total'])) ?> in total · <?= e((int) $txn['month_n']) ?> this month (<?= e(format_money($txn['month_total'])) ?>) · <?= e((int) $txn['today_n']) ?> today</p></div>
    </a>
    <a class="stat-card" href="<?= e(url('pages/staff/receipts.php', ['from' => $today, 'to' => $today])) ?>">
        <div class="stat-card__icon stat-card__icon--success"><?= icon('check') ?></div>
        <div><p class="stat-card__label">Payments received today</p><p class="stat-card__value stat-card__value--money"><?= e(format_money($received['total'])) ?></p>
            <p class="stat-card__meta"><?= e((int) $received['n']) ?> payment(s)<?= $toReview ? ' · ' . e($toReview) . ' submitted online, waiting for the owner' : '' ?></p></div>
    </a>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--info"><?= icon('users') ?></div>
        <div><p class="stat-card__label">Outstanding borrower balances</p><p class="stat-card__value stat-card__value--money"><?= e(format_money($owed['total'])) ?></p>
            <p class="stat-card__meta"><?= e($owed['borrowers']) ?> borrower(s) · <?= e(format_money($owed['due_now'])) ?> due this period</p></div>
    </div>
    <a class="stat-card" href="<?= e(url('pages/staff/due_dates.php')) ?>">
        <div class="stat-card__icon stat-card__icon--warning"><?= icon('calendar') ?></div>
        <div><p class="stat-card__label">Card payments due</p><p class="stat-card__value"><?= e($dueSoon) ?></p>
            <p class="stat-card__meta">due within <?= e(STATEMENT_UPCOMING_DAYS) ?> days or overdue · <?= e(count($due)) ?> open</p></div>
    </a>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--danger"><?= icon('alert') ?></div>
        <div><p class="stat-card__label">Overdue borrower repayments</p><p class="stat-card__value stat-card__value--money"><?= e(format_money($owed['overdue'])) ?></p>
            <p class="stat-card__meta"><?= e(count($overdueList)) ?> borrower(s) overdue</p></div>
    </div>
</section>

<div class="panel-grid">
    <section class="panel" aria-labelledby="due-title">
        <header class="panel__header"><h3 id="due-title"><?= icon('calendar') ?> Upcoming card due dates</h3><a class="btn btn--sm btn--outline" href="<?= e(url('pages/staff/due_dates.php')) ?>">All due dates</a></header>
        <?php if (!$due): ?>
            <p class="empty-inline">No open card statements.</p>
        <?php else: ?>
            <ul class="child-list">
                <?php foreach (array_slice($due, 0, 6) as $r): ?>
                    <li><span><strong><?= e($r['card']['card_nickname']) ?></strong> · due <?= $r['due'] ? e(format_date($r['due'])) : '—' ?>
                        <?= $r['days'] !== null ? '<span class="text-muted">(' . ($r['days'] < 0 ? e(-$r['days']) . ' days late' : e($r['days']) . ' days)') . '</span>' : '' ?> <?= staff_due_badge($r['status']) ?></span>
                        <strong><?= $r['remaining'] !== null ? e(format_money($r['remaining'])) : '<span class="text-muted">SOA not recorded</span>' ?></strong></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel" aria-labelledby="overdue-title">
        <header class="panel__header"><h3 id="overdue-title"><?= icon('alert') ?> Overdue borrowers</h3></header>
        <?php if (!$overdueList): ?>
            <p class="empty-inline">No overdue repayments.</p>
        <?php else: ?>
            <ul class="child-list">
                <?php foreach (array_slice($overdueList, 0, 6) as $o): ?>
                    <li><span><a href="<?= e(url('pages/staff/receive.php', ['borrower_id' => $o['borrower']['id']])) ?>"><?= e(borrower_name($o['borrower'])) ?></a>
                        <span class="text-muted">· owes <?= e(format_money($o['total'])) ?></span></span>
                        <strong class="text-danger"><?= e(format_money($o['overdue'])) ?></strong></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<section class="panel subpanel" aria-labelledby="activity-title">
    <header class="panel__header"><h3 id="activity-title"><?= icon('history') ?> Recent transactions and payments</h3></header>
    <?php if (!$activity): ?>
        <p class="empty-inline">Nothing recorded yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">When</th><th scope="col">Type</th><th scope="col">Reference</th><th scope="col">Card / borrower</th><th scope="col" class="num">Amount</th><th scope="col"><span class="visually-hidden">Open</span></th></tr></thead>
                <tbody>
                <?php foreach ($activity as $a): ?>
                    <tr>
                        <td data-label="When"><?= e(format_datetime($a['at'])) ?></td>
                        <td data-label="Type"><?= $a['kind'] === 'transaction' ? 'Card transaction' : 'Borrower payment' ?></td>
                        <td data-label="Reference"><?= e($a['ref']) ?></td>
                        <td data-label="Card / borrower"><?= e($a['detail']) ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($a['amount'])) ?></td>
                        <td data-label="Open"><?php if ($a['kind'] === 'transaction'): ?>
                                <a data-modal-form href="<?= e(url('pages/transactions/view.php', ['id' => $a['id']])) ?>">View</a>
                            <?php elseif ($a['receipt_id']): ?>
                                <a href="<?= e(url('pages/staff/receipt.php', ['id' => $a['receipt_id']])) ?>">Receipt</a>
                            <?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
