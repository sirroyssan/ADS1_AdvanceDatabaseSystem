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
    'SELECT payment_number, first_name, last_name, payment_date, amount, display_name, status
       FROM vw_pending_payment_approvals
      ORDER BY submitted_at ASC
      LIMIT 5'
);

$overdueObligations = dashboard_rows(
    'SELECT obligation_number, first_name, last_name, outstanding_amount, due_date, overdue_days
       FROM vw_overdue_obligations
      ORDER BY overdue_days DESC
      LIMIT 5'
);

$cardUtilization = dashboard_rows(
    'SELECT card_nickname, issuer, last_four, credit_limit, outstanding, utilization_percentage
       FROM vw_credit_card_utilization
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

<section class="stat-grid" aria-label="Summary statistics">
    <article class="stat-card">
        <div class="stat-card__icon stat-card__icon--primary"><?= icon('users') ?></div>
        <div>
            <p class="stat-card__label">Borrowers</p>
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
            <p class="stat-card__label">Active credit cards</p>
            <p class="stat-card__value"><?= e(stat_number($stats['cards_active'])) ?></p>
            <p class="stat-card__meta"><?= e(stat_number($stats['cards_total'])) ?> cards on record</p>
        </div>
    </article>

    <article class="stat-card">
        <div class="stat-card__icon stat-card__icon--primary"><?= icon('layers') ?></div>
        <div>
            <p class="stat-card__label">Transactions</p>
            <p class="stat-card__value"><?= e(stat_number($stats['transactions'])) ?></p>
            <p class="stat-card__meta"><?= e(stat_number($stats['transactions_month'])) ?> this month</p>
        </div>
    </article>

    <article class="stat-card">
        <div class="stat-card__icon stat-card__icon--warning"><?= icon('wallet') ?></div>
        <div>
            <p class="stat-card__label">Outstanding balance</p>
            <p class="stat-card__value"><?= $stats['outstanding'] === null ? '—' : e(format_money($stats['outstanding'])) ?></p>
            <p class="stat-card__meta">Open borrower obligations</p>
        </div>
    </article>

    <article class="stat-card">
        <div class="stat-card__icon stat-card__icon--warning"><?= icon('receipt') ?></div>
        <div>
            <p class="stat-card__label">Pending payment approvals</p>
            <p class="stat-card__value"><?= e(stat_number($stats['pending_payments'])) ?></p>
            <p class="stat-card__meta">Submitted, under review or needing correction</p>
        </div>
    </article>

    <article class="stat-card">
        <div class="stat-card__icon stat-card__icon--danger"><?= icon('alert') ?></div>
        <div>
            <p class="stat-card__label">Overdue obligations</p>
            <p class="stat-card__value"><?= e(stat_number($stats['overdue'])) ?></p>
            <p class="stat-card__meta">
                <?= $stats['overdue_amount'] === null ? '—' : e(format_money($stats['overdue_amount'])) ?> overdue
            </p>
        </div>
    </article>

    <article class="stat-card">
        <div class="stat-card__icon stat-card__icon--info"><?= icon('shield') ?></div>
        <div>
            <p class="stat-card__label">User accounts</p>
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

<div class="panel-grid">
    <section class="panel" aria-labelledby="pending-title">
        <header class="panel__header">
            <h3 id="pending-title"><?= icon('receipt') ?> Pending payment approvals</h3>
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
                            <td><?= e($row['payment_number']) ?></td>
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
                            <td><?= e($row['obligation_number']) ?></td>
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
                            <span><?= e(number_format($pct, 1)) ?>%</span>
                        </div>
                        <progress class="meter" max="100" value="<?= e($pct) ?>"><?= e($pct) ?>%</progress>
                        <p class="usage-list__meta"><?= e(format_money($card['outstanding'])) ?> used of <?= e(format_money($card['credit_limit'])) ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel" aria-labelledby="activity-title">
        <header class="panel__header">
            <h3 id="activity-title"><?= icon('history') ?> Recent activity</h3>
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
