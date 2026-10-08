<?php
/**
 * Obligations - list, search, filter (borrower, status, due date range), print with totals.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/obligation_functions.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/obligations/index.php',
    'title'   => 'Borrower Obligations',
    'search'  => 'Search obligation number, description or borrower…',
    'filters' => [
        'borrower' => ['label' => 'Borrower', 'type' => 'select', 'options' => borrower_options(null, false), 'all' => 'All borrowers'],
        'status'   => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_OBLIGATION_STATUS), 'all' => 'All statuses'],
        'open'     => ['label' => 'Balance', 'type' => 'select', 'options' => ['open' => 'With outstanding balance', 'overdue' => 'Past due date'], 'all' => 'Any balance'],
        'from'     => ['label' => 'Due from', 'type' => 'date'],
        'to'       => ['label' => 'Due to', 'type' => 'date'],
    ],
    'sorts'   => [
        'due'         => ['Due date', 'o.due_date IS NULL, o.due_date ASC'],
        'newest'      => ['Newest first', 'o.obligation_date DESC'],
        'outstanding' => ['Highest outstanding', 'o.outstanding_amount DESC'],
    ],
]);

$where = [];
$params = [];
add_search_condition($state, ['o.obligation_number', 'o.description', 'b.first_name', 'b.last_name', 'b.borrower_number'], $where, $params);
foreach (['borrower' => 'o.borrower_id', 'status' => 'o.status'] as $key => $column) {
    if ($state['filters'][$key] !== '') {
        $where[] = $column . ' = :' . $key;
        $params[':' . $key] = $state['filters'][$key];
    }
}
if ($state['filters']['open'] === 'open') {
    $where[] = "o.outstanding_amount > 0 AND o.status NOT IN ('cancelled', 'written_off')";
} elseif ($state['filters']['open'] === 'overdue') {
    $where[] = "o.outstanding_amount > 0 AND o.due_date < CURRENT_DATE AND o.status NOT IN ('cancelled', 'paid', 'written_off')";
}
if ($state['filters']['from'] !== '') {
    $where[] = 'o.due_date >= :from';
    $params[':from'] = $state['filters']['from'];
}
if ($state['filters']['to'] !== '') {
    $where[] = 'o.due_date <= :to';
    $params[':to'] = $state['filters']['to'];
}

$from = 'borrower_obligations o JOIN borrowers b ON b.id = o.borrower_id';
$result = run_list(
    $state,
    'o.id, o.obligation_number, o.description, o.obligation_date, o.due_date, o.total_amount_due, o.total_paid, o.outstanding_amount, o.overdue_amount, o.status,
     b.first_name, b.last_name, b.borrower_number',
    $from,
    $where,
    $params
);
$totals = db_row("SELECT COALESCE(SUM(IF(o.status <> 'cancelled', o.total_amount_due, 0)), 0) AS due, COALESCE(SUM(IF(o.status <> 'cancelled', o.total_paid, 0)), 0) AS paid,
                         COALESCE(SUM(IF(o.status NOT IN ('cancelled', 'written_off'), o.outstanding_amount, 0)), 0) AS outstanding FROM "
    . $from . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $params);

$pageTitle = 'Obligations';
$activeNav = 'obligations';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'What each borrower owes, how much was paid and what is left.', url('pages/obligations/create.php')) ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Obligation list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/obligations/create.php'), 'Create an obligation when a borrower uses a card.', 'file-text') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Obligation #</th>
                    <th scope="col">Borrower</th>
                    <th scope="col">Description</th>
                    <th scope="col">Due date</th>
                    <th scope="col" class="num">Total due</th>
                    <th scope="col" class="num">Paid</th>
                    <th scope="col" class="num">Outstanding</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <?php $late = $row['due_date'] && $row['due_date'] < date('Y-m-d') && (float) $row['outstanding_amount'] > 0 && !in_array($row['status'], ['cancelled', 'written_off', 'paid'], true); ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Obligation #"><a class="table-link" href="<?= e(url('pages/obligations/view.php', ['id' => $row['id']])) ?>"><?= e($row['obligation_number']) ?></a></td>
                        <td data-label="Borrower"><?= e(borrower_name($row)) ?></td>
                        <td data-label="Description"><?= show(excerpt($row['description'], 50)) ?></td>
                        <td data-label="Due date" class="<?= $late ? 'text-danger' : '' ?>"><?= e(format_date($row['due_date'])) ?><?= $late ? ' (past due)' : '' ?></td>
                        <td data-label="Total due" class="num"><?= e(format_money($row['total_amount_due'])) ?></td>
                        <td data-label="Paid" class="num"><?= e(format_money($row['total_paid'])) ?></td>
                        <td data-label="Outstanding" class="num"><?= e(format_money($row['outstanding_amount'])) ?></td>
                        <td data-label="Status"><?= badge($row['status']) ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/obligations/view.php', ['id' => $row['id']]), 'eye', 'View ' . $row['obligation_number']) ?>
                                <?php if (!in_array($row['status'], ['cancelled', 'written_off', 'paid'], true)): ?>
                                    <?= icon_link(url('pages/obligations/edit.php', ['id' => $row['id']]), 'edit', 'Edit ' . $row['obligation_number']) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="5">Total excluding cancelled</td><td class="num"><?= e(format_money($totals['due'])) ?></td><td class="num"><?= e(format_money($totals['paid'])) ?></td><td class="num"><?= e(format_money($totals['outstanding'])) ?></td><td colspan="2"></td></tr></tfoot>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
