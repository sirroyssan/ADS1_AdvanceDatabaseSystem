<?php
/**
 * Late & Finance Charges (owner): every late charge on my borrowers' obligations
 * and card purchases (paid, unpaid, waived), the interest and fees recorded on
 * their items, and the bank charges on my cards. Waive or add a late charge on
 * the item itself (its Penalties panel).
 */

require_once __DIR__ . '/../../includes/auth_check.php';

$currentUser = require_admin();

[$where, $params] = late_charges_where(null);
$state = list_state([
    'path'    => 'pages/late_charges/index.php',
    'title'   => 'Late & Finance Charges',
    'search'  => 'Search borrower, item number or charge…',
    'filters' => [
        'borrower' => ['label' => 'Borrower', 'type' => 'select', 'options' => borrower_options(null, false), 'all' => 'All borrowers'],
        'state'    => ['label' => 'Show', 'type' => 'select', 'options' => ['unpaid' => 'Still to pay', 'paid' => 'Paid', 'waived' => 'Waived'], 'all' => 'All late charges'],
        'from'     => ['label' => 'Charged from', 'type' => 'date'],
        'to'       => ['label' => 'Charged to', 'type' => 'date'],
    ],
    'sorts'   => [
        'newest' => ['Newest first', 'ap.applied_date DESC, item_number'],
        'oldest' => ['Oldest first', 'ap.applied_date ASC, item_number'],
        'amount' => ['Highest unpaid', 'ap.remaining_amount DESC'],
    ],
    'base_where' => implode(' AND ', $where),
]);

late_charges_filter($state, $where, $params);
$borrowerId = $state['filters']['borrower'];
if ($borrowerId !== '') {
    $where[] = 'b.id = :borrower';
    $params[':borrower'] = $borrowerId;
}
$result = run_list($state, LATE_CHARGES_SELECT, LATE_CHARGES_FROM, $where, $params);
$sums = late_charges_sums($where, $params);
$finance = finance_charges_rows($borrowerId !== '' && owns('borrowers', $borrowerId) ? $borrowerId : null);

// Bank charges on my cards (in the same date range). They are the card owner's, not a borrower's.
$bankWhere = [owner_scope('bank_charges', 'c')];
$bankParams = [];
foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
    if ($state['filters'][$k] !== '') {
        $bankWhere[] = "c.charge_date $op :bc_$k";
        $bankParams[":bc_$k"] = $state['filters'][$k];
    }
}
$bank = db_all(
    "SELECT c.*, cc.card_nickname, cc.last_four FROM bank_charges c JOIN credit_cards cc ON cc.id = c.credit_card_id
      WHERE " . implode(' AND ', $bankWhere) . ' ORDER BY c.charge_date DESC LIMIT 100',
    $bankParams
);

$pageTitle = 'Late & Finance Charges';
$activeNav = 'late_charges';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Late charges on your borrowers\' obligations and card purchases, the interest and fees on their items, and your cards\' bank charges.', null) ?>

<section class="stat-grid" aria-label="Late charge totals">
    <article class="stat-card"><p class="stat-card__label">Charged (matching filters)</p><p class="stat-card__value stat-card__value--money"><?= e(format_money($sums['charged'])) ?></p></article>
    <article class="stat-card"><p class="stat-card__label">Paid</p><p class="stat-card__value stat-card__value--money"><?= e(format_money($sums['paid'])) ?></p></article>
    <article class="stat-card"><p class="stat-card__label">Still to collect</p><p class="stat-card__value stat-card__value--money<?= (float) $sums['unpaid'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($sums['unpaid'])) ?></p></article>
</section>

<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Late charges">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, null, 'No late charges on your borrowers\' items.', 'check') ?>
    <?php else: ?>
        <?= late_charges_table($result['rows'], $sums, $result['pager']['offset'], true) ?>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<section class="panel" aria-label="Finance charges">
    <header class="panel__header"><h3>Finance charges and bank fees on borrowers' items</h3></header>
    <p class="field-help">Interest and fees recorded on each item (installment plan of a purchase, agreed interest and fees of an obligation). They are part of what the borrower repays; borrowers see their own in the portal.</p>
    <?= finance_charges_table($finance, true) ?>
</section>

<section class="panel" aria-label="Bank charges">
    <header class="panel__header"><h3>Bank charges on your cards</h3>
        <a class="btn btn--outline btn--sm no-print" href="<?= e(url('pages/bank_charges/index.php')) ?>"><?= icon('receipt') ?> <span>Bank Charges</span></a></header>
    <p class="field-help">Finance charges, late fees and other charges the bank billed on your cards. They are not linked to a borrower in your records, so borrowers do not see them.</p>
    <?php if (!$bank): ?>
        <p class="empty-inline">No bank charges<?= $state['filters']['from'] !== '' || $state['filters']['to'] !== '' ? ' in these dates' : '' ?>.</p>
    <?php else: ?>
        <div class="table-wrap"><table class="table table--cards table--compact">
            <thead><tr><th scope="col">Date</th><th scope="col">Card</th><th scope="col">Type</th><th scope="col">Description</th><th scope="col" class="num">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($bank as $c): ?>
                <tr><td data-label="Date"><?= e(format_date($c['charge_date'])) ?></td><td data-label="Card"><?= e($c['card_nickname'] . ' •••• ' . $c['last_four']) ?></td>
                    <td data-label="Type"><?= e($c['charge_type']) ?></td><td data-label="Description"><?= show($c['description']) ?></td>
                    <td data-label="Amount" class="num"><?= e(format_money($c['amount'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="4">Total<?= count($bank) === 100 ? ' (latest 100)' : '' ?></td><td class="num"><strong><?= e(format_money(array_sum(array_column($bank, 'amount')))) ?></strong></td></tr></tfoot>
        </table></div>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
