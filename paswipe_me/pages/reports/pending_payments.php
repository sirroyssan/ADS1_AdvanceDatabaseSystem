<?php
/**
 * Report - Pending Payment Approvals (view: vw_pending_payment_approvals).
 */

require_once __DIR__ . '/../../includes/auth_check.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/reports/pending_payments.php',
    'title'   => 'Pending Payment Approvals',
    'search'  => 'Search payment number, reference or borrower…',
    'filters' => [
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(['submitted', 'under_review', 'correction_required']), 'all' => 'All pending statuses'],
        'from'   => ['label' => 'Paid from', 'type' => 'date'],
        'to'     => ['label' => 'Paid to', 'type' => 'date'],
    ],
    'sorts'   => [
        'oldest' => ['Waiting longest', 'v.submitted_at ASC'],
        'amount' => ['Highest amount', 'v.amount DESC'],
    ],
]);

$where = [];
$params = [];
add_search_condition($state, ['v.payment_number', 'v.reference_number', 'v.borrower_number', 'v.first_name', 'v.last_name'], $where, $params);
if ($state['filters']['status'] !== '') {
    $where[] = 'v.status = :status';
    $params[':status'] = $state['filters']['status'];
}
if ($state['filters']['from'] !== '') {
    $where[] = 'v.payment_date >= :from';
    $params[':from'] = $state['filters']['from'];
}
if ($state['filters']['to'] !== '') {
    $where[] = 'v.payment_date <= :to';
    $params[':to'] = $state['filters']['to'];
}

$from = 'vw_pending_payment_approvals v';
$result = run_list($state, 'v.id, v.payment_number, v.borrower_number, v.first_name, v.last_name, v.payment_date, v.submitted_at, v.amount, v.reference_number, v.method_type, v.display_name, v.status', $from, $where, $params);
$sum = db_value('SELECT COALESCE(SUM(v.amount), 0) FROM ' . $from . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $params);

$pageTitle = 'Pending Payment Approvals';
$activeNav = 'reports';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Reports' => url('pages/reports/index.php'), 'Pending Payment Approvals' => null]) ?>
<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Submitted, under review or waiting for correction.', null) ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, null, 'No payments are waiting for review.', 'check') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr><th scope="col" class="col-num">#</th><th scope="col">Payment #</th><th scope="col">Borrower</th><th scope="col">Paid on</th><th scope="col">Submitted</th>
                    <th scope="col">Method / Reference</th><th scope="col" class="num">Amount</th><th scope="col">Status</th></tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Payment #"><a data-modal-form class="table-link" href="<?= e(url('pages/borrower_payments/view.php', ['id' => $row['id']])) ?>"><?= e($row['payment_number']) ?></a></td>
                        <td data-label="Borrower"><?= e(borrower_name($row)) ?></td>
                        <td data-label="Paid on"><?= e(format_date($row['payment_date'])) ?></td>
                        <td data-label="Submitted"><?= e(format_datetime($row['submitted_at'])) ?></td>
                        <td data-label="Method / Reference"><?= show(trim(($row['display_name'] ?? '') . ($row['reference_number'] ? ' · ' . $row['reference_number'] : ''), ' ·')) ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($row['amount'])) ?></td>
                        <td data-label="Status"><?= badge($row['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="6">Total (all matching)</td><td class="num"><?= e(format_money($sum)) ?></td><td></td></tr></tfoot>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
