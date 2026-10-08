<?php
/**
 * Card Payments - list, search, filter by card and date, print with total.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/card_payment_functions.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/card_payments/index.php',
    'title'   => 'Card Payments',
    'search'  => 'Search reference, channel, type or card…',
    'filters' => [
        'card' => ['label' => 'Card', 'type' => 'select', 'options' => credit_card_options(null, false), 'all' => 'All cards'],
        'from' => ['label' => 'From', 'type' => 'date'],
        'to'   => ['label' => 'To', 'type' => 'date'],
    ],
    'sorts'   => [
        'newest' => ['Newest first', 'p.payment_date DESC, p.created_at DESC'],
        'oldest' => ['Oldest first', 'p.payment_date ASC'],
        'amount' => ['Highest amount', 'p.amount DESC'],
    ],
]);

$where = [];
$params = [];
add_search_condition($state, ['p.reference_number', 'p.payment_channel', 'p.payment_type', 'c.card_nickname', 'c.last_four'], $where, $params);
if ($state['filters']['card'] !== '') {
    $where[] = 'p.credit_card_id = :card';
    $params[':card'] = $state['filters']['card'];
}
if ($state['filters']['from'] !== '') {
    $where[] = 'p.payment_date >= :from';
    $params[':from'] = $state['filters']['from'];
}
if ($state['filters']['to'] !== '') {
    $where[] = 'p.payment_date <= :to';
    $params[':to'] = $state['filters']['to'];
}

$from = 'credit_card_payments p JOIN credit_cards c ON c.id = p.credit_card_id LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id';
$result = run_list(
    $state,
    'p.id, p.payment_date, p.amount, p.payment_type, p.payment_channel, p.reference_number, c.card_nickname, c.last_four, pm.display_name AS method_name',
    $from,
    $where,
    $params
);
$sum = db_value('SELECT COALESCE(SUM(p.amount), 0) FROM ' . $from . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $params);

$pageTitle = 'Card Payments';
$activeNav = 'card_payments';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Payments made by the owner to the card issuers.', url('pages/card_payments/create.php'), 'Record Payment') ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Card payment list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/card_payments/create.php'), 'Record payments made to the banks.', 'banknote') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Date</th>
                    <th scope="col">Card</th>
                    <th scope="col">Type / Channel</th>
                    <th scope="col">Paid from</th>
                    <th scope="col">Reference</th>
                    <th scope="col" class="num">Amount</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Date"><a class="table-link" href="<?= e(url('pages/card_payments/view.php', ['id' => $row['id']])) ?>"><?= e(format_date($row['payment_date'])) ?></a></td>
                        <td data-label="Card"><?= e($row['card_nickname']) ?> <span class="text-muted">•••• <?= e($row['last_four']) ?></span></td>
                        <td data-label="Type / Channel"><?= show(trim(($row['payment_type'] ?? '') . ($row['payment_channel'] ? ' · ' . $row['payment_channel'] : ''), ' ·')) ?></td>
                        <td data-label="Paid from"><?= show($row['method_name']) ?></td>
                        <td data-label="Reference"><?= show($row['reference_number']) ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($row['amount'])) ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/card_payments/view.php', ['id' => $row['id']]), 'eye', 'View payment') ?>
                                <?= icon_link(url('pages/card_payments/edit.php', ['id' => $row['id']]), 'edit', 'Edit payment') ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="6">Total (all <?= e(format_number($result['total'])) ?> matching)</td><td class="num"><?= e(format_money($sum)) ?></td><td class="no-print"></td></tr></tfoot>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
