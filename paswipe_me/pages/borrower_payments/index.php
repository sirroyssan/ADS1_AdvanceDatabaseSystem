<?php
/**
 * Borrower Payments - list, search, filter (status, allocation, borrower, dates), print with totals.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/payment_functions.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/borrower_payments/index.php',
    'title'   => 'Borrower Payments',
    'search'  => 'Search payment number, reference or borrower…',
    'filters' => [
        'status'     => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_PAYMENT_STATUS) + ['pending' => 'Waiting for review (all)'], 'all' => 'All statuses'],
        'allocation' => ['label' => 'Allocation', 'type' => 'select', 'options' => enum_options(ENUM_ALLOCATION_STATUS), 'all' => 'Any allocation'],
        'borrower'   => ['label' => 'Borrower', 'type' => 'select', 'options' => borrower_options(null, false), 'all' => 'All borrowers'],
        'from'       => ['label' => 'From', 'type' => 'date'],
        'to'         => ['label' => 'To', 'type' => 'date'],
    ],
    'sorts'   => [
        'newest' => ['Newest first', 'p.payment_date DESC, p.submitted_at DESC'],
        'oldest' => ['Oldest first', 'p.payment_date ASC'],
        'amount' => ['Highest amount', 'p.amount DESC'],
    ],
]);

$where = [];
$params = [];
add_search_condition($state, ['p.payment_number', 'p.reference_number', 'b.first_name', 'b.last_name', 'b.borrower_number'], $where, $params);
if ($state['filters']['status'] === 'pending') {
    $where[] = "p.status IN ('submitted', 'under_review', 'correction_required')";
} elseif ($state['filters']['status'] !== '') {
    $where[] = 'p.status = :status';
    $params[':status'] = $state['filters']['status'];
}
foreach (['allocation' => 'p.allocation_status', 'borrower' => 'p.borrower_id'] as $key => $column) {
    if ($state['filters'][$key] !== '') {
        $where[] = $column . ' = :' . $key;
        $params[':' . $key] = $state['filters'][$key];
    }
}
if ($state['filters']['from'] !== '') {
    $where[] = 'p.payment_date >= :from';
    $params[':from'] = $state['filters']['from'];
}
if ($state['filters']['to'] !== '') {
    $where[] = 'p.payment_date <= :to';
    $params[':to'] = $state['filters']['to'];
}

$from = 'borrower_payments p JOIN borrowers b ON b.id = p.borrower_id LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id';
$result = run_list(
    $state,
    'p.id, p.payment_number, p.payment_date, p.amount, p.reference_number, p.status, p.allocation_status, b.first_name, b.last_name, pm.display_name AS method_name',
    $from,
    $where,
    $params
);
$totals = db_row("SELECT COALESCE(SUM(p.amount), 0) AS submitted, COALESCE(SUM(IF(p.status = 'approved', p.amount, 0)), 0) AS approved FROM "
    . $from . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $params);

$pageTitle = 'Borrower Payments';
$activeNav = 'borrower_payments';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Payments sent by borrowers. Review, approve, then allocate them to obligations.', url('pages/borrower_payments/create.php'), 'Record Payment',
    '<a href="' . e(url('pages/borrower_payments/index.php', ['status' => 'pending'])) . '" class="btn btn--outline">' . icon('receipt') . ' <span>Waiting for review</span></a>') ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Payment list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/borrower_payments/create.php'), 'Record a payment received from a borrower.', 'receipt') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Payment #</th>
                    <th scope="col">Date</th>
                    <th scope="col">Borrower</th>
                    <th scope="col">Method / Reference</th>
                    <th scope="col" class="num">Amount</th>
                    <th scope="col">Status</th>
                    <th scope="col">Allocation</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Payment #"><a data-modal-form class="table-link" href="<?= e(url('pages/borrower_payments/view.php', ['id' => $row['id']])) ?>"><?= e($row['payment_number']) ?></a></td>
                        <td data-label="Date"><?= e(format_date($row['payment_date'])) ?></td>
                        <td data-label="Borrower"><?= e(borrower_name($row)) ?></td>
                        <td data-label="Method / Reference"><?= show(trim(($row['method_name'] ?? '') . ($row['reference_number'] ? ' · ' . $row['reference_number'] : ''), ' ·')) ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($row['amount'])) ?></td>
                        <td data-label="Status"><?= badge($row['status']) ?></td>
                        <td data-label="Allocation"><?= badge($row['allocation_status']) ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/borrower_payments/view.php', ['id' => $row['id']]), 'eye', 'View ' . $row['payment_number']) ?>
                                <?php if (in_array($row['status'], PAYMENT_EDITABLE_STATUSES, true)): ?>
                                    <?= icon_link(url('pages/borrower_payments/edit.php', ['id' => $row['id']]), 'edit', 'Edit ' . $row['payment_number']) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="5">Total · approved <?= e(format_money($totals['approved'])) ?></td><td class="num"><?= e(format_money($totals['submitted'])) ?></td><td colspan="3"></td></tr></tfoot>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
