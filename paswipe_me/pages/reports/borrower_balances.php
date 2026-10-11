<?php
/**
 * Report - Borrower Balances (view: vw_borrower_balances) with unpaid penalties:
 * outstanding + penalties = total to collect (borrower_totals_sql()).
 */

require_once __DIR__ . '/../../includes/auth_check.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/reports/borrower_balances.php',
    'title'   => 'Borrower Balances',
    'search'  => 'Search borrower number or name…',
    'filters' => [
        'status'  => ['label' => 'Borrower status', 'type' => 'select', 'options' => enum_options(ENUM_BORROWER_STATUS), 'all' => 'All statuses'],
        'balance' => ['label' => 'Balance', 'type' => 'select', 'options' => ['outstanding' => 'With outstanding balance', 'overdue' => 'With overdue amount'], 'all' => 'All borrowers'],
    ],
    'sorts'   => [
        'total'       => ['Highest total to collect', 'total_due DESC'],
        'outstanding' => ['Highest outstanding', 'outstanding DESC'],
        'penalties'   => ['Highest penalties', 'penalties DESC'],
        'overdue'     => ['Highest overdue', 'v.overdue DESC'],
        'name'        => ['Name (A–Z)', 'v.last_name ASC, v.first_name ASC'],
    ],
]);

$where = ['v.borrower_id IN ' . owned_borrowers_sql()];   // the signed-in user's records only
$params = [];
add_search_condition($state, ['v.borrower_number', 'v.first_name', 'v.last_name'], $where, $params);
if ($state['filters']['status'] !== '') {
    $where[] = 'v.status = :status';
    $params[':status'] = $state['filters']['status'];
}
if ($state['filters']['balance'] === 'outstanding') {
    $where[] = 'COALESCE(bt.total_due, 0) > 0';
} elseif ($state['filters']['balance'] === 'overdue') {
    $where[] = 'v.overdue > 0';
}

$from = 'vw_borrower_balances v LEFT JOIN (' . borrower_totals_sql() . ') bt ON bt.borrower_id = v.borrower_id';
$result = run_list($state, 'v.borrower_id, v.borrower_number, v.first_name, v.last_name, v.status, v.total_charged, v.total_paid, v.overdue,
    COALESCE(bt.outstanding, 0) AS outstanding, COALESCE(bt.penalties, 0) AS penalties, COALESCE(bt.total_due, 0) AS total_due', $from, $where, $params);
$totals = db_row('SELECT COALESCE(SUM(v.total_charged), 0) AS charged, COALESCE(SUM(v.total_paid), 0) AS paid, COALESCE(SUM(bt.outstanding), 0) AS outstanding,
    COALESCE(SUM(v.overdue), 0) AS overdue, COALESCE(SUM(bt.penalties), 0) AS penalties, COALESCE(SUM(bt.total_due), 0) AS total_due FROM '
    . $from . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $params);

$pageTitle = 'Borrower Balances';
$activeNav = 'reports';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Reports' => url('pages/reports/index.php'), 'Borrower Balances' => null]) ?>
<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Per borrower: outstanding balance + unpaid penalties = total to collect (cancelled and written-off items excluded).', null) ?>
<?= render_list_toolbar($state) ?>

<section class="figure-grid">
    <div class="figure"><p class="figure__label">Total charged</p><p class="figure__value"><?= e(format_money($totals['charged'])) ?></p></div>
    <div class="figure"><p class="figure__label">Total paid</p><p class="figure__value"><?= e(format_money($totals['paid'])) ?></p></div>
    <div class="figure"><p class="figure__label">Outstanding</p><p class="figure__value"><?= e(format_money($totals['outstanding'])) ?></p></div>
    <div class="figure"><p class="figure__label">Overdue</p><p class="figure__value"><?= e(format_money($totals['overdue'])) ?></p></div>
    <div class="figure"><p class="figure__label">Unpaid penalties</p><p class="figure__value"><?= e(format_money($totals['penalties'])) ?></p></div>
    <div class="figure figure--total"><p class="figure__label">Total to collect</p><p class="figure__value"><?= e(format_money($totals['total_due'])) ?></p></div>
</section>

<section class="panel panel--flush">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, null, 'No borrowers yet.', 'users') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr><th scope="col" class="col-num">#</th><th scope="col">Borrower #</th><th scope="col">Name</th><th scope="col">Status</th>
                    <th scope="col" class="num">Charged</th><th scope="col" class="num">Paid</th><th scope="col" class="num">Outstanding</th><th scope="col" class="num">Overdue</th>
                    <th scope="col" class="num">Penalties</th><th scope="col" class="num">Total to collect</th></tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Borrower #"><?= e($row['borrower_number']) ?></td>
                        <td data-label="Name"><a data-modal-form class="table-link" href="<?= e(url('pages/borrowers/view.php', ['id' => $row['borrower_id']])) ?>"><?= e(borrower_name($row)) ?></a></td>
                        <td data-label="Status"><?= badge($row['status']) ?></td>
                        <td data-label="Charged" class="num"><?= e(format_money($row['total_charged'])) ?></td>
                        <td data-label="Paid" class="num"><?= e(format_money($row['total_paid'])) ?></td>
                        <td data-label="Outstanding" class="num"><?= e(format_money($row['outstanding'])) ?></td>
                        <td data-label="Overdue" class="num<?= (float) $row['overdue'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($row['overdue'])) ?></td>
                        <td data-label="Penalties" class="num<?= (float) $row['penalties'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($row['penalties'])) ?></td>
                        <td data-label="Total to collect" class="num"><strong><?= e(format_money($row['total_due'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="4">Total (all matching)</td><td class="num"><?= e(format_money($totals['charged'])) ?></td><td class="num"><?= e(format_money($totals['paid'])) ?></td>
                    <td class="num"><?= e(format_money($totals['outstanding'])) ?></td><td class="num"><?= e(format_money($totals['overdue'])) ?></td>
                    <td class="num"><?= e(format_money($totals['penalties'])) ?></td><td class="num"><strong><?= e(format_money($totals['total_due'])) ?></strong></td></tr></tfoot>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
