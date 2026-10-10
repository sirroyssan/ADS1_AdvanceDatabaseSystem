<?php
/**
 * Statements - the registered credit cards and, for the chosen card, its
 * billing periods with the records already in the database and the bank's
 * SOA where recorded. Nothing is generated or written here.
 *
 * With a payment filter (?status=…, e.g. from the dashboard) it lists the
 * recorded SOAs of all cards that match instead.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/statement_functions.php';

$currentUser = require_admin();

const STATEMENT_FILTERS = [
    'unpaid'         => 'Needs payment (pending, partial, overdue)',
    'pending'        => 'Pending — nothing paid yet',
    'partially_paid' => 'Partially paid',
    'upcoming'       => 'Due in the next ' . STATEMENT_UPCOMING_DAYS . ' days',
    'overdue'        => 'Overdue',
    'paid'           => 'Paid',
];

$state = list_state([
    'path'    => 'pages/statements/index.php',
    'title'   => 'Credit Card Statements',
    'search'  => 'Search card, issuer or statement number…',
    'filters' => [
        'card'   => ['label' => 'Card', 'type' => 'select', 'options' => credit_card_options(null, false), 'all' => 'All cards'],
        'status' => ['label' => 'Payment', 'type' => 'select', 'options' => STATEMENT_FILTERS, 'all' => 'Any payment status'],
    ],
    'sorts'   => [
        'due'    => ['Due date', 's.due_date ASC'],
        'newest' => ['Newest statement first', 's.cutoff_date DESC'],
        'amount' => ['Highest remaining', 'remaining DESC'],
    ],
]);

$remaining = statement_remaining_sql();
$paid = 'COALESCE(sp.paid, 0)';
$listMode = $state['filters']['status'] !== '' || $state['search'] !== '';

if ($listMode) {
    // Recorded SOAs of all cards - one query with the payments grouped once.
    $where = [];
    $params = [];
    add_search_condition($state, ['c.card_nickname', 'c.issuer', 'c.last_four', 's.statement_number'], $where, $params);
    if ($state['filters']['card'] !== '') {
        $where[] = 's.credit_card_id = :card';
        $params[':card'] = $state['filters']['card'];
    }
    $where[] = match ($state['filters']['status']) {
        'unpaid'         => "$remaining > 0.004",
        'pending'        => "$remaining > 0.004 AND $paid = 0 AND s.due_date >= CURDATE()",
        'partially_paid' => "$remaining > 0.004 AND $paid > 0",
        'upcoming'       => "$remaining > 0.004 AND s.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL " . STATEMENT_UPCOMING_DAYS . ' DAY',
        'overdue'        => "$remaining > 0.004 AND s.due_date < CURDATE()",
        'paid'           => "$remaining <= 0.004",
        default          => '1 = 1',
    };
    $from = 'credit_card_statements s JOIN credit_cards c ON c.id = s.credit_card_id ' . statement_paid_join();
    $result = run_list($state,
        "s.id, s.credit_card_id, s.cutoff_date, s.statement_date, s.period_start_date, s.due_date, s.total_amount_due, s.minimum_amount_due, s.statement_number,
         c.card_nickname, c.issuer, c.last_four, $paid AS paid, $remaining AS remaining, " . statement_status_sql() . ' AS pay_status',
        $from, $where, $params);
    $totals = db_row("SELECT COUNT(*) AS n, COUNT(DISTINCT s.credit_card_id) AS cards, COALESCE(SUM(s.total_amount_due), 0) AS soa,
                             COALESCE(SUM($paid), 0) AS paid, COALESCE(SUM($remaining), 0) AS remaining
                        FROM $from WHERE " . implode(' AND ', $where), $params);
} else {
    // Registered cards with their SOA payment position (one grouped query).
    $cards = db_all(
        "SELECT c.id, c.card_nickname, c.issuer, c.last_four, c.status, c.billing_cycle_day, c.payment_due_day, c.due_date_rule, c.due_days_after_cutoff,
                COUNT(s.id) AS soas,
                COALESCE(SUM($remaining > 0.004), 0) AS unpaid,
                COALESCE(SUM(IF($remaining > 0.004 AND s.due_date < CURDATE(), 1, 0)), 0) AS overdue,
                COALESCE(SUM($remaining), 0) AS outstanding
           FROM credit_cards c
           LEFT JOIN credit_card_statements s ON s.credit_card_id = c.id
           " . statement_paid_join() . '
          GROUP BY c.id
          ORDER BY c.status = \'active\' DESC, c.card_nickname'
    );
    $selected = null;
    foreach ($cards as $c) {
        if ($c['id'] === $state['filters']['card']) {
            $selected = $c;
        }
    }
    $selected ??= $cards[0] ?? null;

    $periods = [];
    $periodPages = 1;
    $periodPage = 1;
    $buckets = [];
    $soaRows = [];
    if ($selected) {
        $card = db_row('SELECT * FROM credit_cards WHERE id = :c', [':c' => $selected['id']]);
        [$periods, $periodPages, $periodPage] = card_periods_page(card_period_context($card), input_int($_GET, 'pp', 1));
        if ($periods) {
            // All records of the shown periods in one pass, then split by period.
            $range = statement_period_lines($card['id'], end($periods)['start'], $periods[0]['end']);
            $buckets = statement_bucket_lines($range, $periods);
            $ids = array_filter(array_column($periods, 'statement_id'));
            if ($ids) {
                $in = implode(',', array_map(static fn ($id) => db()->quote($id), $ids));
                foreach (db_all("SELECT s.id, s.total_amount_due, s.minimum_amount_due, s.due_date, $paid AS paid, $remaining AS remaining, "
                    . statement_status_sql() . " AS pay_status FROM credit_card_statements s " . statement_paid_join() . " WHERE s.id IN ($in)") as $r) {
                    $soaRows[$r['id']] = $r;
                }
            }
        }
    }
}

$pageTitle = 'Statements';
$activeNav = 'statements';
require APP_ROOT . '/includes/header.php';
?>

<?php if ($listMode): ?>
    <?= render_print_header($state, $result['total'], $result['pager']) ?>
<?php endif; ?>
<?= render_list_header($state, 'Each card\'s billing periods with the transactions already recorded. Record the bank\'s SOA amount, minimum due and due date, then pay from the statement.',
    url('pages/statements/create.php', array_filter(['credit_card_id' => $listMode ? null : ($selected['id'] ?? null)])), 'Record SOA') ?>
<?= render_list_toolbar($state) ?>

<?php if ($listMode): ?>
    <section class="panel panel--flush" aria-label="Recorded statements">
        <?php if ($result['total'] === 0): ?>
            <?= render_empty_state($state, null, 'No recorded SOA matches.', 'calendar') ?>
        <?php else: ?>
            <p class="list-summary"><?= e($totals['n']) ?> statement(s) on <?= e($totals['cards']) ?> card(s) · SOA <?= e(format_money($totals['soa'])) ?>
                · paid <?= e(format_money($totals['paid'])) ?> · <strong>remaining <?= e(format_money($totals['remaining'])) ?></strong></p>
            <?= render_statement_rows($result['rows'], $result['pager']['offset']) ?>
            <?= render_list_footer($state, $result) ?>
        <?php endif; ?>
    </section>
<?php elseif (!$cards): ?>
    <section class="panel"><p class="empty-inline">No credit cards yet. Add one in Credit Cards first.</p></section>
<?php else: ?>
    <section class="card-picker no-print" aria-label="Credit cards">
        <?php foreach ($cards as $c): ?>
            <a class="card-pick<?= $c['id'] === $selected['id'] ? ' is-selected' : '' ?>" href="<?= e(url('pages/statements/index.php', ['card' => $c['id']])) ?>"
               <?= $c['id'] === $selected['id'] ? 'aria-current="true"' : '' ?>>
                <span class="card-pick__name"><?= e($c['card_nickname']) ?></span>
                <span class="card-pick__meta"><?= e($c['issuer']) ?> •••• <?= e($c['last_four']) ?><?= $c['status'] !== 'active' ? ' · ' . e(humanize($c['status'])) : '' ?></span>
                <span class="card-pick__meta"><?= (int) $c['unpaid'] > 0
                    ? e($c['unpaid']) . ' to pay · ' . e(format_money($c['outstanding'])) . ((int) $c['overdue'] > 0 ? ' · <strong class="text-danger">' . e($c['overdue']) . ' overdue</strong>' : '')
                    : e($c['soas']) . ' SOA(s) recorded · nothing to pay' ?></span>
            </a>
        <?php endforeach; ?>
    </section>

    <section class="panel panel--flush" aria-label="Billing periods of <?= e($selected['card_nickname']) ?>">
        <header class="panel__header panel__header--padded">
            <div>
                <h3><?= e($selected['card_nickname']) ?> — <?= e($selected['issuer']) ?> •••• <?= e($selected['last_four']) ?></h3>
                <p class="field-help"><?= e(card_billing_summary($card)) ?>. Periods are built from the card's records; nothing is stored until you record an SOA.</p>
            </div>
        </header>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr>
                    <th scope="col">Statement month</th><th scope="col" class="num">Your records</th><th scope="col" class="num">Actual SOA amount</th>
                    <th scope="col" class="num">Minimum due</th><th scope="col">Due date</th><th scope="col" class="num">Paid</th><th scope="col" class="num">Remaining</th>
                    <th scope="col">Status</th><th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr></thead>
                <tbody>
                <?php foreach ($periods as $p): $sum = statement_lines_summary($buckets[$p['end']]); $s = $p['statement_id'] ? ($soaRows[$p['statement_id']] ?? null) : null; $open = $p['end'] >= date('Y-m-d'); ?>
                    <tr>
                        <td data-label="Statement month"><a data-modal-form class="table-link" href="<?= e(statement_view_url($selected['id'], $p)) ?>"><?= e(statement_month_label($p['end'])) ?></a>
                            <br><span class="text-muted"><?= e(format_date($p['start'])) ?> – <?= e(format_date($p['end'])) ?><?= $open ? ' · current cycle' : '' ?></span></td>
                        <td data-label="Your records" class="num"><?= e(format_money($sum['charges'] - $sum['payments'])) ?><br><span class="text-muted"><?= e($sum['count']) ?> record(s)</span></td>
                        <td data-label="Actual SOA amount" class="num"><?= $s ? '<strong>' . e(format_money($s['total_amount_due'])) . '</strong>' : '<span class="text-muted">—</span>' ?></td>
                        <td data-label="Minimum due" class="num"><?= $s ? e(format_money($s['minimum_amount_due'])) : '—' ?></td>
                        <td data-label="Due date"><?= $s ? e(format_date($s['due_date'])) : '—' ?></td>
                        <td data-label="Paid" class="num"><?= $s ? e(format_money($s['paid'])) : '—' ?></td>
                        <td data-label="Remaining" class="num"><?= $s ? e(format_money($s['remaining'])) : '—' ?></td>
                        <td data-label="Status"><?= $s ? statement_status_badge($s['pay_status'], $s['due_date']) : ($open ? '<span class="status status--muted">Current cycle</span>' : statement_status_badge('unrecorded')) ?></td>
                        <td class="actions no-print"><div class="row-actions">
                            <a data-modal-form href="<?= e(statement_view_url($selected['id'], $p)) ?>" class="icon-button" title="View" aria-label="View <?= e(statement_month_label($p['end'])) ?>"><?= icon('eye') ?></a>
                            <?php if ($s): ?>
                                <?= icon_link(url('pages/statements/edit.php', ['id' => $s['id']]), 'edit', 'Edit SOA') ?>
                                <?php if ((float) $s['remaining'] > 0.004): ?>
                                    <a data-modal-form href="<?= e(url('pages/statements/pay.php', ['id' => $s['id']])) ?>" class="btn btn--sm btn--primary"><?= icon('wallet') ?> <span>Pay</span></a>
                                <?php endif; ?>
                            <?php else: ?>
                                <a data-modal-form href="<?= e(statement_soa_url($selected['id'], $p)) ?>" class="btn btn--sm btn--outline"><?= icon('plus') ?> <span>Record SOA</span></a>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($periodPages > 1): ?>
            <nav class="pager no-print" aria-label="Billing periods">
                <?php if ($periodPage > 1): ?><a class="btn btn--sm btn--outline" href="<?= e(url('pages/statements/index.php', ['card' => $selected['id'], 'pp' => $periodPage - 1])) ?>">Newer</a><?php endif; ?>
                <span class="text-muted">Page <?= e($periodPage) ?> of <?= e($periodPages) ?></span>
                <?php if ($periodPage < $periodPages): ?><a class="btn btn--sm btn--outline" href="<?= e(url('pages/statements/index.php', ['card' => $selected['id'], 'pp' => $periodPage + 1])) ?>">Older</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
