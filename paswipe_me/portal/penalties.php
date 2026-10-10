<?php
/**
 * Borrower portal - all my penalties (late charges): unpaid, paid and waived,
 * with search, filters and print. Outstanding + unpaid penalties = total to pay.
 */

require_once __DIR__ . '/../includes/auth_check.php';

[$currentUser, $borrower] = require_borrower();
$bid = $borrower['id'];

$state = list_state([
    'path'    => 'portal/penalties.php',
    'title'   => 'My Penalties (Late Charges)',
    'search'  => 'Search item number or charge…',
    'filters' => [
        'state' => ['label' => 'Show', 'type' => 'select', 'options' => ['unpaid' => 'Still to pay', 'paid' => 'Paid', 'waived' => 'Waived'], 'all' => 'All penalties'],
        'from'  => ['label' => 'Charged from', 'type' => 'date'],
        'to'    => ['label' => 'Charged to', 'type' => 'date'],
    ],
    'sorts'   => [
        'oldest' => ['Oldest first', 'ap.applied_date ASC, o.obligation_number'],
        'newest' => ['Newest first', 'ap.applied_date DESC, o.obligation_number'],
        'amount' => ['Highest unpaid', 'ap.remaining_amount DESC'],
    ],
    'base_where' => "o.status NOT IN ('cancelled', 'written_off') AND o.borrower_id = " . db()->quote($bid),
]);

$where = ["o.status NOT IN ('cancelled', 'written_off')", 'o.borrower_id = :b'];
$params = [':b' => $bid];
add_search_condition($state, ['o.obligation_number', 'r.name'], $where, $params);
$stateSql = [
    'unpaid' => 'ap.remaining_amount > 0',
    'paid'   => "ap.remaining_amount = 0 AND (ap.notes IS NULL OR ap.notes NOT LIKE '%WAIVED%')",
    'waived' => "ap.notes LIKE '%WAIVED%'",
];
if ($state['filters']['state'] !== '') {
    $where[] = $stateSql[$state['filters']['state']];
}
if ($state['filters']['from'] !== '') {
    $where[] = 'ap.applied_date >= :from';
    $params[':from'] = $state['filters']['from'];
}
if ($state['filters']['to'] !== '') {
    $where[] = 'ap.applied_date <= :to';
    $params[':to'] = $state['filters']['to'];
}

$from = 'applied_penalties ap JOIN borrower_obligations o ON o.id = ap.obligation_id JOIN penalty_rules r ON r.id = ap.penalty_rule_id';
$result = run_list($state, 'ap.*, r.name AS rule_name, o.id AS obligation_id, o.obligation_number, o.description', $from, $where, $params);
$sums = db_row('SELECT COALESCE(SUM(ap.amount), 0) AS charged, COALESCE(SUM(ap.paid_amount), 0) AS paid, COALESCE(SUM(ap.remaining_amount), 0) AS unpaid
                  FROM ' . $from . ' WHERE ' . implode(' AND ', $where), $params);
$collect = collection_totals($bid);

$pageTitle = 'My Penalties';
$activeNav = 'portal_penalties';
$layout = 'portal';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager'], 'My Penalties — ' . borrower_name($borrower)) ?>
<?= render_list_header($state, 'Late charges added to your items. Your payments pay these first.', url('portal/pay.php'), 'Submit payment') ?>

<section class="balance-equation" aria-label="Total to pay">
    <div class="balance-equation__part">
        <p class="balance-equation__label">Outstanding</p>
        <p class="balance-equation__value"><?= e(format_money($collect['outstanding'])) ?></p>
    </div>
    <span class="balance-equation__op" aria-hidden="true">+</span>
    <div class="balance-equation__part">
        <p class="balance-equation__label">Unpaid penalties</p>
        <p class="balance-equation__value<?= (float) $collect['penalties'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($collect['penalties'])) ?></p>
    </div>
    <span class="balance-equation__op" aria-hidden="true">=</span>
    <div class="balance-equation__part balance-equation__part--total">
        <p class="balance-equation__label">Total to pay</p>
        <p class="balance-equation__value"><?= e(format_money($collect['total_due'])) ?></p>
    </div>
</section>

<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="My penalties">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, null, 'You have no penalties. Thank you for paying on time!', 'check') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr>
                    <th scope="col" class="col-num">#</th><th scope="col">Date charged</th><th scope="col">Item</th><th scope="col">Charge</th>
                    <th scope="col" class="num">Days late</th><th scope="col" class="num">Amount</th><th scope="col" class="num">Paid</th><th scope="col" class="num">To pay</th><th scope="col">Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $p): ?>
                    <?php $waived = str_contains((string) $p['notes'], 'WAIVED'); $unpaid = (float) $p['remaining_amount'] > 0.004; ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Date charged"><?= e(format_date($p['applied_date'])) ?></td>
                        <td data-label="Item"><a data-modal-form class="table-link" href="<?= e(url('portal/obligation.php', ['id' => $p['obligation_id']])) ?>"><?= e($p['obligation_number']) ?></a></td>
                        <td data-label="Charge"><?= e($p['rule_name']) ?></td>
                        <td data-label="Days late" class="num"><?= e($p['overdue_days']) ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($p['amount'])) ?></td>
                        <td data-label="Paid" class="num"><?= e(format_money($p['paid_amount'])) ?></td>
                        <td data-label="To pay" class="num<?= $unpaid ? ' text-danger' : '' ?>"><strong><?= e(format_money($p['remaining_amount'])) ?></strong></td>
                        <td data-label="Status"><?= $unpaid ? status_badge('unpaid', ['unpaid' => 'danger']) : ($waived ? status_badge('waived', ['waived' => 'info']) : status_badge('paid', ['paid' => 'success'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="5">Total (matching filters)</td><td class="num"><?= e(format_money($sums['charged'])) ?></td><td class="num"><?= e(format_money($sums['paid'])) ?></td>
                    <td class="num"><strong><?= e(format_money($sums['unpaid'])) ?></strong></td><td></td></tr></tfoot>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
