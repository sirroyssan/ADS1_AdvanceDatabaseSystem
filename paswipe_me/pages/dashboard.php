<?php
/**
 * Admin dashboard. Every number comes from a live query on the schema's
 * tables and reporting views - nothing here is sample data.
 */

require_once __DIR__ . '/../includes/auth_check.php';

$currentUser = require_admin();

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

$stats = [
    'borrowers'          => dashboard_value('SELECT COUNT(*) FROM borrowers'),
    'borrowers_good'     => dashboard_value("SELECT COUNT(*) FROM borrowers WHERE status = 'good_standing'"),
    'borrowers_late'     => dashboard_value("SELECT COUNT(*) FROM borrowers WHERE status IN ('late', 'delinquent')"),
    'cards_active'       => dashboard_value("SELECT COUNT(*) FROM credit_cards WHERE status = 'active'"),
    'cards_total'        => dashboard_value('SELECT COUNT(*) FROM credit_cards'),
    'cards_used'         => dashboard_value('SELECT COALESCE(SUM(outstanding), 0) FROM (' . card_utilization_sql() . ') v'),
    'cards_limit'        => dashboard_value('SELECT COALESCE(SUM(credit_limit), 0) FROM (' . card_utilization_sql() . ') v'),
    'transactions'       => dashboard_value('SELECT COUNT(*) FROM transactions'),
    'transactions_month' => dashboard_value(
        "SELECT COUNT(*) FROM transactions
          WHERE transaction_date >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')"
    ),
    'outstanding'        => dashboard_value(
        "SELECT COALESCE(SUM(outstanding_amount), 0) FROM borrower_obligations
          WHERE status NOT IN ('cancelled', 'paid', 'written_off')"
    ),
    'pending_payments'   => dashboard_value('SELECT COUNT(*) FROM vw_pending_payment_approvals'),
    'overdue'            => dashboard_value('SELECT COUNT(*) FROM vw_overdue_obligations'),
    'overdue_amount'     => dashboard_value('SELECT COALESCE(SUM(overdue_amount), 0) FROM vw_overdue_obligations'),
    'users_total'        => dashboard_value('SELECT COUNT(*) FROM users'),
    'users_active'       => dashboard_value("SELECT COUNT(*) FROM users WHERE status = 'active'"),
    'categories_active'  => dashboard_value('SELECT COUNT(*) FROM categories WHERE is_active = 1'),
    'categories_inactive'=> dashboard_value('SELECT COUNT(*) FROM categories WHERE is_active = 0'),
];

$pendingPayments = dashboard_rows(
    'SELECT id, payment_number, first_name, last_name, payment_date, amount, display_name, status
       FROM vw_pending_payment_approvals
      ORDER BY submitted_at ASC
      LIMIT 5'
);

// Outstanding + unpaid penalties = total to collect (includes/penalties.php).
try {
    $collect = collection_totals();
} catch (PDOException $e) {
    $collect = ['outstanding' => null, 'penalties' => null, 'total_due' => null];
}
$borrowerBalances = dashboard_rows(
    'SELECT b.id, b.borrower_number, b.first_name, b.last_name, bt.outstanding, bt.penalties, bt.total_due
       FROM (' . borrower_totals_sql() . ') bt JOIN borrowers b ON b.id = bt.borrower_id
      WHERE bt.total_due > 0
      ORDER BY bt.total_due DESC
      LIMIT 8'
);

$overdueObligations = dashboard_rows(
    'SELECT id, obligation_number, first_name, last_name, outstanding_amount, due_date, overdue_days
       FROM vw_overdue_obligations
      ORDER BY overdue_days DESC
      LIMIT 5'
);

$cardUtilization = dashboard_rows(
    'SELECT card_nickname, issuer, last_four, credit_limit, outstanding, available_credit, utilization_percentage
       FROM (' . card_utilization_sql() . ') v
      ORDER BY utilization_percentage DESC
      LIMIT 5'
);

$recentActivity = dashboard_rows(
    "SELECT a.action, a.entity_type, a.description, a.created_at,
            COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, u.email, 'System') AS actor
       FROM activity_logs a
       LEFT JOIN users u ON u.id = a.actor_user_id
      ORDER BY a.created_at DESC
      LIMIT 8"
);

// Credit card payments: recorded SOAs and the payments linked to them (each statement once).
$cardPay = statement_payment_summary();
$dueSoon = db_all(
    'SELECT s.id, s.cutoff_date, s.statement_date, s.due_date, s.total_amount_due, s.minimum_amount_due, c.card_nickname, c.issuer, c.last_four,
            COALESCE(sp.paid, 0) AS paid, ' . statement_remaining_sql() . ' AS remaining, ' . statement_status_sql() . ' AS pay_status
       FROM credit_card_statements s JOIN credit_cards c ON c.id = s.credit_card_id ' . statement_paid_join() . '
      WHERE s.soa_recorded_at IS NOT NULL AND ' . statement_remaining_sql() . ' > 0.004 AND s.due_date <= CURDATE() + INTERVAL ' . STATEMENT_UPCOMING_DAYS . ' DAY
      ORDER BY s.due_date
      LIMIT 10'
);

$pageTitle  = 'Dashboard';
$activeNav  = 'dashboard';
require APP_ROOT . '/includes/header.php';
?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Welcome, <?= e(user_display_name($currentUser)) ?></h2>
        <p class="page-header__subtitle">Overview of <?= e(APP_NAME) ?> as of <?= e(date('F j, Y g:i A')) ?>.</p>
    </div>
</section>

<section class="panel pay-summary" aria-labelledby="card-pay-title">
    <header class="panel__header">
        <h3 id="card-pay-title"><?= icon('credit-card') ?> Credit card payments</h3>
        <a class="btn btn--sm btn--outline" href="<?= e(url('pages/statements/index.php')) ?>">Statements</a>
    </header>
    <div class="pay-cards">
        <?php
        $payCards = [
            ['Registered credit cards', $cardPay['cards'], $cardPay['active_cards'] . ' active', url('pages/credit_cards/index.php'), 'primary', 'credit-card'],
            ['Pending payments', $cardPay['unpaid'], 'statements to pay on ' . $cardPay['unpaid_cards'] . ' card(s) · ' . $cardPay['pending'] . ' with nothing paid yet', url('pages/statements/index.php', ['status' => 'unpaid']), 'warning', 'history'],
            ['Partially paid statements', $cardPay['partial'], 'payment recorded, balance left', url('pages/statements/index.php', ['status' => 'partially_paid']), 'info', 'wallet'],
            ['Total outstanding statement balance', format_money($cardPay['outstanding']), 'left to pay on recorded SOAs', url('pages/statements/index.php', ['status' => 'unpaid', 'sort' => 'amount']), 'primary', 'banknote'],
            ['Upcoming payments', $cardPay['upcoming'], 'due in the next ' . STATEMENT_UPCOMING_DAYS . ' days', url('pages/statements/index.php', ['status' => 'upcoming']), 'warning', 'calendar'],
            ['SOA to record', $cardPay['awaiting_soa'], 'closed periods prepared, bank SOA not entered yet', url('pages/statements/index.php'), 'info', 'file-text'],
            ['Overdue payments', $cardPay['overdue'], $cardPay['overdue'] ? format_money($cardPay['overdue_amount']) . ' on ' . $cardPay['overdue_cards'] . ' card(s)' : 'nothing overdue', url('pages/statements/index.php', ['status' => 'overdue']), 'danger', 'alert'],
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
        <div class="stat-card__icon stat-card__icon--info"><?= icon('credit-card') ?></div>
        <div>
            <p class="stat-card__label"><a href="<?= e(url('pages/credit_cards/index.php')) ?>">Active credit cards</a></p>
            <p class="stat-card__value"><?= e(stat_number($stats['cards_active'])) ?></p>
            <p class="stat-card__meta"><?= e(format_money($stats['cards_used'])) ?> used of <?= e(format_money($stats['cards_limit'])) ?>
                (<?= e(number_format((float) $stats['cards_limit'] > 0 ? (float) $stats['cards_used'] / (float) $stats['cards_limit'] * 100 : 0, 2)) ?>%)</p>
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
            <p class="stat-card__value stat-card__value--money"><?= $collect['total_due'] === null ? '—' : e(format_money($collect['total_due'])) ?></p>
            <p class="stat-card__meta"><?= $collect['total_due'] === null ? '' : e(format_money($collect['outstanding'])) . ' outstanding + ' . e(format_money($collect['penalties'])) . ' penalties' ?></p>
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

    <article class="stat-card">
        <div class="stat-card__icon stat-card__icon--info"><?= icon('shield') ?></div>
        <div>
            <p class="stat-card__label"><a href="<?= e(url('pages/users/index.php')) ?>">User accounts</a></p>
            <p class="stat-card__value"><?= e(stat_number($stats['users_total'])) ?></p>
            <p class="stat-card__meta"><?= e(stat_number($stats['users_active'])) ?> active</p>
        </div>
    </article>

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
        <h3 id="balances-title"><?= icon('users') ?> What borrowers owe (outstanding + penalties)</h3>
        <a class="btn btn--sm btn--outline" href="<?= e(url('pages/reports/borrower_balances.php')) ?>">View all</a>
    </header>
    <?php if (!$borrowerBalances): ?>
        <p class="empty-inline">No borrower owes anything right now.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Borrower</th><th scope="col" class="num">Outstanding</th><th scope="col" class="num">Penalties</th><th scope="col" class="num">Total to collect</th></tr></thead>
                <tbody>
                <?php foreach ($borrowerBalances as $row): ?>
                    <tr>
                        <td data-label="Borrower"><a data-modal-form class="table-link" href="<?= e(url('pages/borrowers/view.php', ['id' => $row['id']])) ?>"><?= e(borrower_name($row)) ?></a>
                            <span class="text-muted"><?= e($row['borrower_number']) ?></span></td>
                        <td data-label="Outstanding" class="num"><?= e(format_money($row['outstanding'])) ?></td>
                        <td data-label="Penalties" class="num<?= (float) $row['penalties'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($row['penalties'])) ?></td>
                        <td data-label="Total to collect" class="num"><strong><?= e(format_money($row['total_due'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td>All borrowers</td><td class="num"><?= e(format_money($collect['outstanding'])) ?></td><td class="num"><?= e(format_money($collect['penalties'])) ?></td><td class="num"><strong><?= e(format_money($collect['total_due'])) ?></strong></td></tr></tfoot>
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

    <section class="panel" aria-labelledby="cards-title">
        <header class="panel__header">
            <h3 id="cards-title"><?= icon('credit-card') ?> Credit card utilization</h3>
            <a class="btn btn--sm btn--outline" href="<?= e(url('pages/reports/card_utilization.php')) ?>">View report</a>
        </header>
        <?php if (!$cardUtilization): ?>
            <p class="empty-inline">No active credit cards.</p>
        <?php else: ?>
            <ul class="usage-list">
                <?php foreach ($cardUtilization as $card): ?>
                    <?php $pct = max(0, min(100, (float) $card['utilization_percentage'])); ?>
                    <li>
                        <div class="usage-list__head">
                            <span><strong><?= e($card['card_nickname']) ?></strong> · <?= e($card['issuer']) ?> •••• <?= e($card['last_four']) ?></span>
                            <span><?= e(number_format((float) $card['utilization_percentage'], 2)) ?>%</span>
                        </div>
                        <progress class="meter" max="100" value="<?= e($pct) ?>"><?= e($pct) ?>%</progress>
                        <p class="usage-list__meta"><?= e(format_money($card['outstanding'])) ?> used of <?= e(format_money($card['credit_limit'])) ?> · <?= e(format_money($card['available_credit'])) ?> available</p>
                    </li>
                <?php endforeach; ?>
            </ul>
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
