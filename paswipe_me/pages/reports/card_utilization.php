<?php
/**
 * Report - Credit Card Utilization (view: vw_credit_card_utilization, active cards).
 */

require_once __DIR__ . '/../../includes/auth_check.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/reports/card_utilization.php',
    'title'   => 'Credit Card Utilization',
    'search'  => 'Search nickname, issuer or last four…',
    'filters' => [
        'level' => ['label' => 'Utilization', 'type' => 'select', 'options' => ['high' => '70% and above', 'medium' => '30% to 69%', 'low' => 'Below 30%'], 'all' => 'Any utilization'],
    ],
    'sorts'   => [
        'utilization' => ['Highest utilization', 'v.utilization_percentage DESC'],
        'limit'       => ['Highest limit', 'v.credit_limit DESC'],
        'name'        => ['Nickname', 'v.card_nickname ASC'],
    ],
]);

$where = [];
$params = [];
add_search_condition($state, ['v.card_nickname', 'v.issuer', 'v.last_four'], $where, $params);
$levels = ['high' => 'v.utilization_percentage >= 70', 'medium' => 'v.utilization_percentage >= 30 AND v.utilization_percentage < 70', 'low' => 'v.utilization_percentage < 30'];
if ($state['filters']['level'] !== '') {
    $where[] = $levels[$state['filters']['level']];
}

$from = 'vw_credit_card_utilization v';
$result = run_list($state, 'v.id, v.card_nickname, v.issuer, v.last_four, v.credit_limit, v.outstanding, v.available_credit, v.utilization_percentage', $from, $where, $params);
$totals = db_row('SELECT COALESCE(SUM(v.credit_limit), 0) AS limit_total, COALESCE(SUM(v.outstanding), 0) AS used, COALESCE(SUM(v.available_credit), 0) AS available FROM '
    . $from . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $params);
$overall = (float) $totals['limit_total'] > 0 ? (float) $totals['used'] / (float) $totals['limit_total'] * 100 : 0;

$pageTitle = 'Card Utilization';
$activeNav = 'reports';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Reports' => url('pages/reports/index.php'), 'Credit Card Utilization' => null]) ?>
<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Active cards only. Used = credit limit − available credit.', null) ?>
<?= render_list_toolbar($state) ?>

<section class="figure-grid">
    <div class="figure"><p class="figure__label">Total limit</p><p class="figure__value"><?= e(format_money($totals['limit_total'])) ?></p></div>
    <div class="figure"><p class="figure__label">Used</p><p class="figure__value"><?= e(format_money($totals['used'])) ?></p></div>
    <div class="figure"><p class="figure__label">Available</p><p class="figure__value"><?= e(format_money($totals['available'])) ?></p></div>
    <div class="figure"><p class="figure__label">Overall utilization</p><p class="figure__value"><?= e(number_format($overall, 1)) ?>%</p></div>
</section>

<section class="panel panel--flush">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, null, 'No active cards.', 'credit-card') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr><th scope="col" class="col-num">#</th><th scope="col">Card</th><th scope="col">Issuer</th><th scope="col" class="num">Limit</th><th scope="col" class="num">Used</th>
                    <th scope="col" class="num">Available</th><th scope="col">Utilization</th></tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <?php $pct = max(0, min(100, (float) $row['utilization_percentage'])); ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Card"><a class="table-link" href="<?= e(url('pages/credit_cards/view.php', ['id' => $row['id']])) ?>"><?= e($row['card_nickname']) ?></a> •••• <?= e($row['last_four']) ?></td>
                        <td data-label="Issuer"><?= e($row['issuer']) ?></td>
                        <td data-label="Limit" class="num"><?= e(format_money($row['credit_limit'])) ?></td>
                        <td data-label="Used" class="num"><?= e(format_money($row['outstanding'])) ?></td>
                        <td data-label="Available" class="num"><?= e(format_money($row['available_credit'])) ?></td>
                        <td data-label="Utilization"><?= e(number_format($pct, 1)) ?>% <progress class="meter" max="100" value="<?= e($pct) ?>"><?= e($pct) ?>%</progress></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
