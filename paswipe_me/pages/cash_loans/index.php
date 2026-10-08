<?php
/**
 * Cash Loans - list, search, filter, print with totals.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/cash_loan_functions.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/cash_loans/index.php',
    'title'   => 'Cash Loans',
    'search'  => 'Search loan reference, offer number or card…',
    'filters' => [
        'card'   => ['label' => 'Card', 'type' => 'select', 'options' => credit_card_options(null, false), 'all' => 'All cards'],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_CASH_LOAN_STATUS), 'all' => 'All statuses'],
        'from'   => ['label' => 'Start from', 'type' => 'date'],
        'to'     => ['label' => 'Start to', 'type' => 'date'],
    ],
    'sorts'   => [
        'newest' => ['Newest first', 'l.created_at DESC'],
        'start'  => ['Start date', 'l.start_date DESC'],
        'amount' => ['Highest repayment', 'l.total_repayment DESC'],
    ],
]);

$where = [];
$params = [];
add_search_condition($state, ['l.loan_reference', 'l.offer_number', 'c.card_nickname', 'c.last_four'], $where, $params);
foreach (['card' => 'l.credit_card_id', 'status' => 'l.status'] as $key => $column) {
    if ($state['filters'][$key] !== '') {
        $where[] = $column . ' = :' . $key;
        $params[':' . $key] = $state['filters'][$key];
    }
}
if ($state['filters']['from'] !== '') {
    $where[] = 'l.start_date >= :from';
    $params[':from'] = $state['filters']['from'];
}
if ($state['filters']['to'] !== '') {
    $where[] = 'l.start_date <= :to';
    $params[':to'] = $state['filters']['to'];
}

$from = 'cash_loans l JOIN credit_cards c ON c.id = l.credit_card_id';
$result = run_list(
    $state,
    'l.id, l.loan_reference, l.accepted_amount, l.actual_cash_received, l.total_repayment, l.term_months, l.monthly_payment, l.start_date, l.status,
     c.card_nickname, c.last_four,
     (SELECT COALESCE(SUM(s.remaining_amount), 0) FROM cash_loan_schedules s WHERE s.cash_loan_id = l.id AND s.status NOT IN (\'waived\', \'cancelled\')) AS remaining',
    $from,
    $where,
    $params
);
$totals = db_row("SELECT COALESCE(SUM(IF(l.status NOT IN ('cancelled', 'rejected'), l.actual_cash_received, 0)), 0) AS received,
                         COALESCE(SUM(IF(l.status NOT IN ('cancelled', 'rejected'), l.total_repayment, 0)), 0) AS repayment FROM "
    . $from . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $params);

$pageTitle = 'Cash Loans';
$activeNav = 'cash_loans';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Cash loan offers and loans taken on the credit cards.', url('pages/cash_loans/create.php')) ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Cash loan list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/cash_loans/create.php'), 'Record cash loan offers and accepted loans.', 'banknote') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Reference</th>
                    <th scope="col">Card</th>
                    <th scope="col">Start</th>
                    <th scope="col" class="num">Cash received</th>
                    <th scope="col" class="num">Total repayment</th>
                    <th scope="col">Terms</th>
                    <th scope="col" class="num">Remaining</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Reference"><a class="table-link" href="<?= e(url('pages/cash_loans/view.php', ['id' => $row['id']])) ?>"><?= e($row['loan_reference']) ?></a></td>
                        <td data-label="Card"><?= e($row['card_nickname']) ?> <span class="text-muted">•••• <?= e($row['last_four']) ?></span></td>
                        <td data-label="Start"><?= e(format_date($row['start_date'])) ?></td>
                        <td data-label="Cash received" class="num"><?= e(format_money($row['actual_cash_received'])) ?></td>
                        <td data-label="Total repayment" class="num"><?= e(format_money($row['total_repayment'])) ?></td>
                        <td data-label="Terms"><?= $row['term_months'] ? e($row['term_months']) . ' mo × ' . e(format_money($row['monthly_payment'])) : '<span class="text-muted">—</span>' ?></td>
                        <td data-label="Remaining" class="num"><?= e(format_money($row['remaining'])) ?></td>
                        <td data-label="Status"><?= badge($row['status']) ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/cash_loans/view.php', ['id' => $row['id']]), 'eye', 'View ' . $row['loan_reference']) ?>
                                <?= icon_link(url('pages/cash_loans/edit.php', ['id' => $row['id']]), 'edit', 'Edit ' . $row['loan_reference']) ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="4">Total excluding cancelled/rejected</td><td class="num"><?= e(format_money($totals['received'])) ?></td><td class="num"><?= e(format_money($totals['repayment'])) ?></td><td colspan="4"></td></tr></tfoot>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
