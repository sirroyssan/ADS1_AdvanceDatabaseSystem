<?php
/**
 * Report - Collections: APPROVED borrower payments in a date range,
 * with a summary per payment method.
 */

require_once __DIR__ . '/../../includes/auth_check.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/reports/collections.php',
    'title'   => 'Collections',
    'search'  => 'Search payment number, reference or borrower…',
    'filters' => [
        'borrower' => ['label' => 'Borrower', 'type' => 'select', 'options' => borrower_options(null, false), 'all' => 'All borrowers'],
        'method'   => ['label' => 'Method type', 'type' => 'select', 'options' => enum_options(ENUM_PAYMENT_METHOD), 'all' => 'All methods'],
        'from'     => ['label' => 'From', 'type' => 'date'],
        'to'       => ['label' => 'To', 'type' => 'date'],
    ],
    'sorts'   => [
        'newest' => ['Newest first', 'p.payment_date DESC'],
        'oldest' => ['Oldest first', 'p.payment_date ASC'],
        'amount' => ['Highest amount', 'p.amount DESC'],
    ],
]);

$where = ["p.status = 'approved'"];
$params = [];
add_search_condition($state, ['p.payment_number', 'p.reference_number', 'b.first_name', 'b.last_name'], $where, $params);
if ($state['filters']['borrower'] !== '') {
    $where[] = 'p.borrower_id = :borrower';
    $params[':borrower'] = $state['filters']['borrower'];
}
if ($state['filters']['method'] !== '') {
    $where[] = 'pm.method_type = :method';
    $params[':method'] = $state['filters']['method'];
}
if ($state['filters']['from'] !== '') {
    $where[] = 'p.payment_date >= :from';
    $params[':from'] = $state['filters']['from'];
}
if ($state['filters']['to'] !== '') {
    $where[] = 'p.payment_date <= :to';
    $params[':to'] = $state['filters']['to'];
}

$state['config']['base_where'] = "p.status = 'approved'";
$from = 'borrower_payments p JOIN borrowers b ON b.id = p.borrower_id LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id';
$result = run_list($state, 'p.id, p.payment_number, p.payment_date, p.amount, p.reference_number, p.approved_at, b.first_name, b.last_name, pm.display_name, pm.method_type', $from, $where, $params);
$whereSql = ' WHERE ' . implode(' AND ', $where);
$byMethod = db_all("SELECT COALESCE(pm.method_type, 'unspecified') AS method, COUNT(*) AS payments, SUM(p.amount) AS total FROM " . $from . $whereSql . ' GROUP BY method ORDER BY total DESC', $params);
$sum = array_sum(array_column($byMethod, 'total'));

$pageTitle = 'Collections';
$activeNav = 'reports';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Reports' => url('pages/reports/index.php'), 'Collections' => null]) ?>
<?= render_print_header($state, $result['total'], $result['pager'], 'Collections Report') ?>
<?= render_list_header($state, 'Approved borrower payments only. Use From/To for a period (e.g. one month).', null) ?>
<?= render_list_toolbar($state) ?>

<section class="figure-grid" aria-label="Collections by method">
    <div class="figure"><p class="figure__label">Total collected</p><p class="figure__value"><?= e(format_money($sum)) ?></p></div>
    <?php foreach ($byMethod as $m): ?>
        <div class="figure"><p class="figure__label"><?= e(strtoupper(humanize($m['method']))) ?> · <?= e($m['payments']) ?> payment(s)</p><p class="figure__value"><?= e(format_money($m['total'])) ?></p></div>
    <?php endforeach; ?>
</section>

<section class="panel panel--flush">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, null, 'No approved payments yet.', 'banknote') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr><th scope="col" class="col-num">#</th><th scope="col">Payment #</th><th scope="col">Date</th><th scope="col">Borrower</th><th scope="col">Method</th>
                    <th scope="col">Reference</th><th scope="col">Approved</th><th scope="col" class="num">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Payment #"><a data-modal-form class="table-link" href="<?= e(url('pages/borrower_payments/view.php', ['id' => $row['id']])) ?>"><?= e($row['payment_number']) ?></a></td>
                        <td data-label="Date"><?= e(format_date($row['payment_date'])) ?></td>
                        <td data-label="Borrower"><?= e(borrower_name($row)) ?></td>
                        <td data-label="Method"><?= show($row['display_name']) ?></td>
                        <td data-label="Reference"><?= show($row['reference_number']) ?></td>
                        <td data-label="Approved"><?= e(format_datetime($row['approved_at'])) ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($row['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="7">Total collected (all matching)</td><td class="num"><?= e(format_money($sum)) ?></td></tr></tfoot>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
