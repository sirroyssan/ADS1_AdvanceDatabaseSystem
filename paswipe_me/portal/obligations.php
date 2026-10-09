<?php
/**
 * Borrower portal - What I owe: the borrower's own obligations (search, filter, print).
 */

require_once __DIR__ . '/../includes/auth_check.php';

[$currentUser, $borrower] = require_borrower();

$state = list_state([
    'path'    => 'portal/obligations.php',
    'title'   => 'What I Owe',
    'search'  => 'Search number or description…',
    'filters' => [
        'open'   => ['label' => 'Show', 'type' => 'select', 'options' => ['open' => 'With remaining balance', 'closed' => 'Fully paid / closed'], 'all' => 'Everything'],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(array_diff(ENUM_OBLIGATION_STATUS, ['cancelled'])), 'all' => 'All statuses'],
    ],
    'sorts'   => [
        'due'    => ['Due date (soonest)', 'o.due_date IS NULL, o.due_date ASC'],
        'newest' => ['Newest first', 'o.obligation_date DESC'],
        'amount' => ['Highest remaining', 'o.outstanding_amount DESC'],
    ],
    'base_where' => "o.status <> 'cancelled' AND o.borrower_id = " . db()->quote($borrower['id']),
]);

$where = ["o.status <> 'cancelled'", 'o.borrower_id = :b'];
$params = [':b' => $borrower['id']];
add_search_condition($state, ['o.obligation_number', 'o.description'], $where, $params);
if ($state['filters']['open'] === 'open') {
    $where[] = "(o.outstanding_amount > 0 OR COALESCE(pen.penalties_unpaid, 0) > 0) AND o.status NOT IN ('written_off')";
} elseif ($state['filters']['open'] === 'closed') {
    $where[] = "((o.outstanding_amount = 0 AND COALESCE(pen.penalties_unpaid, 0) = 0) OR o.status IN ('paid', 'written_off'))";
}
if ($state['filters']['status'] !== '') {
    $where[] = 'o.status = :status';
    $params[':status'] = $state['filters']['status'];
}

$from = 'borrower_obligations o ' . penalty_totals_join();
$result = run_list($state, 'o.*, COALESCE(pen.penalties_unpaid, 0) AS penalties_unpaid, o.outstanding_amount + COALESCE(pen.penalties_unpaid, 0) AS total_due', $from, $where, $params);
$totals = db_row('SELECT COALESCE(SUM(o.total_amount_due), 0) AS due, COALESCE(SUM(o.total_paid), 0) AS paid, COALESCE(SUM(o.outstanding_amount), 0) AS remaining,
                         COALESCE(SUM(pen.penalties_unpaid), 0) AS penalties
                    FROM ' . $from . ' WHERE ' . implode(' AND ', $where), $params);

$pageTitle = 'What I Owe';
$activeNav = 'portal_obligations';
$layout = 'portal';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager'], 'What I Owe — ' . borrower_name($borrower)) ?>
<?= render_list_header($state, 'Everything you owe the card owner, with what you have paid and what remains.', null) ?>
<?= render_list_toolbar($state) ?>

<section class="figure-grid">
    <div class="figure"><p class="figure__label">Total amount due</p><p class="figure__value"><?= e(format_money($totals['due'])) ?></p></div>
    <div class="figure"><p class="figure__label">Paid</p><p class="figure__value"><?= e(format_money($totals['paid'])) ?></p></div>
    <div class="figure"><p class="figure__label">Outstanding</p><p class="figure__value"><?= e(format_money($totals['remaining'])) ?></p></div>
    <div class="figure"><p class="figure__label">+ Penalties</p><p class="figure__value<?= (float) $totals['penalties'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($totals['penalties'])) ?></p></div>
    <div class="figure figure--total"><p class="figure__label">= Total to pay</p><p class="figure__value"><?= e(format_money((float) $totals['remaining'] + (float) $totals['penalties'])) ?></p></div>
</section>

<section class="panel panel--flush" aria-label="My obligations">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, null, 'You have nothing recorded yet.', 'file-text') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr>
                    <th scope="col" class="col-num">#</th><th scope="col">Item</th><th scope="col">Date</th><th scope="col">Due date</th>
                    <th scope="col" class="num">Amount due</th><th scope="col" class="num">Paid</th><th scope="col" class="num">Outstanding</th><th scope="col" class="num">Penalties</th><th scope="col" class="num">Total</th><th scope="col">Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $o): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Item"><a class="table-link" href="<?= e(url('portal/obligation.php', ['id' => $o['id']])) ?>"><?= e($o['obligation_number']) ?></a>
                            <?php if ($o['description']): ?><br><span class="text-muted"><?= e($o['description']) ?></span><?php endif; ?></td>
                        <td data-label="Date"><?= e(format_date($o['obligation_date'])) ?></td>
                        <td data-label="Due date"><?= $o['due_date'] ? e(format_date($o['due_date'])) : '<span class="text-muted">—</span>' ?></td>
                        <td data-label="Amount due" class="num"><?= e(format_money($o['total_amount_due'])) ?></td>
                        <td data-label="Paid" class="num"><?= e(format_money($o['total_paid'])) ?></td>
                        <td data-label="Outstanding" class="num"><?= e(format_money($o['outstanding_amount'])) ?></td>
                        <td data-label="Penalties" class="num<?= (float) $o['penalties_unpaid'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($o['penalties_unpaid'])) ?></td>
                        <td data-label="Total" class="num"><strong><?= e(format_money($o['status'] === 'written_off' ? 0 : $o['total_due'])) ?></strong></td>
                        <td data-label="Status"><?= badge($o['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
