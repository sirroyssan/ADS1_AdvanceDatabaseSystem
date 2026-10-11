<?php
/**
 * Cash Advances - list, search, filter, print with totals.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/cash_advance_functions.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/cash_advances/index.php',
    'title'   => 'Cash Advances',
    'search'  => 'Search reference, channel or card…',
    'filters' => [
        'card'   => ['label' => 'Card', 'type' => 'select', 'options' => credit_card_options(null, false), 'all' => 'All cards'],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_CASH_ADVANCE_STATUS), 'all' => 'All statuses'],
        'from'   => ['label' => 'From', 'type' => 'date'],
        'to'     => ['label' => 'To', 'type' => 'date'],
    ],
    'sorts'   => [
        'newest' => ['Newest first', 'a.advance_date DESC, a.created_at DESC'],
        'oldest' => ['Oldest first', 'a.advance_date ASC'],
        'amount' => ['Highest total', 'a.total_amount DESC'],
    ],
]);

$where = [owner_scope('cash_advances', 'a')];
$params = [];
add_search_condition($state, ['a.reference_number', 'a.channel', 'c.card_nickname', 'c.last_four'], $where, $params);
foreach (['card' => 'a.credit_card_id', 'status' => 'a.status'] as $key => $column) {
    if ($state['filters'][$key] !== '') {
        $where[] = $column . ' = :' . $key;
        $params[':' . $key] = $state['filters'][$key];
    }
}
if ($state['filters']['from'] !== '') {
    $where[] = 'a.advance_date >= :from';
    $params[':from'] = $state['filters']['from'];
}
if ($state['filters']['to'] !== '') {
    $where[] = 'a.advance_date <= :to';
    $params[':to'] = $state['filters']['to'];
}

$from = 'cash_advances a JOIN credit_cards c ON c.id = a.credit_card_id';
$result = run_list($state, 'a.id, a.advance_date, a.reference_number, a.channel, a.amount, a.fee, a.interest, a.total_amount, a.status, c.card_nickname, c.last_four', $from, $where, $params);
$totals = db_row("SELECT COALESCE(SUM(IF(a.status <> 'cancelled', a.amount, 0)), 0) AS amount, COALESCE(SUM(IF(a.status <> 'cancelled', a.total_amount, 0)), 0) AS total FROM "
    . $from . ' WHERE ' . implode(' AND ', $where), $params);

$pageTitle = 'Cash Advances';
$activeNav = 'cash_advances';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Cash withdrawn against the credit cards.', url('pages/cash_advances/create.php')) ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Cash advance list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/cash_advances/create.php'), 'Record cash advances taken on the cards.', 'banknote') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Date</th>
                    <th scope="col">Card</th>
                    <th scope="col">Reference / Channel</th>
                    <th scope="col" class="num">Amount</th>
                    <th scope="col" class="num">Fee + Interest</th>
                    <th scope="col" class="num">Total</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Date"><a data-modal-form class="table-link" href="<?= e(url('pages/cash_advances/view.php', ['id' => $row['id']])) ?>"><?= e(format_date($row['advance_date'])) ?></a></td>
                        <td data-label="Card"><?= e($row['card_nickname']) ?> <span class="text-muted">•••• <?= e($row['last_four']) ?></span></td>
                        <td data-label="Reference / Channel"><?= show(trim(($row['reference_number'] ?? '') . ($row['channel'] ? ' · ' . $row['channel'] : ''), ' ·')) ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($row['amount'])) ?></td>
                        <td data-label="Fee + Interest" class="num"><?= e(format_money((float) $row['fee'] + (float) $row['interest'])) ?></td>
                        <td data-label="Total" class="num"><?= e(format_money($row['total_amount'])) ?></td>
                        <td data-label="Status"><?= badge($row['status']) ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/cash_advances/view.php', ['id' => $row['id']]), 'eye', 'View cash advance') ?>
                                <?php if ($row['status'] !== 'cancelled'): ?>
                                    <?= icon_link(url('pages/cash_advances/edit.php', ['id' => $row['id']]), 'edit', 'Edit cash advance') ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="4">Total excluding cancelled</td><td class="num"><?= e(format_money($totals['amount'])) ?></td><td></td><td class="num"><?= e(format_money($totals['total'])) ?></td><td colspan="2"></td></tr></tfoot>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
