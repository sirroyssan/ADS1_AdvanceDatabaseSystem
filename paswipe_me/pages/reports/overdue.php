<?php
/**
 * Report - Overdue Obligations (view: vw_overdue_obligations), aged by days overdue.
 */

require_once __DIR__ . '/../../includes/auth_check.php';

$currentUser = require_admin();

$ages = ['1-30' => '1–30 days', '31-60' => '31–60 days', '61-90' => '61–90 days', '91+' => 'More than 90 days'];

$state = list_state([
    'path'    => 'pages/reports/overdue.php',
    'title'   => 'Overdue Obligations',
    'search'  => 'Search obligation number or borrower…',
    'filters' => [
        'borrower' => ['label' => 'Borrower', 'type' => 'select', 'options' => borrower_options(null, false), 'all' => 'All borrowers'],
        'age'      => ['label' => 'Days overdue', 'type' => 'select', 'options' => $ages, 'all' => 'Any age'],
    ],
    'sorts'   => [
        'days'        => ['Most overdue', 'v.overdue_days DESC'],
        'outstanding' => ['Highest outstanding', 'v.outstanding_amount DESC'],
    ],
]);

$where = [];
$params = [];
add_search_condition($state, ['v.obligation_number', 'v.borrower_number', 'v.first_name', 'v.last_name'], $where, $params);
if ($state['filters']['borrower'] !== '') {
    $where[] = 'v.borrower_id = :borrower';
    $params[':borrower'] = $state['filters']['borrower'];
}
$ageSql = ['1-30' => 'v.overdue_days BETWEEN 1 AND 30', '31-60' => 'v.overdue_days BETWEEN 31 AND 60', '61-90' => 'v.overdue_days BETWEEN 61 AND 90', '91+' => 'v.overdue_days > 90'];
if ($state['filters']['age'] !== '') {
    $where[] = $ageSql[$state['filters']['age']];
}

$from = 'vw_overdue_obligations v';
$result = run_list($state, 'v.id, v.obligation_number, v.borrower_id, v.borrower_number, v.first_name, v.last_name, v.total_amount_due, v.total_paid, v.outstanding_amount, v.overdue_amount, v.due_date, v.overdue_days', $from, $where, $params);
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$totals = db_row('SELECT COALESCE(SUM(v.outstanding_amount), 0) AS outstanding, COALESCE(SUM(v.overdue_amount), 0) AS overdue FROM ' . $from . $whereSql, $params);
$aging = db_options(
    "SELECT CASE WHEN v.overdue_days <= 30 THEN '1-30' WHEN v.overdue_days <= 60 THEN '31-60' WHEN v.overdue_days <= 90 THEN '61-90' ELSE '91+' END AS bucket,
            SUM(v.outstanding_amount) FROM " . $from . $whereSql . ' GROUP BY bucket',
    $params
);

$pageTitle = 'Overdue Obligations';
$activeNav = 'reports';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Reports' => url('pages/reports/index.php'), 'Overdue Obligations' => null]) ?>
<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Unpaid obligations past their due date.', null) ?>
<?= render_list_toolbar($state) ?>

<section class="figure-grid">
    <?php foreach ($ages as $key => $label): ?>
        <div class="figure"><p class="figure__label">Outstanding · <?= e($label) ?></p><p class="figure__value"><?= e(format_money($aging[$key] ?? 0)) ?></p></div>
    <?php endforeach; ?>
</section>

<section class="panel panel--flush">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, null, 'No obligations are overdue.', 'check') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr><th scope="col" class="col-num">#</th><th scope="col">Obligation #</th><th scope="col">Borrower</th><th scope="col">Due date</th><th scope="col" class="num">Days</th>
                    <th scope="col" class="num">Total due</th><th scope="col" class="num">Paid</th><th scope="col" class="num">Outstanding</th></tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Obligation #"><a class="table-link" href="<?= e(url('pages/obligations/view.php', ['id' => $row['id']])) ?>"><?= e($row['obligation_number']) ?></a></td>
                        <td data-label="Borrower"><?= e(borrower_name($row)) ?> <span class="text-muted"><?= e($row['borrower_number']) ?></span></td>
                        <td data-label="Due date"><?= e(format_date($row['due_date'])) ?></td>
                        <td data-label="Days" class="num text-danger"><?= e($row['overdue_days']) ?></td>
                        <td data-label="Total due" class="num"><?= e(format_money($row['total_amount_due'])) ?></td>
                        <td data-label="Paid" class="num"><?= e(format_money($row['total_paid'])) ?></td>
                        <td data-label="Outstanding" class="num"><?= e(format_money($row['outstanding_amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="7">Total outstanding (all matching)</td><td class="num"><?= e(format_money($totals['outstanding'])) ?></td></tr></tfoot>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
