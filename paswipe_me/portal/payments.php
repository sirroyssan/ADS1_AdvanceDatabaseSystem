<?php
/**
 * Borrower portal - My payments (search, status filter, print).
 */

require_once __DIR__ . '/../includes/auth_check.php';

[$currentUser, $borrower] = require_borrower();

$state = list_state([
    'path'    => 'portal/payments.php',
    'title'   => 'My Payments',
    'search'  => 'Search payment no. or reference…',
    'filters' => [
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_PAYMENT_STATUS), 'all' => 'All statuses'],
        'from'   => ['label' => 'Paid from', 'type' => 'date'],
        'to'     => ['label' => 'Paid to', 'type' => 'date'],
    ],
    'sorts'   => [
        'newest' => ['Newest first', 'p.submitted_at DESC'],
        'oldest' => ['Oldest first', 'p.submitted_at ASC'],
        'amount' => ['Highest amount', 'p.amount DESC'],
    ],
    'base_where' => 'p.borrower_id = ' . db()->quote($borrower['id']),
]);

$where = ['p.borrower_id = :b'];
$params = [':b' => $borrower['id']];
add_search_condition($state, ['p.payment_number', 'p.reference_number'], $where, $params);
if ($state['filters']['status'] !== '') {
    $where[] = 'p.status = :status';
    $params[':status'] = $state['filters']['status'];
}
if ($state['filters']['from'] !== '') {
    $where[] = 'p.payment_date >= :from';
    $params[':from'] = $state['filters']['from'];
}
if ($state['filters']['to'] !== '') {
    $where[] = 'p.payment_date <= :to';
    $params[':to'] = $state['filters']['to'];
}

$from = 'borrower_payments p LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id';
$result = run_list($state, 'p.*, pm.display_name AS method_name', $from, $where, $params);
$approved = db_value("SELECT COALESCE(SUM(p.amount), 0) FROM $from WHERE " . implode(' AND ', $where) . " AND p.status = 'approved'", $params);

$pageTitle = 'My Payments';
$activeNav = 'portal_payments';
$layout = 'portal';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager'], 'My Payments — ' . borrower_name($borrower)) ?>
<?= render_list_header($state, 'Payments you submitted. Only approved payments reduce your balance.', url('portal/pay.php'), 'Submit payment') ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="My payments">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('portal/pay.php'), 'After you pay, submit the payment here with its reference number and proof.', 'receipt') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr>
                    <th scope="col" class="col-num">#</th><th scope="col">Payment no.</th><th scope="col">Paid on</th><th scope="col">Method</th>
                    <th scope="col">Reference</th><th scope="col" class="num">Amount</th><th scope="col">Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $p): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Payment no."><a class="table-link" href="<?= e(url('portal/payment.php', ['id' => $p['id']])) ?>"><?= e($p['payment_number']) ?></a></td>
                        <td data-label="Paid on"><?= e(format_date($p['payment_date'])) ?></td>
                        <td data-label="Method"><?= show($p['method_name']) ?></td>
                        <td data-label="Reference"><?= show($p['reference_number']) ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($p['amount'])) ?></td>
                        <td data-label="Status"><?= badge($p['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="5">Approved total (matching filters)</td><td class="num"><?= e(format_money($approved)) ?></td><td></td></tr></tfoot>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
