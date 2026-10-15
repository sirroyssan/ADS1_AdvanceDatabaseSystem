<?php
/**
 * Credit Cards - list with utilization, search, filter, print.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/credit_card_functions.php';

$currentUser = require_staff_area();
$canManage = user_is_admin($currentUser);   // staff: read only

$state = list_state([
    'path'    => 'pages/credit_cards/index.php',
    'title'   => 'Credit Cards',
    'search'  => 'Search nickname, issuer or last four…',
    'filters' => [
        'status'  => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_CARD_STATUS), 'all' => 'All statuses'],
        'network' => ['label' => 'Network', 'type' => 'select', 'options' => enum_options(ENUM_CARD_NETWORK), 'all' => 'All networks'],
    ],
    'sorts'   => [
        'name'        => ['Nickname (A–Z)', 'cc.card_nickname ASC'],
        'utilization' => ['Highest utilization', 'utilization DESC'],
        'limit'       => ['Highest limit', 'cc.credit_limit DESC'],
    ],
]);

$where = [owner_scope('credit_cards', 'cc')];
$params = [];
add_search_condition($state, ['cc.card_nickname', 'cc.issuer', 'cc.last_four'], $where, $params);
foreach (['status' => 'cc.status', 'network' => 'cc.network'] as $key => $column) {
    if ($state['filters'][$key] !== '') {
        $where[] = $column . ' = :' . $key;
        $params[':' . $key] = $state['filters'][$key];
    }
}

$result = run_list(
    $state,
    "cc.id, cc.card_nickname, cc.issuer, cc.network, cc.last_four, cc.credit_limit, cc.status, cc.payment_due_day,
     " . card_usage_columns() . ",
     COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email) AS owner_name",
    'credit_cards cc LEFT JOIN users u ON u.id = cc.owner_user_id ' . card_usage_join(),
    $where,
    $params
);

// Cards with nothing recorded on them yet can be deleted from the list.
$deletable = [];
if ($canManage) {
    foreach ($result['rows'] as $row) {
        $deletable[$row['id']] = credit_card_usage($row['id'])['total'] === 0;
    }
}

$pageTitle = 'Credit Cards';
$activeNav = 'credit_cards';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'The owner\'s credit cards, limits and available credit.', ($canManage ? url('pages/credit_cards/create.php') : null)) ?>
<?= render_list_toolbar($state) ?>

<?php $sharedLines = credit_lines_mine(); ?>
<?php if ($sharedLines): ?>
    <section class="panel" aria-label="Shared credit limits">
        <header class="panel__header"><h3><?= icon('layers') ?> Shared credit limits</h3></header>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Shared line</th><th scope="col">Cards</th><th scope="col" class="num">Shared limit</th><th scope="col" class="num">Used (all cards)</th>
                    <th scope="col" class="num">Available</th><th scope="col" class="num">Utilization</th></tr></thead>
                <tbody>
                <?php foreach ($sharedLines as $ln): ?>
                    <tr>
                        <td data-label="Shared line"><strong><?= e($ln['name']) ?></strong></td>
                        <td data-label="Cards"><?= e(implode(', ', array_map(static fn ($c) => $c['card_nickname'] . ' •••• ' . $c['last_four'] . ($c['sub_limit'] !== null ? ' (sub-limit ' . format_money($c['sub_limit']) . ')' : ''), $ln['cards']))) ?></td>
                        <td data-label="Shared limit" class="num"><?= e(format_money($ln['credit_limit'])) ?></td>
                        <td data-label="Used (all cards)" class="num"><?= e(format_money($ln['used'])) ?></td>
                        <td data-label="Available" class="num<?= $ln['available'] < 0 ? ' text-danger' : '' ?>"><strong><?= e(format_money($ln['available'])) ?></strong></td>
                        <td data-label="Utilization" class="num"><?= e(number_format($ln['utilization'], 2)) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<section class="panel panel--flush" aria-label="Credit card list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, $canManage ? url('pages/credit_cards/create.php') : null, 'Add the owner\'s credit cards (last four digits only).', 'credit-card') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Card</th>
                    <th scope="col">Issuer / Network</th>
                    <th scope="col">Owner</th>
                    <th scope="col" class="num">Limit</th>
                    <th scope="col" class="num">Used</th>
                    <th scope="col" class="num">Available</th>
                    <th scope="col" class="num">Utilization</th>
                    <th scope="col">Due day</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Card"><a data-modal-form class="table-link" href="<?= e(url('pages/credit_cards/view.php', ['id' => $row['id']])) ?>"><?= e($row['card_nickname']) ?></a><br><span class="text-muted">•••• <?= e($row['last_four']) ?></span></td>
                        <td data-label="Issuer / Network"><?= e($row['issuer']) ?><?= $row['network'] ? ' · ' . e(strtoupper($row['network'])) : '' ?></td>
                        <td data-label="Owner"><?= show($row['owner_name']) ?></td>
                        <td data-label="Limit" class="num"><?= e(format_money($row['credit_limit'])) ?><?= $row['shared_line'] ? '<br><span class="chip">shared · ' . e($row['shared_line']) . '</span>' : '' ?></td>
                        <td data-label="Used" class="num"><?= e(format_money($row['used_credit'])) ?></td>
                        <td data-label="Available" class="num<?= (float) $row['remaining_credit'] < 0 ? ' text-danger' : '' ?>"><?= e(format_money($row['remaining_credit'])) ?></td>
                        <td data-label="Utilization" class="num"><?= e(number_format((float) $row['utilization'], 2)) ?>%</td>
                        <td data-label="Due day"><?= show($row['payment_due_day']) ?></td>
                        <td data-label="Status"><?= badge($row['status']) ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/credit_cards/view.php', ['id' => $row['id']]), 'eye', 'View ' . $row['card_nickname']) ?>
                                <?= $canManage ? icon_link(url('pages/credit_cards/edit.php', ['id' => $row['id']]), 'edit', 'Edit ' . $row['card_nickname']) : '' ?>
                                <?= !empty($deletable[$row['id']]) ? post_button(url('pages/credit_cards/delete.php'), ['id' => $row['id'], 'action' => 'delete'], 'Delete ' . $row['card_nickname'], 'trash',
                                    'icon-button icon-button--danger', 'Delete "' . $row['card_nickname'] . '" •••• ' . $row['last_four'] . '? It has no transactions or other records yet. This cannot be undone.', true, 'Delete card?') : '' ?>
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
