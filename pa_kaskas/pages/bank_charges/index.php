<?php
/**
 * Bank Charges - list, search, filter by card/type/date, print with total.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/bank_charge_functions.php';

$currentUser = require_admin();

$typeOptions = db_options('SELECT DISTINCT b.charge_type, b.charge_type FROM bank_charges b WHERE ' . owner_scope('bank_charges', 'b') . ' ORDER BY b.charge_type');

$state = list_state([
    'path'    => 'pages/bank_charges/index.php',
    'title'   => 'Bank Charges',
    'search'  => 'Search type, description, reference or card…',
    'filters' => [
        'card' => ['label' => 'Card', 'type' => 'select', 'options' => credit_card_options(null, false), 'all' => 'All cards'],
        'type' => ['label' => 'Charge type', 'type' => 'select', 'options' => $typeOptions, 'all' => 'All charge types'],
        'from' => ['label' => 'From', 'type' => 'date'],
        'to'   => ['label' => 'To', 'type' => 'date'],
    ],
    'sorts'   => [
        'newest' => ['Newest first', 'b.charge_date DESC, b.created_at DESC'],
        'oldest' => ['Oldest first', 'b.charge_date ASC'],
        'amount' => ['Highest amount', 'b.amount DESC'],
    ],
]);

$where = [owner_scope('bank_charges', 'b')];
$params = [];
add_search_condition($state, ['b.charge_type', 'b.description', 'b.reference_number', 'c.card_nickname'], $where, $params);
if ($state['filters']['card'] !== '') {
    $where[] = 'b.credit_card_id = :card';
    $params[':card'] = $state['filters']['card'];
}
if ($state['filters']['type'] !== '') {
    $where[] = 'b.charge_type = :type';
    $params[':type'] = $state['filters']['type'];
}
if ($state['filters']['from'] !== '') {
    $where[] = 'b.charge_date >= :from';
    $params[':from'] = $state['filters']['from'];
}
if ($state['filters']['to'] !== '') {
    $where[] = 'b.charge_date <= :to';
    $params[':to'] = $state['filters']['to'];
}

$from = 'bank_charges b JOIN credit_cards c ON c.id = b.credit_card_id';
$result = run_list($state, 'b.id, b.charge_date, b.charge_type, b.description, b.amount, b.reference_number, c.card_nickname, c.last_four', $from, $where, $params);
$sum = db_value('SELECT COALESCE(SUM(b.amount), 0) FROM ' . $from . ' WHERE ' . implode(' AND ', $where), $params);

$pageTitle = 'Bank Charges';
$activeNav = 'bank_charges';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Annual fees, late fees and other charges billed by the banks.', url('pages/bank_charges/create.php')) ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Bank charge list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/bank_charges/create.php'), 'Record fees and charges from the bank.', 'receipt') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Date</th>
                    <th scope="col">Card</th>
                    <th scope="col">Charge type</th>
                    <th scope="col">Description</th>
                    <th scope="col">Reference</th>
                    <th scope="col" class="num">Amount</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Date"><a data-modal-form class="table-link" href="<?= e(url('pages/bank_charges/view.php', ['id' => $row['id']])) ?>"><?= e(format_date($row['charge_date'])) ?></a></td>
                        <td data-label="Card"><?= e($row['card_nickname']) ?> <span class="text-muted">•••• <?= e($row['last_four']) ?></span></td>
                        <td data-label="Charge type"><?= e($row['charge_type']) ?></td>
                        <td data-label="Description"><?= show(excerpt($row['description'], 60)) ?></td>
                        <td data-label="Reference"><?= show($row['reference_number']) ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($row['amount'])) ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/bank_charges/view.php', ['id' => $row['id']]), 'eye', 'View charge') ?>
                                <?= icon_link(url('pages/bank_charges/edit.php', ['id' => $row['id']]), 'edit', 'Edit charge') ?>
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
