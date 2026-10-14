<?php
/**
 * Borrower portal - What I owe: everything the borrower owes the card owner,
 * one row per record (portal_all_items): cash loan shares, cash advances,
 * card purchases (one-time and installment) and other agreed amounts.
 * Search, filter, sort and print are done on the borrower's own small list.
 */

require_once __DIR__ . '/../includes/auth_check.php';

[$currentUser, $borrower] = require_borrower();

const PORTAL_ITEM_TYPES = ['Card purchase', 'Installment purchase', 'Cash loan', 'Cash advance', 'Amount owed'];

$state = list_state([
    'path'    => 'portal/obligations.php',
    'title'   => 'What I Owe',
    'search'  => 'Search number, item or card…',
    'filters' => [
        'open' => ['label' => 'Show', 'type' => 'select', 'options' => ['open' => 'With remaining balance', 'closed' => 'Fully paid / closed'], 'all' => 'Everything'],
        'type' => ['label' => 'Type', 'type' => 'select', 'options' => array_combine(PORTAL_ITEM_TYPES, PORTAL_ITEM_TYPES), 'all' => 'All types'],
    ],
    'sorts'   => [
        'due'    => ['Due date (soonest)', ''],
        'newest' => ['Newest first', ''],
        'amount' => ['Highest remaining', ''],
    ],
]);

$items = portal_all_items($borrower['id'], false);
$search = mb_strtolower($state['search'] ?? '');
$items = array_values(array_filter($items, static function ($i) use ($state, $search) {
    $open = $i['total'] > 0.004 && $i['status'] !== 'written_off';
    return ($state['filters']['open'] === '' || ($state['filters']['open'] === 'open') === $open)
        && ($state['filters']['type'] === '' || $i['type'] === $state['filters']['type'])
        && ($search === '' || str_contains(mb_strtolower($i['ref'] . ' ' . $i['title'] . ' ' . $i['type']), $search));
}));
if ($state['sort'] === 'newest') {
    usort($items, static fn ($a, $b) => $b['date'] <=> $a['date']);
} elseif ($state['sort'] === 'amount') {
    usort($items, static fn ($a, $b) => $b['total'] <=> $a['total']);
}
$totals = portal_items_totals($items);
$pager = ['page' => 1, 'pages' => 1, 'offset' => 0];

$pageTitle = 'What I Owe';
$activeNav = 'portal_obligations';
$layout = 'portal';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, count($items), $pager, 'What I Owe — ' . borrower_name($borrower)) ?>
<?= render_list_header($state, 'Everything you owe the card owner — cash loans, cash advances and card purchases — with what you have paid and what remains.', null) ?>
<?= render_list_toolbar($state) ?>

<section class="figure-grid">
    <div class="figure"><p class="figure__label">Total amount</p><p class="figure__value"><?= e(format_money($totals['amount'])) ?></p></div>
    <div class="figure"><p class="figure__label">Paid</p><p class="figure__value"><?= e(format_money($totals['paid'])) ?></p></div>
    <div class="figure"><p class="figure__label">Outstanding</p><p class="figure__value"><?= e(format_money($totals['outstanding'])) ?></p></div>
    <div class="figure"><p class="figure__label">+ Penalties</p><p class="figure__value<?= $totals['penalties'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($totals['penalties'])) ?></p></div>
    <div class="figure figure--total"><p class="figure__label">= Total to pay</p><p class="figure__value"><?= e(format_money($totals['total'])) ?></p></div>
    <div class="figure"><p class="figure__label">Due this period</p><p class="figure__value"><?= e(format_money($totals['due_now'])) ?></p></div>
</section>

<section class="panel panel--flush" aria-label="Everything I owe">
    <?php if (!$items): ?>
        <?= render_empty_state($state, null, 'You have nothing recorded yet.', 'file-text') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr>
                    <th scope="col" class="col-num">#</th><th scope="col">Type</th><th scope="col">Item</th><th scope="col">Date</th>
                    <th scope="col" class="num">Amount</th><th scope="col" class="num">Paid</th><th scope="col" class="num">Outstanding</th><th scope="col" class="num">Penalties</th>
                    <th scope="col" class="num">Due this period</th><th scope="col">Next due</th><th scope="col">Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($items as $n => $i): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($n + 1) ?></td>
                        <td data-label="Type"><?= e($i['type']) ?></td>
                        <td data-label="Item"><a data-modal-form class="table-link" href="<?= e($i['link']) ?>"><?= e(excerpt($i['title'], 45)) ?></a>
                            <br><span class="text-muted nowrap"><?= e($i['ref']) ?></span><?= $i['date'] ? ' <span class="text-muted nowrap">· ' . e(format_date($i['date'])) . '</span>' : '' ?><?= !empty($i['bank']) ? ' <span class="text-muted nowrap">· ' . e($i['bank']) . '</span>' : '' ?></td>
                        <td data-label="Date"><?= $i['date'] ? e(format_date($i['date'])) : '—' ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($i['amount'])) ?></td>
                        <td data-label="Paid" class="num"><?= e(format_money($i['paid'])) ?></td>
                        <td data-label="Outstanding" class="num"><?= e(format_money($i['outstanding'])) ?></td>
                        <td data-label="Penalties" class="num<?= $i['penalties'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($i['penalties'])) ?></td>
                        <td data-label="Due this period" class="num"><strong><?= e(format_money($i['due_now'])) ?></strong><?= $i['installment'] ? '<br><span class="text-muted">' . e($i['installment']) . '</span>' : '' ?></td>
                        <td data-label="Next due"><?= $i['due_date'] && $i['total'] > 0.004 ? e(format_date($i['due_date'])) : '<span class="text-muted">—</span>' ?></td>
                        <td data-label="Status"><?= portal_item_status($i) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<p class="field-help"><?= e(count($items)) ?> item(s). Card purchases are made on the owner's credit card; their installments follow the card's statements.</p>

<?php require APP_ROOT . '/includes/footer.php'; ?>
