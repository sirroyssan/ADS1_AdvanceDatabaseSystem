<?php
/**
 * Installment Rules - list, search, filter, paginate.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/rule_functions.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/installment_rules/index.php',
    'title'   => 'Installment Rules',
    'search'  => 'Search name or bank…',
    'filters' => [
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'all' => 'All statuses'],
    ],
    'sorts'   => [
        'name'   => ['Name (A–Z)', 'name ASC'],
        'newest' => ['Newest first', 'created_at DESC'],
    ],
]);

$where = [owner_scope('installment_rules', 'installment_rules')];
$params = [];
add_search_condition($state, ['name', 'issuer'], $where, $params);
if ($state['filters']['status'] !== '') {
    $where[] = 'is_active = ' . ($state['filters']['status'] === 'active' ? '1' : '0');
}

$result = run_list(
    $state,
    'id, name, credit_card_id, issuer, terms, min_amount, max_amount, interest_method, interest_rate, fee_type, fee_value, fee_billing, effective_from, effective_until, is_active,
     (SELECT c.card_nickname FROM credit_cards c WHERE c.id = installment_rules.credit_card_id) AS card_nickname,
     (SELECT COUNT(*) FROM installment_plans p WHERE p.installment_rule_id = installment_rules.id) AS usage_count',
    'installment_rules',
    $where,
    $params
);

$pageTitle = 'Installment Rules';
$activeNav = 'installment_rules';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Your bank\'s installment terms. Choose one when recording an installment purchase; the plan keeps a copy of the terms.', url('pages/installment_rules/create.php'), 'Add New') ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Installment rule list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/installment_rules/create.php'), 'Save the installment terms your bank offers (months, interest, processing fee).', 'layers') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Rule</th>
                    <th scope="col">Applies to</th>
                    <th scope="col">Terms</th>
                    <th scope="col">Effective</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="num">Plans</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Rule"><a data-modal-form class="table-link" href="<?= e(url('pages/installment_rules/view.php', ['id' => $row['id']])) ?>"><?= e($row['name']) ?></a></td>
                        <td data-label="Applies to"><?= e($row['card_nickname'] ?? ($row['issuer'] ?: 'Any bank')) ?></td>
                        <td data-label="Terms"><?= e(installment_rule_summary($row)) ?></td>
                        <td data-label="Effective"><?= e($row['effective_from'] ? format_date($row['effective_from']) : 'Any') ?> – <?= e($row['effective_until'] ? format_date($row['effective_until']) : 'open') ?></td>
                        <td data-label="Status"><?= active_badge($row['is_active']) ?></td>
                        <td data-label="Plans" class="num"><?= e($row['usage_count']) ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/installment_rules/view.php', ['id' => $row['id']]), 'eye', 'View ' . $row['name']) ?>
                                <?= icon_link(url('pages/installment_rules/edit.php', ['id' => $row['id']]), 'edit', 'Edit ' . $row['name']) ?>
                                <?php if ((int) $row['usage_count'] === 0): ?>
                                    <?= post_button(url('pages/installment_rules/delete.php'), ['id' => $row['id'], 'action' => 'delete'], 'Delete ' . $row['name'], 'trash',
                                        'icon-button icon-button--danger', 'Are you sure you want to delete the rule "' . $row['name'] . '"? This cannot be undone.', true) ?>
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
