<?php
/**
 * Transactions - list, search, filter (card, borrower, status, payment type, dates), print with total.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/transaction_functions.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/transactions/index.php',
    'title'   => 'Transactions',
    'search'  => 'Search number, reference, receipt, merchant or borrower…',
    'filters' => [
        'card'     => ['label' => 'Card', 'type' => 'select', 'options' => credit_card_options(null, false), 'all' => 'All cards'],
        'borrower' => ['label' => 'Borrower', 'type' => 'select', 'options' => borrower_options(null, false), 'all' => 'All borrowers'],
        'status'   => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_TXN_STATUS), 'all' => 'All statuses'],
        'payment'  => ['label' => 'Payment type', 'type' => 'select', 'options' => enum_options(ENUM_TXN_PAYMENT_TYPE), 'all' => 'All payment types'],
        'from'     => ['label' => 'From', 'type' => 'date'],
        'to'       => ['label' => 'To', 'type' => 'date'],
    ],
    'sorts'   => [
        'newest' => ['Newest first', 't.transaction_date DESC, t.created_at DESC'],
        'oldest' => ['Oldest first', 't.transaction_date ASC'],
        'amount' => ['Highest amount', 't.amount DESC'],
    ],
]);

$where = [];
$params = [];
add_search_condition($state, ['t.transaction_number', 't.reference_number', 't.receipt_number', 'm.merchant_name', 'b.first_name', 'b.last_name'], $where, $params);
foreach (['card' => 't.credit_card_id', 'borrower' => 't.borrower_id', 'status' => 't.status', 'payment' => 't.payment_type'] as $key => $column) {
    if ($state['filters'][$key] !== '') {
        $where[] = $column . ' = :' . $key;
        $params[':' . $key] = $state['filters'][$key];
    }
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
$result = run_list(
    $state,
    't.id, t.transaction_number, t.transaction_date, t.amount, t.currency, t.payment_type, t.status, c.card_nickname, c.last_four,
     m.merchant_name, b.first_name, b.last_name',
    $from,
    $where,
    $params
);
$sum = db_value("SELECT COALESCE(SUM(IF(t.status NOT IN ('cancelled', 'refunded'), t.amount, 0)), 0) FROM " . $from . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $params);

$pageTitle = 'Transactions';
$activeNav = 'transactions';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Card swipes for the owner and for borrowers.', url('pages/transactions/create.php')) ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Transaction list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/transactions/create.php'), 'Record each card swipe here.', 'layers') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Transaction #</th>
                    <th scope="col">Date</th>
                    <th scope="col">Card</th>
                    <th scope="col">Merchant</th>
                    <th scope="col">Borrower</th>
                    <th scope="col">Payment type</th>
                    <th scope="col" class="num">Amount</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Transaction #"><a class="table-link" href="<?= e(url('pages/transactions/view.php', ['id' => $row['id']])) ?>"><?= e($row['transaction_number']) ?></a></td>
                        <td data-label="Date"><?= e(format_date($row['transaction_date'])) ?></td>
                        <td data-label="Card"><?= e($row['card_nickname']) ?> <span class="text-muted">•••• <?= e($row['last_four']) ?></span></td>
                        <td data-label="Merchant"><?= show($row['merchant_name']) ?></td>
                        <td data-label="Borrower"><?= $row['first_name'] ? e(borrower_name($row)) : '<span class="text-muted">Owner</span>' ?></td>
                        <td data-label="Payment type"><?= e(humanize($row['payment_type'])) ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($row['amount'])) ?><?= $row['currency'] !== 'PHP' ? ' <span class="text-muted">' . e($row['currency']) . '</span>' : '' ?></td>
                        <td data-label="Status"><?= badge($row['status']) ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/transactions/view.php', ['id' => $row['id']]), 'eye', 'View ' . $row['transaction_number']) ?>
                                <?php if (!in_array($row['status'], TXN_LOCKED_STATUSES, true)): ?>
                                    <?= icon_link(url('pages/transactions/edit.php', ['id' => $row['id']]), 'edit', 'Edit ' . $row['transaction_number']) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="7">Total excluding cancelled/refunded</td><td class="num"><?= e(format_money($sum)) ?></td><td colspan="2"></td></tr></tfoot>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
