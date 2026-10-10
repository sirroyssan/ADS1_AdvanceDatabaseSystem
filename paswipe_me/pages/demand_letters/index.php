<?php
/**
 * Demand Letters - list, search, filter, print.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/letter_functions.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/demand_letters/index.php',
    'title'   => 'Demand Letters',
    'search'  => 'Search letter number, borrower or content…',
    'filters' => [
        'status'   => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_LETTER_STATUS), 'all' => 'All statuses'],
        'borrower' => ['label' => 'Borrower', 'type' => 'select', 'options' => borrower_options(null, false), 'all' => 'All borrowers'],
        'from'     => ['label' => 'From', 'type' => 'date'],
        'to'       => ['label' => 'To', 'type' => 'date'],
    ],
    'sorts'   => [
        'newest'  => ['Newest first', 'l.generated_date DESC, l.created_at DESC'],
        'oldest'  => ['Oldest first', 'l.generated_date ASC'],
        'balance' => ['Highest balance', 'l.remaining_balance DESC'],
    ],
]);

$where = [];
$params = [];
add_search_condition($state, ['l.letter_number', 'l.content', 'b.first_name', 'b.last_name', 'b.borrower_number'], $where, $params);
foreach (['status' => 'l.status', 'borrower' => 'l.borrower_id'] as $key => $column) {
    if ($state['filters'][$key] !== '') {
        $where[] = $column . ' = :' . $key;
        $params[':' . $key] = $state['filters'][$key];
    }
}
if ($state['filters']['from'] !== '') {
    $where[] = 'l.generated_date >= :from';
    $params[':from'] = $state['filters']['from'];
}
if ($state['filters']['to'] !== '') {
    $where[] = 'l.generated_date <= :to';
    $params[':to'] = $state['filters']['to'];
}

$result = run_list(
    $state,
    'l.id, l.letter_number, l.generated_date, l.remaining_balance, l.status, l.sent_at, b.first_name, b.last_name, o.obligation_number',
    'demand_letters l JOIN borrowers b ON b.id = l.borrower_id LEFT JOIN borrower_obligations o ON o.id = l.obligation_id',
    $where,
    $params
);

$pageTitle = 'Demand Letters';
$activeNav = 'demand_letters';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Collection letters for overdue borrower balances.', url('pages/demand_letters/create.php')) ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Demand letter list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/demand_letters/create.php'), 'Prepare a demand letter for an overdue obligation.', 'mail-alert') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Letter #</th>
                    <th scope="col">Date</th>
                    <th scope="col">Borrower</th>
                    <th scope="col">Obligation</th>
                    <th scope="col" class="num">Remaining balance</th>
                    <th scope="col">Sent</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Letter #"><a data-modal-form class="table-link" href="<?= e(url('pages/demand_letters/view.php', ['id' => $row['id']])) ?>"><?= e($row['letter_number']) ?></a></td>
                        <td data-label="Date"><?= e(format_date($row['generated_date'])) ?></td>
                        <td data-label="Borrower"><?= e(borrower_name($row)) ?></td>
                        <td data-label="Obligation"><?= show($row['obligation_number']) ?></td>
                        <td data-label="Remaining balance" class="num"><?= show_money($row['remaining_balance']) ?></td>
                        <td data-label="Sent"><?= show($row['sent_at'] ? format_date($row['sent_at']) : null) ?></td>
                        <td data-label="Status"><?= badge($row['status']) ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/demand_letters/view.php', ['id' => $row['id']]), 'eye', 'View ' . $row['letter_number']) ?>
                                <?php if (in_array($row['status'], LETTER_EDITABLE_STATUSES, true)): ?>
                                    <?= icon_link(url('pages/demand_letters/edit.php', ['id' => $row['id']]), 'edit', 'Edit ' . $row['letter_number']) ?>
                                <?php endif; ?>
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
