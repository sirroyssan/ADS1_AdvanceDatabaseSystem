<?php
/**
 * Report - Transactions by period, card, borrower and payment type, with totals
 * per payment type and per card. Cancelled and refunded transactions are excluded
 * from the totals.
 */

require_once __DIR__ . '/../../includes/auth_check.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/reports/transactions.php',
    'title'   => 'Transactions',
    'search'  => 'Search number, merchant or borrower…',
    'filters' => [
        'card'     => ['label' => 'Card', 'type' => 'select', 'options' => credit_card_options(null, false), 'all' => 'All cards'],
        'borrower' => ['label' => 'Borrower', 'type' => 'select', 'options' => ['owner' => 'Owner\'s own purchases'] + borrower_options(null, false), 'all' => 'Owner and borrowers'],
        'payment'  => ['label' => 'Payment type', 'type' => 'select', 'options' => enum_options(ENUM_TXN_PAYMENT_TYPE), 'all' => 'All payment types'],
        'status'   => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_TXN_STATUS), 'all' => 'All statuses'],
        'from'     => ['label' => 'From', 'type' => 'date'],
        'to'       => ['label' => 'To', 'type' => 'date'],
    ],
    'sorts'   => [
        'oldest' => ['Date (oldest first)', 't.transaction_date ASC'],
        'newest' => ['Date (newest first)', 't.transaction_date DESC'],
        'amount' => ['Highest amount', 't.amount DESC'],
    ],
]);

$where = [];
$params = [];
add_search_condition($state, ['t.transaction_number', 'm.merchant_name', 'b.first_name', 'b.last_name'], $where, $params);
foreach (['card' => 't.credit_card_id', 'payment' => 't.payment_type', 'status' => 't.status'] as $key => $column) {
    if ($state['filters'][$key] !== '') {
        $where[] = $column . ' = :' . $key;
        $params[':' . $key] = $state['filters'][$key];
    }
}
if ($state['filters']['borrower'] === 'owner') {
    $where[] = 't.borrower_id IS NULL';
} elseif ($state['filters']['borrower'] !== '') {
    $where[] = 't.borrower_id = :borrower';
    $params[':borrower'] = $state['filters']['borrower'];
}
if ($state['filters']['from'] !== '') {
    $where[] = 't.transaction_date >= :from';
    $params[':from'] = $state['filters']['from'];
}
if ($state['filters']['to'] !== '') {
    $where[] = 't.transaction_date <= :to';
    $params[':to'] = $state['filters']['to'];
}

$from = 'transactions t JOIN credit_cards c ON c.id = t.credit_card_id LEFT JOIN merchants m ON m.id = t.merchant_id LEFT JOIN borrowers b ON b.id = t.borrower_id';
$result = run_list($state, 't.id, t.transaction_number, t.transaction_date, t.amount, t.payment_type, t.status, c.card_nickname, c.last_four, m.merchant_name, b.first_name, b.last_name', $from, $where, $params);

$countable = array_merge($where, ["t.status NOT IN ('cancelled', 'refunded')"]);
$countSql = ' WHERE ' . implode(' AND ', $countable);
$byType = db_all('SELECT t.payment_type, COUNT(*) AS n, SUM(t.amount) AS total FROM ' . $from . $countSql . ' GROUP BY t.payment_type ORDER BY total DESC', $params);
$byCard = db_all("SELECT CONCAT(c.card_nickname, ' •••• ', c.last_four) AS card, COUNT(*) AS n, SUM(t.amount) AS total FROM " . $from . $countSql . ' GROUP BY c.id, card ORDER BY total DESC', $params);
$grand = array_sum(array_column($byType, 'total'));

$pageTitle = 'Transactions Report';
$activeNav = 'reports';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Reports' => url('pages/reports/index.php'), 'Transactions' => null]) ?>
<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Card swipes for a period with totals by payment type and by card.', null) ?>
<?= render_list_toolbar($state) ?>

<div class="panel-grid">
    <section class="panel">
        <header class="panel__header"><h3>By payment type</h3></header>
        <?php if (!$byType): ?><p class="empty-inline">No transactions.</p><?php else: ?>
            <table class="table table--compact">
                <thead><tr><th scope="col">Payment type</th><th scope="col" class="num">Count</th><th scope="col" class="num">Total</th></tr></thead>
                <tbody><?php foreach ($byType as $r): ?><tr><td><?= e(humanize($r['payment_type'])) ?></td><td class="num"><?= e($r['n']) ?></td><td class="num"><?= e(format_money($r['total'])) ?></td></tr><?php endforeach; ?></tbody>
                <tfoot><tr><td colspan="2">Total (excluding cancelled/refunded)</td><td class="num"><?= e(format_money($grand)) ?></td></tr></tfoot>
            </table>
        <?php endif; ?>
    </section>
    <section class="panel">
        <header class="panel__header"><h3>By card</h3></header>
        <?php if (!$byCard): ?><p class="empty-inline">No transactions.</p><?php else: ?>
            <table class="table table--compact">
                <thead><tr><th scope="col">Card</th><th scope="col" class="num">Count</th><th scope="col" class="num">Total</th></tr></thead>
                <tbody><?php foreach ($byCard as $r): ?><tr><td><?= e($r['card']) ?></td><td class="num"><?= e($r['n']) ?></td><td class="num"><?= e(format_money($r['total'])) ?></td></tr><?php endforeach; ?></tbody>
            </table>
        <?php endif; ?>
    </section>
</div>

<section class="panel panel--flush">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, null, 'No transactions recorded yet.', 'layers') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr><th scope="col" class="col-num">#</th><th scope="col">Date</th><th scope="col">Transaction #</th><th scope="col">Card</th><th scope="col">Merchant</th>
                    <th scope="col">Borrower</th><th scope="col">Payment type</th><th scope="col">Status</th><th scope="col" class="num">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Date"><?= e(format_date($row['transaction_date'])) ?></td>
                        <td data-label="Transaction #"><a data-modal-form class="table-link" href="<?= e(url('pages/transactions/view.php', ['id' => $row['id']])) ?>"><?= e($row['transaction_number']) ?></a></td>
                        <td data-label="Card"><?= e($row['card_nickname']) ?> •••• <?= e($row['last_four']) ?></td>
                        <td data-label="Merchant"><?= show($row['merchant_name']) ?></td>
                        <td data-label="Borrower"><?= $row['first_name'] ? e(borrower_name($row)) : '<span class="text-muted">Owner</span>' ?></td>
                        <td data-label="Payment type"><?= e(humanize($row['payment_type'])) ?></td>
                        <td data-label="Status"><?= badge($row['status']) ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($row['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
