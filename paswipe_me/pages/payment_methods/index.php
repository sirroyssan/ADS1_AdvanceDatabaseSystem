<?php
/**
 * Payment Methods - list, search, filter, paginate, print.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/payment_method_functions.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/payment_methods/index.php',
    'title'   => 'Payment Methods',
    'search'  => 'Search name, account, last four or borrower…',
    'filters' => [
        'type'   => ['label' => 'Type', 'type' => 'select', 'options' => enum_options(ENUM_PAYMENT_METHOD), 'all' => 'All types'],
        'owner'  => ['label' => 'Belongs to', 'type' => 'select', 'options' => ['owner' => 'Owner accounts', 'borrower' => 'Borrower accounts'], 'all' => 'Owner & borrowers'],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'all' => 'All statuses'],
    ],
    'sorts'   => [
        'name'   => ['Name (A–Z)', 'pm.display_name ASC'],
        'type'   => ['Type', 'pm.method_type ASC, pm.display_name ASC'],
        'newest' => ['Newest first', 'pm.created_at DESC'],
    ],
]);

$where = [];
$params = [];
add_search_condition($state, ['pm.display_name', 'pm.account_name', 'pm.masked_account_number', 'pm.last_four', 'b.first_name', 'b.last_name'], $where, $params);
if ($state['filters']['type'] !== '') {
    $where[] = 'pm.method_type = :type';
    $params[':type'] = $state['filters']['type'];
}
if ($state['filters']['owner'] === 'owner') {
    $where[] = 'pm.borrower_id IS NULL';
} elseif ($state['filters']['owner'] === 'borrower') {
    $where[] = 'pm.borrower_id IS NOT NULL';
}
if ($state['filters']['status'] !== '') {
    $where[] = 'pm.is_active = ' . ($state['filters']['status'] === 'active' ? '1' : '0');
}

$result = run_list(
    $state,
    "pm.id, pm.method_type, pm.display_name, pm.account_name, pm.masked_account_number, pm.last_four, pm.is_active,
     pm.borrower_id, b.first_name, b.last_name,
     COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, u.email) AS owner_name,
     (SELECT COUNT(*) FROM borrower_payments bp WHERE bp.payment_method_id = pm.id)
       + (SELECT COUNT(*) FROM credit_card_payments cp WHERE cp.payment_method_id = pm.id) AS usage_count",
    'payment_methods pm LEFT JOIN borrowers b ON b.id = pm.borrower_id LEFT JOIN users u ON u.id = pm.owner_user_id',
    $where,
    $params
);

$pageTitle = 'Payment Methods';
$activeNav = 'payment_methods';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'GCash, Maya, bank and cash accounts used to send or receive payments.', url('pages/payment_methods/create.php')) ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Payment method list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/payment_methods/create.php'), 'Add the accounts used for payments.', 'wallet') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Display name</th>
                    <th scope="col">Type</th>
                    <th scope="col">Account</th>
                    <th scope="col">Belongs to</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="num">Used</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Display name"><a data-modal-form class="table-link" href="<?= e(url('pages/payment_methods/view.php', ['id' => $row['id']])) ?>"><?= e($row['display_name']) ?></a></td>
                        <td data-label="Type"><?= e(strtoupper(humanize($row['method_type']))) ?></td>
                        <td data-label="Account"><?= show(trim(($row['account_name'] ?? '') . ' ' . ($row['masked_account_number'] ?? ($row['last_four'] ? '•••• ' . $row['last_four'] : '')))) ?></td>
                        <td data-label="Belongs to"><?= $row['borrower_id'] ? e(borrower_name($row)) : (show($row['owner_name']) . ' <span class="chip chip--muted">owner</span>') ?></td>
                        <td data-label="Status"><?= active_badge($row['is_active']) ?></td>
                        <td data-label="Used" class="num"><?= e($row['usage_count']) ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/payment_methods/view.php', ['id' => $row['id']]), 'eye', 'View ' . $row['display_name']) ?>
                                <?= icon_link(url('pages/payment_methods/edit.php', ['id' => $row['id']]), 'edit', 'Edit ' . $row['display_name']) ?>
                                <?php if ((int) $row['usage_count'] === 0): ?>
                                    <?= post_button(url('pages/payment_methods/delete.php'), ['id' => $row['id'], 'action' => 'delete'], 'Delete ' . $row['display_name'], 'trash',
                                        'icon-button icon-button--danger', 'Are you sure you want to delete "' . $row['display_name'] . '"? This cannot be undone.', true) ?>
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
