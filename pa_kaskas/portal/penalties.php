<?php
/**
 * Borrower portal - all my penalties (late charges): unpaid, paid and waived,
 * with search, filters and print. Outstanding + unpaid penalties = total to pay.
 */

require_once __DIR__ . '/../includes/auth_check.php';

[$currentUser, $borrower] = require_borrower();
$bid = $borrower['id'];

[$where, $params] = late_charges_where($bid);
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
        'oldest' => ['Oldest first', 'ap.applied_date ASC, item_number'],
        'newest' => ['Newest first', 'ap.applied_date DESC, item_number'],
        'amount' => ['Highest unpaid', 'ap.remaining_amount DESC'],
    ],
    'base_where' => str_replace(':lc_b', db()->quote($bid), implode(' AND ', $where)),
]);

// Late charges on obligations and on card purchases (includes/late_charges.php).
late_charges_filter($state, $where, $params);
$result = run_list($state, LATE_CHARGES_SELECT, LATE_CHARGES_FROM, $where, $params);
$sums = late_charges_sums($where, $params);
// Everything owed: obligations and card purchases, the same totals as My Account.
$totals = portal_items_totals(portal_all_items($bid, false));
$collect = ['outstanding' => $totals['outstanding'], 'penalties' => $totals['penalties'], 'total_due' => $totals['total']];
$finance = finance_charges_rows($bid);

$pageTitle = 'My Penalties';
$activeNav = 'portal_penalties';
$layout = 'portal';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager'], 'My Penalties — ' . borrower_name($borrower)) ?>
<?= render_list_header($state, 'Late charges added to your items, and the interest and fees recorded on them. Your payments go where you choose.', url('portal/pay.php'), 'Submit payment') ?>

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
        <?= late_charges_table($result['rows'], $sums, $result['pager']['offset'], false) ?>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php if (!$state['print'] || $finance): ?>
<section class="panel" aria-label="Finance charges">
    <header class="panel__header"><h3>Finance charges and bank fees</h3></header>
    <p class="field-help">Interest and fees recorded on your items when they were set up. They are already part of your installments or amount due, not an extra charge.</p>
    <?= finance_charges_table($finance, false) ?>
</section>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
