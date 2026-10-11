<?php
/**
 * Staff - acknowledgement receipts issued (search, filter by date, reprint).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/includes/receipts.php';

$currentUser = require_staff_area();

$state = list_state([
    'path'    => 'pages/staff/receipts.php',
    'title'   => 'Receipts',
    'search'  => 'Search receipt no., borrower or reference…',
    'filters' => [
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['issued' => 'Issued', 'void' => 'Void'], 'all' => 'All'],
        'from'   => ['label' => 'From', 'type' => 'date'],
        'to'     => ['label' => 'To', 'type' => 'date'],
    ],
    'sorts'   => ['newest' => ['Newest first', 'r.received_at DESC'], 'amount' => ['Highest amount', 'r.amount_tendered DESC']],
]);
$where = [owner_scope('payment_receipts', 'r')];
$params = [];
add_search_condition($state, ['r.receipt_number', 'b.first_name', 'b.last_name', 'r.reference_number'], $where, $params);
if ($state['filters']['status'] !== '') {
    $where[] = 'r.status = :st';
    $params[':st'] = $state['filters']['status'];
}
if ($state['filters']['from'] !== '') {
    $where[] = 'r.payment_date >= :from';
    $params[':from'] = $state['filters']['from'];
}
if ($state['filters']['to'] !== '') {
    $where[] = 'r.payment_date <= :to';
    $params[':to'] = $state['filters']['to'];
}
$result = run_list($state, 'r.*, b.first_name, b.last_name', 'payment_receipts r JOIN borrowers b ON b.id = r.borrower_id', $where, $params);

$pageTitle = 'Receipts';
$activeNav = 'staff_receipts';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Acknowledgement receipts for payments received in person. Reprints are marked as copies.', url('pages/staff/receive.php'), 'Receive payment') ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Receipts">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/staff/receive.php'), 'Receipts appear here after you receive a payment.', 'receipt') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr><th scope="col">Receipt</th><th scope="col">Borrower</th><th scope="col">Received</th><th scope="col">Received by</th><th scope="col" class="num">Amount</th><th scope="col">Status</th><th scope="col" class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $r): ?>
                    <tr>
                        <td data-label="Receipt"><a class="table-link" href="<?= e(url('pages/staff/receipt.php', ['id' => $r['id']])) ?>"><?= e($r['receipt_number']) ?></a></td>
                        <td data-label="Borrower"><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?></td>
                        <td data-label="Received"><?= e(format_datetime($r['received_at'])) ?></td>
                        <td data-label="Received by"><?= e(user_name_of($r['received_by'])) ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($r['amount_tendered'])) ?></td>
                        <td data-label="Status"><?= $r['status'] === 'void' ? status_badge('void', ['void' => 'danger']) : status_badge('issued', ['issued' => 'success']) ?></td>
                        <td class="actions"><a class="icon-button" target="_blank" rel="noopener" title="Print" aria-label="Print <?= e($r['receipt_number']) ?>" href="<?= e(url('pages/staff/receipt.php', ['id' => $r['id'], 'print' => 1])) ?>"><?= icon('printer') ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
