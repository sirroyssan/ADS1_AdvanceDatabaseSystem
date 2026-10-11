<?php
/**
 * Personal dashboard: the signed-in user's own cards, statements, payments,
 * transactions, installments and borrowers. Every number comes from a live
 * query scoped to that user (includes/ownership.php) - administrators see
 * their own records here too, never other users'.
 */

require_once __DIR__ . '/../includes/auth_check.php';

$currentUser = require_admin();
$isSystemAdmin = user_is_system_admin($currentUser);

/**
 * Run a read-only dashboard query. Returns null (shown as "—") if it fails,
 * e.g. when a reporting view was not imported.
 */
function dashboard_value(string $sql): mixed
{
    try {
        return db()->query($sql)->fetchColumn();
    } catch (PDOException $e) {
        error_log('[' . APP_NAME . '] Dashboard query failed: ' . $e->getMessage());
        return null;
    }
}

function dashboard_rows(string $sql): array
{
    try {
        return db()->query($sql)->fetchAll();
    } catch (PDOException $e) {
        error_log('[' . APP_NAME . '] Dashboard query failed: ' . $e->getMessage());
        return [];
    }
}

function stat_number(mixed $value): string
{
    return $value === null || $value === false ? '—' : format_number((float) $value);
}

/** Card number as shown everywhere on the dashboard (only the last four digits are stored). */
function masked_card(string $lastFour): string
{
    return '•••• •••• •••• ' . $lastFour;
}

$myCards      = owned_cards_sql();
$myBorrowers  = owned_borrowers_sql();
$myTxn        = owner_scope('transactions', 't');
$myObligation = owner_scope('borrower_obligations', 'o');

$stats = [
    'borrowers'          => dashboard_value("SELECT COUNT(*) FROM borrowers WHERE id IN $myBorrowers"),
    'borrowers_good'     => dashboard_value("SELECT COUNT(*) FROM borrowers WHERE id IN $myBorrowers AND status = 'good_standing'"),
    'borrowers_late'     => dashboard_value("SELECT COUNT(*) FROM borrowers WHERE id IN $myBorrowers AND status IN ('late', 'delinquent')"),
    'cards_used'         => dashboard_value("SELECT COALESCE(SUM(outstanding), 0) FROM (" . card_utilization_sql() . ") v WHERE v.id IN $myCards"),
    'cards_limit'        => dashboard_value("SELECT COALESCE(SUM(credit_limit), 0) FROM credit_cards WHERE status = 'active' AND id IN $myCards"),
    'transactions'       => dashboard_value("SELECT COUNT(*) FROM transactions t WHERE $myTxn"),
    'transactions_month' => dashboard_value(
        "SELECT COUNT(*) FROM transactions t
          WHERE $myTxn AND t.transaction_date >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')"
    ),
    'pending_payments'   => dashboard_value("SELECT COUNT(*) FROM vw_pending_payment_approvals v
                                              WHERE v.id IN (SELECT p.id FROM borrower_payments p WHERE p.borrower_id IN $myBorrowers)"),
    'overdue'            => dashboard_value("SELECT COUNT(*) FROM vw_overdue_obligations WHERE borrower_id IN $myBorrowers"),
    'overdue_amount'     => dashboard_value("SELECT COALESCE(SUM(overdue_amount), 0) FROM vw_overdue_obligations WHERE borrower_id IN $myBorrowers"),
    'categories_active'  => dashboard_value('SELECT COUNT(*) FROM categories WHERE is_active = 1'),
    'categories_inactive'=> dashboard_value('SELECT COUNT(*) FROM categories WHERE is_active = 0'),
];
if ($isSystemAdmin) {
    // Account counts only (no financial data): system administration.
    $stats['users_total']  = dashboard_value('SELECT COUNT(*) FROM users');
    $stats['users_active'] = dashboard_value("SELECT COUNT(*) FROM users WHERE status = 'active'");
    $stats['unowned']      = array_sum(multiuser_unassigned_counts());
}

$pendingPayments = dashboard_rows(
    "SELECT id, payment_number, first_name, last_name, payment_date, amount, display_name, status
       FROM vw_pending_payment_approvals v
      WHERE v.id IN (SELECT p.id FROM borrower_payments p WHERE p.borrower_id IN $myBorrowers)
      ORDER BY submitted_at ASC
      LIMIT 5"
);

// Outstanding + unpaid penalties = total to collect (includes/penalties.php).
try {
    $collect = collection_totals();
} catch (PDOException $e) {
    $collect = ['outstanding' => null, 'penalties' => null, 'total_due' => null];
}
// Who owes me: per borrower, what is owed on obligations (older workflow) plus on the card
// purchases assigned to them (the transactions themselves), next due date, repayments to confirm, status.
$borrowerBalances = dashboard_rows(
    'SELECT b.id, b.borrower_number, b.first_name, b.last_name, COALESCE(bt.outstanding, 0) AS outstanding,
            COALESCE(bt.penalties, 0) AS penalties, COALESCE(bt.total_due, 0) AS total_due,
            (SELECT MIN(o.due_date) FROM borrower_obligations o WHERE o.borrower_id = b.id AND o.outstanding_amount > 0
                AND o.status NOT IN (\'cancelled\', \'written_off\')) AS next_due,
            (SELECT COUNT(*) FROM borrower_payments p WHERE p.borrower_id = b.id AND p.status IN (\'submitted\', \'under_review\')) AS to_confirm,
            (SELECT COUNT(*) FROM borrower_user_accounts a WHERE a.borrower_id = b.id AND a.deactivated_at IS NULL) AS has_account,
            (SELECT COUNT(*) FROM borrower_obligations r WHERE r.borrower_id = b.id AND r.status = \'paid\' AND r.updated_at >= CURDATE() - INTERVAL 30 DAY) AS recently_paid
       FROM borrowers b LEFT JOIN (' . borrower_totals_sql() . ") bt ON bt.borrower_id = b.id
      WHERE b.id IN $myBorrowers"
);
$purchaseByBorrower = [];
foreach (borrowed_owner_purchases() as $p) {
    $purchaseByBorrower[$p['borrower_id']][] = $p;
}
$today = date('Y-m-d');
foreach ($borrowerBalances as $i => &$row) {
    $mine = $purchaseByBorrower[$row['id']] ?? [];
    $sum = borrowed_totals($mine);
    $row['outstanding'] = (float) $row['outstanding'] + $sum['remaining'];
    $row['penalties'] = (float) $row['penalties'] + $sum['penalties'];
    $row['total_due'] = (float) $row['total_due'] + $sum['outstanding'];
    $row['review'] = $sum['review'];
    foreach ($mine as $p) {
        $due = $p['ledger']['next_due']['date'] ?? null;
        if ($due !== null && $p['ledger']['status'] !== 'repaid' && ($row['next_due'] === null || $due < $row['next_due'])) {
            $row['next_due'] = $due;
        }
    }
    $overdue = $sum['overdue'] > 0 || ($row['next_due'] !== null && $row['next_due'] < $today);
    $row['repay_status'] = match (true) {
        $row['total_due'] <= 0.004 => 'repaid',
        $overdue => 'overdue',
        default => 'pending',
    };
    // Listed while something is owed, or when everything was repaid recently.
    $recent = (int) $row['recently_paid'] > 0 || (bool) array_filter($mine, static fn ($p) => $p['transaction_date'] >= date('Y-m-d', strtotime('-60 days')));
    if ($row['total_due'] <= 0.004 && !$recent) {
        unset($borrowerBalances[$i]);
    }
}
unset($row);
usort($borrowerBalances, static fn ($a, $b) => [$a['total_due'] <= 0.004, $a['next_due'] === null, $a['next_due'], -$a['total_due']]
    <=> [$b['total_due'] <= 0.004, $b['next_due'] === null, $b['next_due'], -$b['total_due']]);
$borrowerBalances = array_slice($borrowerBalances, 0, 10);
$purchaseTotals = borrowed_totals(array_merge([], ...array_values($purchaseByBorrower)));

// What the signed-in user borrowed with other users' cards (their side of the same transactions).
$borrowedItems = borrowed_has_items() ? borrowed_my_purchases() : [];
$borrowed = $borrowedItems ? borrowed_totals($borrowedItems) : null;
$borrowedOpen = array_slice(array_values(array_filter($borrowedItems, static fn ($r) => $r['ledger']['status'] !== 'repaid')), 0, 5);

$overdueObligations = dashboard_rows(
    "SELECT id, obligation_number, first_name, last_name, outstanding_amount, due_date, overdue_days
       FROM vw_overdue_obligations
      WHERE borrower_id IN $myBorrowers
      ORDER BY overdue_days DESC
      LIMIT 5"
);

$recentActivity = dashboard_rows(
    "SELECT a.action, a.entity_type, a.description, a.created_at,
            COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, u.email, 'System') AS actor
       FROM activity_logs a
       LEFT JOIN users u ON u.id = a.actor_user_id
      WHERE " . activity_scope('a') . "
      ORDER BY a.created_at DESC
      LIMIT 8"
);

// Credit card payments: recorded SOAs and the payments linked to them (each statement once).
$cardPay = statement_payment_summary();
$dueSoon = db_all(
    'SELECT s.id, s.cutoff_date, s.statement_date, s.due_date, s.total_amount_due, s.minimum_amount_due, c.card_nickname, c.issuer, c.last_four,
            COALESCE(sp.paid, 0) AS paid, ' . statement_remaining_sql() . ' AS remaining, ' . statement_status_sql() . ' AS pay_status
       FROM credit_card_statements s JOIN credit_cards c ON c.id = s.credit_card_id ' . statement_paid_join() . '
      WHERE s.soa_recorded_at IS NOT NULL AND ' . owner_scope('credit_card_statements', 's') . ' AND ' . statement_remaining_sql() . ' > 0.004
        AND s.due_date <= CURDATE() + INTERVAL ' . STATEMENT_UPCOMING_DAYS . ' DAY
      ORDER BY s.due_date
      LIMIT 10'
);

// My credit cards: limit, used and available credit, and the next payment due on a recorded SOA.
$cards = dashboard_rows(
    "SELECT cc.id, cc.card_nickname, cc.issuer, cc.network, cc.last_four, cc.status, cc.credit_limit,
            COALESCE(cu.used, 0) AS used, cc.credit_limit - COALESCE(cu.used, 0) AS available,
            IF(cc.credit_limit > 0, ROUND(COALESCE(cu.used, 0) / cc.credit_limit * 100, 2), 0) AS utilization,
            (SELECT MIN(s.due_date) FROM credit_card_statements s " . statement_paid_join() . "
              WHERE s.credit_card_id = cc.id AND " . statement_remaining_sql() . " > 0.004) AS next_due
       FROM credit_cards cc " . card_usage_join() . "
      WHERE cc.id IN $myCards
      ORDER BY cc.status = 'active' DESC, cc.card_nickname"
);

$recentTransactions = dashboard_rows(
    "SELECT t.id, t.transaction_number, t.transaction_date, t.amount, t.payment_type, t.status,
            c.card_nickname, c.last_four, m.merchant_name, b.first_name, b.last_name
       FROM transactions t
       JOIN credit_cards c ON c.id = t.credit_card_id
       LEFT JOIN merchants m ON m.id = t.merchant_id
       LEFT JOIN borrowers b ON b.id = t.borrower_id
      WHERE $myTxn
      ORDER BY t.transaction_date DESC, t.created_at DESC
      LIMIT 8"
);

// Installment overview: progress (paid of total), monthly amount, what is left and the next billing period.
$installments = dashboard_rows(
    "SELECT p.id, p.number_of_installments, p.installment_amount, p.total_repayment, p.status,
            t.transaction_number, t.credit_card_id, m.merchant_name, c.card_nickname, c.last_four,
            COALESCE(sc.paid_count, 0) AS paid_count, COALESCE(sc.remaining, 0) AS remaining, sc.next_due
       FROM installment_plans p
       JOIN transactions t ON t.id = p.transaction_id
       JOIN credit_cards c ON c.id = t.credit_card_id
       LEFT JOIN merchants m ON m.id = t.merchant_id
       LEFT JOIN (SELECT installment_plan_id,
                         SUM(status IN ('paid', 'waived') OR remaining_amount <= 0) AS paid_count,
                         SUM(IF(status IN ('waived', 'cancelled'), 0, remaining_amount)) AS remaining,
                         MIN(IF(remaining_amount > 0 AND status NOT IN ('paid', 'waived', 'cancelled'), due_date, NULL)) AS next_due
                    FROM installment_schedules GROUP BY installment_plan_id) sc ON sc.installment_plan_id = p.id
      WHERE t.credit_card_id IN $myCards AND p.status NOT IN ('cancelled', 'completed') AND COALESCE(sc.remaining, 0) > 0
      ORDER BY sc.next_due IS NULL, sc.next_due
      LIMIT 10"
);
// Billing period (statement month) of each next installment, from the card's cutoff day.
$cardContext = [];
foreach ($installments as &$plan) {
    $plan['next_period'] = null;
    if ($plan['next_due']) {
        try {
            $ctx = $cardContext[$plan['credit_card_id']] ??= card_period_context(db_row('SELECT * FROM credit_cards WHERE id = :id', [':id' => $plan['credit_card_id']]));
            $plan['next_period'] = statement_month_label(card_period_containing($ctx, $plan['next_due'])['end']);
        } catch (Throwable $e) {
            $plan['next_period'] = null;   // card without billing settings: shown as the due date only
        }
    }
}
unset($plan);

$pageTitle  = 'Dashboard';
$activeNav  = 'dashboard';
require APP_ROOT . '/includes/header.php';
?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Welcome, <?= e(user_display_name($currentUser)) ?></h2>
        <p class="page-header__subtitle">Your cards and records as of <?= e(date('F j, Y g:i A')) ?>. Only you can see them.</p>
    </div>
    <div class="page-header__actions no-print">
        <a data-modal-form class="btn btn--primary" href="<?= e(url('pages/credit_cards/create.php')) ?>"><?= icon('plus') ?> <span>Add credit card</span></a>
    </div>
</section>

<?php if ($isSystemAdmin && !empty($stats['unowned'])): ?>
    <div class="alert alert--warning"><?= icon('alert') ?><span><?= e($stats['unowned']) ?> record(s) from before multi-user accounts need their owner confirmed.
        <a href="<?= e(url('pages/users/ownership.php')) ?>">Review ownership</a></span></div>
<?php endif; ?>

<?php if ($borrowed): ?>
<section class="panel pay-summary" aria-labelledby="borrowed-title">
    <header class="panel__header">
        <h3 id="borrowed-title"><?= icon('wallet') ?> Borrowed from others — amounts to repay</h3>
        <a class="btn btn--sm btn--outline" href="<?= e(url('pages/borrowed/index.php')) ?>">View all</a>
    </header>
    <div class="pay-cards">
        <?php foreach ([
            ['Total borrowed', format_money($borrowed['responsible']), $borrowed['count'] . ' purchase(s) on other users\' cards', 'primary', 'credit-card'],
            ['Outstanding to repay', format_money($borrowed['outstanding']), format_money($borrowed['repaid']) . ' repaid (confirmed) · ' . format_money($borrowed['reported']) . ' reported, not confirmed', 'warning', 'wallet'],
            ['Upcoming deadlines', (string) $borrowed['upcoming'], $borrowed['next_due'] ? 'next on ' . format_date($borrowed['next_due']) : 'none', 'info', 'calendar'],
            ['Overdue', (string) $borrowed['overdue'], $borrowed['overdue'] ? format_money($borrowed['overdue_amount']) . ' past due' : 'nothing overdue', 'danger', 'alert'],
            ['Repayment status', $borrowed['open'] ? $borrowed['open'] . ' open' : 'All repaid', ($borrowed['count'] - $borrowed['open']) . ' fully repaid', $borrowed['open'] ? 'warning' : 'info', 'check'],
        ] as [$label, $value, $meta, $tone, $iconName]): ?>
            <a class="pay-card pay-card--<?= e($tone) ?>" href="<?= e(url('pages/borrowed/index.php')) ?>">
                <span class="pay-card__icon"><?= icon($iconName) ?></span>
                <span class="pay-card__label"><?= e($label) ?></span>
                <span class="pay-card__value"><?= e($value) ?></span>
                <span class="pay-card__meta"><?= e($meta) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php if ($borrowedOpen): ?>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Purchase</th><th scope="col">Card owner</th><th scope="col" class="num">Remaining</th><th scope="col">Repay by</th><th scope="col">Status</th><th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($borrowedOpen as $row): ?>
                    <tr>
                        <td data-label="Purchase"><a data-modal-form class="table-link" href="<?= e(url('pages/borrowed/view.php', ['id' => $row['id']])) ?>"><?= e($row['merchant_name'] ?: $row['transaction_number']) ?></a>
                            <span class="text-muted"><?= e(format_date($row['transaction_date'])) ?> · <?= e($row['issuer']) ?> •••• <?= e($row['last_four']) ?></span></td>
                        <td data-label="Card owner"><?= e($row['lender_name'] ?? '—') ?></td>
                        <td data-label="Remaining" class="num"><?= e(format_money($row['ledger']['outstanding'])) ?></td>
                        <td data-label="Repay by"><?= e(borrowed_due_text($row['ledger'])) ?></td>
                        <td data-label="Status"><?= borrowed_status_badge($row['ledger']['status']) ?></td>
                        <td class="actions no-print"><?php if ($row['ledger']['outstanding'] - $row['ledger']['reported'] > 0.004): ?>
                            <a data-modal-form href="<?= e(url('pages/borrowed/repay.php', ['id' => $row['id']])) ?>" class="btn btn--sm btn--primary"><?= icon('wallet') ?> <span>Repay</span></a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel pay-summary" aria-labelledby="card-pay-title">
    <header class="panel__header">
        <h3 id="card-pay-title"><?= icon('credit-card') ?> My credit cards and payments</h3>
        <a class="btn btn--sm btn--outline" href="<?= e(url('pages/statements/index.php')) ?>">Statements</a>
    </header>
    <div class="pay-cards">
        <?php
        $limit = (float) $stats['cards_limit'];
        $payCards = [
            ['My credit cards', $cardPay['cards'], $cardPay['active_cards'] . ' active', url('pages/credit_cards/index.php'), 'primary', 'credit-card'],
            ['Total credit limit', format_money($stats['cards_limit']), 'on active cards', url('pages/reports/card_utilization.php'), 'primary', 'chart'],
            ['Total outstanding balance', format_money($stats['cards_used']), 'used credit (' . number_format($limit > 0 ? (float) $stats['cards_used'] / $limit * 100 : 0, 2) . '% of limit) · ' . format_money($cardPay['outstanding']) . ' left on SOAs', url('pages/reports/card_utilization.php'), 'primary', 'banknote'],
            ['Pending SOAs', $cardPay['awaiting_soa'], 'closed periods, bank SOA not entered yet', url('pages/statements/index.php'), 'info', 'file-text'],
            ['Pending payments', $cardPay['pending'], 'SOAs with nothing paid yet · ' . $cardPay['unpaid'] . ' unpaid on ' . $cardPay['unpaid_cards'] . ' card(s)', url('pages/statements/index.php', ['status' => 'unpaid']), 'warning', 'history'],
            ['Partially paid', $cardPay['partial'], 'payment recorded, balance left', url('pages/statements/index.php', ['status' => 'partially_paid']), 'info', 'wallet'],
            ['Upcoming', $cardPay['upcoming'], 'due in the next ' . STATEMENT_UPCOMING_DAYS . ' days', url('pages/statements/index.php', ['status' => 'upcoming']), 'warning', 'calendar'],
            ['Overdue', $cardPay['overdue'], $cardPay['overdue'] ? format_money($cardPay['overdue_amount']) . ' on ' . $cardPay['overdue_cards'] . ' card(s)' : 'nothing overdue', url('pages/statements/index.php', ['status' => 'overdue']), 'danger', 'alert'],
            ['Total minimum due', format_money($cardPay['minimum_due']), 'still needed to cover the minimums of unpaid SOAs', url('pages/statements/index.php', ['status' => 'unpaid']), 'warning', 'receipt'],
        ];
        foreach ($payCards as [$label, $value, $meta, $href, $tone, $iconName]): ?>
            <a class="pay-card pay-card--<?= e($tone) ?>" href="<?= e($href) ?>">
                <span class="pay-card__icon"><?= icon($iconName) ?></span>
                <span class="pay-card__label"><?= e($label) ?></span>
                <span class="pay-card__value"><?= e(is_numeric($value) ? stat_number((int) $value) : $value) ?></span>
                <span class="pay-card__meta"><?= e($meta) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <h4 class="pay-summary__title">Upcoming and overdue payments</h4>
    <?php if (!$dueSoon): ?>
        <p class="empty-inline">Nothing overdue or due in the next <?= e(STATEMENT_UPCOMING_DAYS) ?> days.</p>
    <?php else: ?>
        <?= render_statement_rows($dueSoon) ?>
    <?php endif; ?>
</section>

<section class="panel" aria-labelledby="my-cards-title">
    <header class="panel__header">
        <h3 id="my-cards-title"><?= icon('credit-card') ?> My credit cards</h3>
        <a class="btn btn--sm btn--outline" href="<?= e(url('pages/credit_cards/index.php')) ?>">View all</a>
    </header>
    <?php if (!$cards): ?>
        <p class="empty-inline">You have not added a credit card yet. <a data-modal-form href="<?= e(url('pages/credit_cards/create.php')) ?>">Add your first card</a> to start tracking statements and payments.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Card</th><th scope="col">Card number</th><th scope="col" class="num">Credit limit</th><th scope="col" class="num">Used</th>
                    <th scope="col" class="num">Available</th><th scope="col">Utilization</th><th scope="col">Next due</th><th scope="col">Status</th></tr></thead>
                <tbody>
                <?php foreach ($cards as $card): ?>
                    <?php $pct = max(0, min(100, (float) $card['utilization'])); ?>
                    <tr>
                        <td data-label="Card"><a data-modal-form class="table-link" href="<?= e(url('pages/credit_cards/view.php', ['id' => $card['id']])) ?>"><?= e($card['card_nickname']) ?></a>
                            <span class="text-muted"><?= e($card['issuer']) ?></span></td>
                        <td data-label="Card number"><span class="mono"><?= e(masked_card($card['last_four'])) ?></span></td>
                        <td data-label="Credit limit" class="num"><?= e(format_money($card['credit_limit'])) ?></td>
                        <td data-label="Used" class="num"><?= e(format_money($card['used'])) ?></td>
                        <td data-label="Available" class="num"><?= e(format_money($card['available'])) ?></td>
                        <td data-label="Utilization"><progress class="meter" max="100" value="<?= e($pct) ?>"><?= e($pct) ?>%</progress> <span class="text-muted"><?= e(number_format((float) $card['utilization'], 2)) ?>%</span></td>
                        <td data-label="Next due"><?= $card['next_due'] ? e(format_date($card['next_due'])) : '<span class="text-muted">—</span>' ?></td>
                        <td data-label="Status"><?= badge($card['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<div class="panel-grid">
    <section class="panel" aria-labelledby="recent-txn-title">
        <header class="panel__header">
            <h3 id="recent-txn-title"><?= icon('layers') ?> Recent transactions</h3>
            <a class="btn btn--sm btn--outline" href="<?= e(url('pages/transactions/index.php')) ?>">View all</a>
        </header>
        <?php if (!$recentTransactions): ?>
            <p class="empty-inline">No transactions yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--cards table--compact">
                    <thead><tr><th scope="col">Date</th><th scope="col">Merchant</th><th scope="col">Card</th><th scope="col">For</th><th scope="col" class="num">Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentTransactions as $row): ?>
                        <tr>
                            <td data-label="Date"><?= e(format_date($row['transaction_date'])) ?></td>
                            <td data-label="Merchant"><a data-modal-form class="table-link" href="<?= e(url('pages/transactions/view.php', ['id' => $row['id']])) ?>"><?= e($row['merchant_name'] ?: $row['transaction_number']) ?></a>
                                <span class="text-muted"><?= e(humanize($row['payment_type'])) ?></span></td>
                            <td data-label="Card"><?= e($row['card_nickname']) ?> <span class="text-muted">•••• <?= e($row['last_four']) ?></span></td>
                            <td data-label="For"><?= $row['first_name'] ? e(borrower_name($row)) : '<span class="text-muted">Me</span>' ?></td>
                            <td data-label="Amount" class="num"><?= e(format_money($row['amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel" aria-labelledby="installments-title">
        <header class="panel__header">
            <h3 id="installments-title"><?= icon('repeat') ?> Installment overview</h3>
            <a class="btn btn--sm btn--outline" href="<?= e(url('pages/installment_plans/index.php')) ?>">View all</a>
        </header>
        <?php if (!$installments): ?>
            <p class="empty-inline">No installment plans running.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--cards table--compact">
                    <thead><tr><th scope="col">Purchase</th><th scope="col">Progress</th><th scope="col" class="num">Monthly</th><th scope="col" class="num">Remaining</th><th scope="col">Next billing</th></tr></thead>
                    <tbody>
                    <?php foreach ($installments as $plan): ?>
                        <?php $count = max(1, (int) $plan['number_of_installments']); ?>
                        <tr>
                            <td data-label="Purchase"><a data-modal-form class="table-link" href="<?= e(url('pages/installment_plans/view.php', ['id' => $plan['id']])) ?>"><?= e($plan['merchant_name'] ?: $plan['transaction_number']) ?></a>
                                <span class="text-muted"><?= e($plan['card_nickname']) ?> •••• <?= e($plan['last_four']) ?></span></td>
                            <td data-label="Progress"><strong><?= e((int) $plan['paid_count'] . '/' . $count) ?></strong>
                                <progress class="meter" max="<?= e($count) ?>" value="<?= e((int) $plan['paid_count']) ?>"><?= e((int) $plan['paid_count']) ?> of <?= e($count) ?></progress></td>
                            <td data-label="Monthly" class="num"><?= e(format_money($plan['installment_amount'])) ?></td>
                            <td data-label="Remaining" class="num"><?= e(format_money($plan['remaining'])) ?></td>
                            <td data-label="Next billing"><?= $plan['next_period'] ? e($plan['next_period']) . '<br>' : '' ?><span class="text-muted"><?= $plan['next_due'] ? 'due ' . e(format_date($plan['next_due'])) : '—' ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<section class="stat-grid" aria-label="Summary statistics">
    <article class="stat-card">
        <div class="stat-card__icon stat-card__icon--primary"><?= icon('users') ?></div>
        <div>
            <p class="stat-card__label"><a href="<?= e(url('pages/borrowers/index.php')) ?>">Borrowers</a></p>
            <p class="stat-card__value"><?= e(stat_number($stats['borrowers'])) ?></p>
            <p class="stat-card__meta">
                <?= e(stat_number($stats['borrowers_good'])) ?> in good standing ·
                <?= e(stat_number($stats['borrowers_late'])) ?> late/delinquent
            </p>
        </div>
    </article>

    <article class="stat-card">
        <div class="stat-card__icon stat-card__icon--primary"><?= icon('layers') ?></div>
        <div>
            <p class="stat-card__label"><a href="<?= e(url('pages/transactions/index.php')) ?>">Transactions</a></p>
            <p class="stat-card__value"><?= e(stat_number($stats['transactions'])) ?></p>
            <p class="stat-card__meta"><?= e(stat_number($stats['transactions_month'])) ?> this month</p>
        </div>
    </article>

    <article class="stat-card">
        <div class="stat-card__icon stat-card__icon--warning"><?= icon('wallet') ?></div>
        <div>
            <p class="stat-card__label"><a href="<?= e(url('pages/reports/borrower_balances.php')) ?>">Total to collect</a></p>
            <p class="stat-card__value stat-card__value--money"><?= $collect['total_due'] === null ? '—' : e(format_money((float) $collect['total_due'] + $purchaseTotals['outstanding'])) ?></p>
            <p class="stat-card__meta"><?= $collect['total_due'] === null ? '' : e(format_money((float) $collect['outstanding'] + $purchaseTotals['remaining'])) . ' outstanding + ' . e(format_money((float) $collect['penalties'] + $purchaseTotals['penalties'])) . ' penalties' ?></p>
        </div>
    </article>

    <article class="stat-card">
        <div class="stat-card__icon stat-card__icon--warning"><?= icon('receipt') ?></div>
        <div>
            <p class="stat-card__label"><a href="<?= e(url('pages/borrower_payments/index.php', ['status' => 'pending'])) ?>">Pending payment approvals</a></p>
            <p class="stat-card__value"><?= e(stat_number($stats['pending_payments'])) ?></p>
            <p class="stat-card__meta">Submitted, under review or needing correction</p>
        </div>
    </article>

    <article class="stat-card">
        <div class="stat-card__icon stat-card__icon--danger"><?= icon('alert') ?></div>
        <div>
            <p class="stat-card__label"><a href="<?= e(url('pages/reports/overdue.php')) ?>">Overdue obligations</a></p>
            <p class="stat-card__value"><?= e(stat_number($stats['overdue'])) ?></p>
            <p class="stat-card__meta">
                <?= $stats['overdue_amount'] === null ? '—' : e(format_money($stats['overdue_amount'])) ?> overdue
            </p>
        </div>
    </article>

    <?php if ($isSystemAdmin): ?>
    <article class="stat-card">
        <div class="stat-card__icon stat-card__icon--info"><?= icon('shield') ?></div>
        <div>
            <p class="stat-card__label"><a href="<?= e(url('pages/users/index.php')) ?>">User accounts</a></p>
            <p class="stat-card__value"><?= e(stat_number($stats['users_total'])) ?></p>
            <p class="stat-card__meta"><?= e(stat_number($stats['users_active'])) ?> active</p>
        </div>
    </article>
    <?php endif; ?>

    <article class="stat-card">
        <div class="stat-card__icon stat-card__icon--success"><?= icon('tag') ?></div>
        <div>
            <p class="stat-card__label">Categories</p>
            <p class="stat-card__value"><?= e(stat_number($stats['categories_active'])) ?></p>
            <p class="stat-card__meta"><?= e(stat_number($stats['categories_inactive'])) ?> inactive ·
                <a href="<?= e(url('pages/categories/index.php')) ?>">Manage</a></p>
        </div>
    </article>
</section>

<section class="panel balance-panel" aria-labelledby="balances-title">
    <header class="panel__header">
        <h3 id="balances-title"><?= icon('users') ?> Who owes me (outstanding + penalties)</h3>
        <a class="btn btn--sm btn--outline" href="<?= e(url('pages/reports/borrower_balances.php')) ?>">View all</a>
    </header>
    <?php if (!$borrowerBalances): ?>
        <p class="empty-inline">No borrower owes anything right now.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Borrower</th><th scope="col" class="num">Outstanding</th><th scope="col" class="num">Penalties</th><th scope="col" class="num">Total to collect</th>
                    <th scope="col">Next due</th><th scope="col">Repayment status</th></tr></thead>
                <tbody>
                <?php foreach ($borrowerBalances as $row): ?>
                    <tr>
                        <td data-label="Borrower"><a data-modal-form class="table-link" href="<?= e(url('pages/borrowers/view.php', ['id' => $row['id']])) ?>"><?= e(borrower_name($row)) ?></a>
                            <span class="text-muted"><?= e($row['borrower_number']) ?></span><?php if ((int) $row['has_account'] > 0): ?> <span class="chip">has an account</span><?php endif; ?></td>
                        <td data-label="Outstanding" class="num"><?= e(format_money($row['outstanding'])) ?></td>
                        <td data-label="Penalties" class="num<?= (float) $row['penalties'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($row['penalties'])) ?></td>
                        <td data-label="Total to collect" class="num"><strong><?= e(format_money($row['total_due'])) ?></strong></td>
                        <td data-label="Next due"><?= $row['next_due'] ? e(format_date($row['next_due'])) : '<span class="text-muted">—</span>' ?></td>
                        <td data-label="Repayment status">
                            <?= borrowed_status_badge($row['repay_status']) ?>
                            <?php if ($row['review']): ?><br><span class="text-danger">overdue, no penalty rule: review</span><?php endif; ?>
                            <?php if ((int) $row['to_confirm'] > 0): ?><br><a data-modal-form href="<?= e(url('pages/borrower_payments/index.php', ['status' => 'pending'])) ?>"><?= e($row['to_confirm']) ?> repayment(s) to confirm</a><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td>All my borrowers</td><td class="num"><?= e(format_money((float) $collect['outstanding'] + $purchaseTotals['remaining'])) ?></td><td class="num"><?= e(format_money((float) $collect['penalties'] + $purchaseTotals['penalties'])) ?></td><td class="num"><strong><?= e(format_money((float) $collect['total_due'] + $purchaseTotals['outstanding'])) ?></strong></td><td colspan="2"></td></tr></tfoot>
            </table>
        </div>
    <?php endif; ?>
</section>

<div class="panel-grid">
    <section class="panel" aria-labelledby="pending-title">
        <header class="panel__header">
            <h3 id="pending-title"><?= icon('receipt') ?> Pending payment approvals</h3>
            <a class="btn btn--sm btn--outline" href="<?= e(url('pages/borrower_payments/index.php', ['status' => 'pending'])) ?>">View all</a>
        </header>
        <?php if (!$pendingPayments): ?>
            <p class="empty-inline">No payments are waiting for review.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--compact">
                    <thead>
                    <tr><th scope="col">Payment #</th><th scope="col">Borrower</th><th scope="col">Date</th><th scope="col" class="num">Amount</th><th scope="col">Status</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendingPayments as $row): ?>
                        <tr>
                            <td><a data-modal-form href="<?= e(url('pages/borrower_payments/view.php', ['id' => $row['id']])) ?>"><?= e($row['payment_number']) ?></a></td>
                            <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                            <td><?= e(format_date($row['payment_date'])) ?></td>
                            <td class="num"><?= e(format_money($row['amount'])) ?></td>
                            <td><span class="status status--warning"><?= e(str_replace('_', ' ', $row['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel" aria-labelledby="overdue-title">
        <header class="panel__header">
            <h3 id="overdue-title"><?= icon('alert') ?> Most overdue obligations</h3>
            <a class="btn btn--sm btn--outline" href="<?= e(url('pages/reports/overdue.php')) ?>">View report</a>
        </header>
        <?php if (!$overdueObligations): ?>
            <p class="empty-inline">No overdue obligations.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--compact">
                    <thead>
                    <tr><th scope="col">Obligation #</th><th scope="col">Borrower</th><th scope="col">Due</th><th scope="col" class="num">Days</th><th scope="col" class="num">Outstanding</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($overdueObligations as $row): ?>
                        <tr>
                            <td><a data-modal-form href="<?= e(url('pages/obligations/view.php', ['id' => $row['id']])) ?>"><?= e($row['obligation_number']) ?></a></td>
                            <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                            <td><?= e(format_date($row['due_date'])) ?></td>
                            <td class="num"><?= e($row['overdue_days']) ?></td>
                            <td class="num"><?= e(format_money($row['outstanding_amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel" aria-labelledby="activity-title">
        <header class="panel__header">
            <h3 id="activity-title"><?= icon('history') ?> Recent activity</h3>
            <a class="btn btn--sm btn--outline" href="<?= e(url('pages/activity_logs/index.php')) ?>">View all</a>
        </header>
        <?php if (!$recentActivity): ?>
            <p class="empty-inline">No activity recorded yet.</p>
        <?php else: ?>
            <ul class="activity-list">
                <?php foreach ($recentActivity as $log): ?>
                    <li>
                        <span class="activity-list__dot" aria-hidden="true"></span>
                        <div>
                            <p><strong><?= e($log['actor']) ?></strong>
                                <?= e(str_replace('_', ' ', $log['action'])) ?> · <?= e($log['entity_type']) ?></p>
                            <?php if (!empty($log['description'])): ?>
                                <p class="text-muted"><?= e(excerpt($log['description'], 90)) ?></p>
                            <?php endif; ?>
                            <time class="text-muted" datetime="<?= e($log['created_at']) ?>"><?= e(format_datetime($log['created_at'])) ?></time>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
