<?php
/**
 * Installment Plans - list with progress, search, filter, print.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/plan_functions.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/installment_plans/index.php',
    'title'   => 'Installment Plans',
    'search'  => 'Search transaction number or borrower…',
    'filters' => [
        'status'    => ['label' => 'Status', 'type' => 'select', 'options' => PLAN_STATUSES, 'all' => 'All statuses'],
        'frequency' => ['label' => 'Frequency', 'type' => 'select', 'options' => enum_options(ENUM_FREQUENCY), 'all' => 'All frequencies'],
        'card'      => ['label' => 'Card', 'type' => 'select', 'options' => credit_card_options(null, false), 'all' => 'All cards'],
    ],
    'sorts'   => [
        'newest' => ['Newest first', 'p.start_date DESC'],
        'ending' => ['Ending soonest', 'p.end_date ASC'],
        'amount' => ['Highest repayment', 'p.total_repayment DESC'],
    ],
]);

$where = [owner_scope('installment_plans', 'p')];
$params = [];
add_search_condition($state, ['t.transaction_number', 'b.first_name', 'b.last_name'], $where, $params);
foreach (['status' => 'p.status', 'frequency' => 'p.frequency', 'card' => 't.credit_card_id'] as $key => $column) {
    if ($state['filters'][$key] !== '') {
        $where[] = $column . ' = :' . $key;
        $params[':' . $key] = $state['filters'][$key];
    }
}

$result = run_list(
    $state,
    "p.id, p.total_repayment, p.number_of_installments, p.installment_amount, p.frequency, p.start_date, p.end_date, p.status,
     t.transaction_number, b.first_name, b.last_name,
     (SELECT COUNT(*) FROM installment_schedules s WHERE s.installment_plan_id = p.id AND s.status IN ('paid', 'waived')) AS settled,
     (SELECT COALESCE(SUM(s.remaining_amount), 0) FROM installment_schedules s WHERE s.installment_plan_id = p.id AND s.status NOT IN ('waived', 'cancelled')) AS remaining,
     (SELECT MIN(s.due_date) FROM installment_schedules s WHERE s.installment_plan_id = p.id AND s.status NOT IN ('paid', 'waived', 'cancelled')) AS next_due",
    'installment_plans p JOIN transactions t ON t.id = p.transaction_id LEFT JOIN borrowers b ON b.id = t.borrower_id',
    $where,
    $params
);

$pageTitle = 'Installment Plans';
$activeNav = 'installments';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Installment terms of card transactions and their payment schedules.', url('pages/installment_plans/create.php')) ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Installment plan list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/installment_plans/create.php'), 'Create a plan for a transaction bought on installment.', 'repeat') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Transaction</th>
                    <th scope="col">Borrower</th>
                    <th scope="col">Terms</th>
                    <th scope="col">Progress</th>
                    <th scope="col">Next due</th>
                    <th scope="col" class="num">Remaining</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Transaction"><a data-modal-form class="table-link" href="<?= e(url('pages/installment_plans/view.php', ['id' => $row['id']])) ?>"><?= e($row['transaction_number']) ?></a></td>
                        <td data-label="Borrower"><?= $row['first_name'] ? e(borrower_name($row)) : '<span class="text-muted">Owner</span>' ?></td>
                        <td data-label="Terms"><?= e($row['number_of_installments']) ?> × <?= e(format_money($row['installment_amount'])) ?> <span class="text-muted"><?= e($row['frequency']) ?></span></td>
                        <td data-label="Progress"><?= e($row['settled']) ?> / <?= e($row['number_of_installments']) ?> paid</td>
                        <td data-label="Next due"><?= e($row['next_due'] ? format_date($row['next_due']) : '—') ?></td>
                        <td data-label="Remaining" class="num"><?= e(format_money($row['remaining'])) ?></td>
                        <td data-label="Status"><?= badge($row['status']) ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/installment_plans/view.php', ['id' => $row['id']]), 'eye', 'View plan') ?>
                                <?= icon_link(url('pages/installment_plans/edit.php', ['id' => $row['id']]), 'edit', 'Edit plan') ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
