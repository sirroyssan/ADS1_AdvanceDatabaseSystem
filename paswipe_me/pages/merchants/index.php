<?php
/**
 * Merchants - list, search, filter, paginate, print.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/merchant_functions.php';

$currentUser = require_admin();

$typeOptions = db_options("SELECT DISTINCT merchant_type, merchant_type FROM merchants WHERE merchant_type IS NOT NULL AND merchant_type <> '' ORDER BY merchant_type");

$state = list_state([
    'path'    => 'pages/merchants/index.php',
    'title'   => 'Merchants',
    'search'  => 'Search name, branch, platform, city or contact…',
    'filters' => [
        'category' => ['label' => 'Category', 'type' => 'select', 'options' => category_options(), 'all' => 'All categories'],
        'type'     => ['label' => 'Type', 'type' => 'select', 'options' => $typeOptions, 'all' => 'All types'],
    ],
    'sorts'   => [
        'name'   => ['Name (A–Z)', 'm.merchant_name ASC, m.branch ASC'],
        'newest' => ['Newest first', 'm.created_at DESC'],
        'city'   => ['City', 'm.municipality_city ASC, m.merchant_name ASC'],
    ],
]);

$where = [];
$params = [];
add_search_condition($state, ['m.merchant_name', 'm.branch', 'm.platform', 'm.municipality_city', 'm.province', 'm.contact'], $where, $params);
if ($state['filters']['category'] !== '') {
    $where[] = 'm.category_id = :category';
    $params[':category'] = $state['filters']['category'];
}
if ($state['filters']['type'] !== '') {
    $where[] = 'm.merchant_type = :type';
    $params[':type'] = $state['filters']['type'];
}

$result = run_list(
    $state,
    'm.id, m.merchant_name, m.branch, m.merchant_type, m.platform, m.municipality_city, m.province, m.contact, m.created_at,
     c.name AS category_name,
     (SELECT COUNT(*) FROM transactions t WHERE t.merchant_id = m.id AND ' . owner_scope('transactions', 't') . ') AS transaction_count,
     EXISTS (SELECT 1 FROM transactions ta WHERE ta.merchant_id = m.id) AS in_use',
    'merchants m LEFT JOIN categories c ON c.id = m.category_id',
    $where,
    $params
);

$pageTitle   = 'Merchants';
$activeNav   = 'merchants';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Stores and online shops where the credit cards are used.', url('pages/merchants/create.php')) ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Merchant list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/merchants/create.php'), 'Add the merchants where purchases are made.', 'store') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Merchant</th>
                    <th scope="col">Category</th>
                    <th scope="col">Type / Platform</th>
                    <th scope="col">Location</th>
                    <th scope="col">Contact</th>
                    <th scope="col" class="num">Transactions</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Merchant">
                            <a data-modal-form class="table-link" href="<?= e(url('pages/merchants/view.php', ['id' => $row['id']])) ?>"><?= e($row['merchant_name']) ?></a>
                            <?php if ($row['branch']): ?><br><span class="text-muted"><?= e($row['branch']) ?></span><?php endif; ?>
                        </td>
                        <td data-label="Category"><?= show($row['category_name']) ?></td>
                        <td data-label="Type / Platform"><?= show(trim(($row['merchant_type'] ?? '') . ($row['platform'] ? ' · ' . $row['platform'] : ''), ' ·')) ?></td>
                        <td data-label="Location"><?= show(trim(($row['municipality_city'] ?? '') . ($row['province'] ? ', ' . $row['province'] : ''), ', ')) ?></td>
                        <td data-label="Contact"><?= show($row['contact']) ?></td>
                        <td data-label="Transactions" class="num"><?= e($row['transaction_count']) ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/merchants/view.php', ['id' => $row['id']]), 'eye', 'View ' . $row['merchant_name']) ?>
                                <?= icon_link(url('pages/merchants/edit.php', ['id' => $row['id']]), 'edit', 'Edit ' . $row['merchant_name']) ?>
                                <?php if ((int) $row['in_use'] === 0): ?>
                                    <?= post_button(url('pages/merchants/delete.php'), ['id' => $row['id']], 'Delete ' . $row['merchant_name'], 'trash', 'icon-button icon-button--danger',
                                        'Are you sure you want to delete the merchant "' . $row['merchant_name'] . '"? This cannot be undone.', true) ?>
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
