<?php
/**
 * Borrowers - list with balances (from vw_borrower_balances), search, filter, print.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/borrower_functions.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/borrowers/index.php',
    'title'   => 'Borrowers',
    'search'  => 'Search number, name, nickname, mobile or email…',
    'filters' => [
        'status'    => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_BORROWER_STATUS), 'all' => 'All statuses'],
        'duplicate' => ['label' => 'Duplicates', 'type' => 'select', 'options' => ['yes' => 'Possible duplicates only', 'no' => 'No duplicates'], 'all' => 'All borrowers'],
    ],
    'sorts'   => [
        'name'        => ['Name (A–Z)', 'b.last_name ASC, b.first_name ASC'],
        'outstanding' => ['Highest outstanding', 'outstanding DESC'],
        'newest'      => ['Newest first', 'b.created_at DESC'],
        'number'      => ['Borrower number', 'b.borrower_number ASC'],
    ],
]);

$where = [owner_scope('borrowers', 'b')];
$params = [];
add_search_condition($state, ['b.borrower_number', 'b.first_name', 'b.last_name', 'b.nickname', 'b.primary_mobile', 'b.primary_email'], $where, $params);
if ($state['filters']['status'] !== '') {
    $where[] = 'b.status = :status';
    $params[':status'] = $state['filters']['status'];
}
// Same name, same mobile or same email as another of your borrowers.
if ($state['filters']['duplicate'] === 'yes') {
    $where[] = borrower_duplicate_sql('b');
} elseif ($state['filters']['duplicate'] === 'no') {
    $where[] = 'NOT ' . borrower_duplicate_sql('b');
}

$result = run_list(
    $state,
    'b.id, b.borrower_number, b.first_name, b.middle_name, b.last_name, b.suffix, b.nickname, b.primary_mobile, b.primary_email, b.status,
     COALESCE(v.outstanding, 0) AS outstanding, COALESCE(v.overdue, 0) AS overdue,
     ' . borrower_duplicate_sql('b') . ' AS is_duplicate',
    'borrowers b LEFT JOIN vw_borrower_balances v ON v.borrower_id = b.id',
    $where,
    $params
);
// Add what is owed on the card transactions assigned to each listed borrower.
foreach ($result['rows'] as &$row) {
    $owed = borrowed_totals(borrowed_owner_purchases($row['id']));
    $row['outstanding'] = (float) $row['outstanding'] + $owed['outstanding'];
    $row['overdue'] = (float) $row['overdue'] + $owed['overdue_amount'];
}
unset($row);

$pageTitle = 'Borrowers';
$activeNav = 'borrowers';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'People who use the owner\'s credit cards and pay back through obligations.', url('pages/borrowers/create.php')) ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Borrower list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/borrowers/create.php'), 'Add the people who borrow the credit cards.', 'users') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Borrower #</th>
                    <th scope="col">Name</th>
                    <th scope="col">Mobile / Email</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="num">Outstanding</th>
                    <th scope="col" class="num">Overdue</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <?php $name = borrower_full_name($row); ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Borrower #"><?= e($row['borrower_number']) ?></td>
                        <td data-label="Name">
                            <a data-modal-form class="table-link" href="<?= e(url('pages/borrowers/view.php', ['id' => $row['id']])) ?>"><?= e($name) ?></a>
                            <?php if ($row['nickname']): ?><br><span class="text-muted">“<?= e($row['nickname']) ?>”</span><?php endif; ?>
                            <?php if ((int) $row['is_duplicate'] === 1): ?><br><a data-modal-form class="status status--warning" href="<?= e(url('pages/borrowers/remove.php', ['id' => $row['id']])) ?>" title="Another borrower has the same name, mobile or email">Possible duplicate</a><?php endif; ?>
                        </td>
                        <td data-label="Mobile / Email"><?= show($row['primary_mobile']) ?><?php if ($row['primary_email']): ?><br><span class="text-muted"><?= e($row['primary_email']) ?></span><?php endif; ?></td>
                        <td data-label="Status"><?= badge($row['status']) ?></td>
                        <td data-label="Outstanding" class="num"><?= e(format_money($row['outstanding'])) ?></td>
                        <td data-label="Overdue" class="num<?= (float) $row['overdue'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($row['overdue'])) ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/borrowers/view.php', ['id' => $row['id']]), 'eye', 'View ' . $name) ?>
                                <?= icon_link(url('pages/borrowers/edit.php', ['id' => $row['id']]), 'edit', 'Edit ' . $name) ?>
                                <a data-modal-form class="icon-button icon-button--danger" href="<?= e(url('pages/borrowers/remove.php', ['id' => $row['id']])) ?>" title="<?= e('Delete or merge ' . $name) ?>" aria-label="<?= e('Delete or merge ' . $name) ?>"><?= icon('trash') ?></a>
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
