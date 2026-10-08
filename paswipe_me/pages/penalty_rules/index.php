<?php
/**
 * Penalty Rules - list, search, filter, paginate, print.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/penalty_rule_functions.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/penalty_rules/index.php',
    'title'   => 'Penalty Rules',
    'search'  => 'Search name, basis or frequency…',
    'filters' => [
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'all' => 'All statuses'],
        'type'   => ['label' => 'Type', 'type' => 'select', 'options' => ['percentage' => 'Percentage', 'fixed' => 'Fixed'], 'all' => 'All types'],
    ],
    'sorts'   => [
        'name'   => ['Name (A–Z)', 'name ASC'],
        'newest' => ['Newest first', 'created_at DESC'],
    ],
]);

$where = [];
$params = [];
add_search_condition($state, ['name', 'basis', 'frequency'], $where, $params);
if ($state['filters']['status'] !== '') {
    $where[] = 'is_active = ' . ($state['filters']['status'] === 'active' ? '1' : '0');
}
if ($state['filters']['type'] !== '') {
    $where[] = 'calculation_type = :type';
    $params[':type'] = $state['filters']['type'];
}

$result = run_list(
    $state,
    'id, name, calculation_type, basis, rate, fixed_amount, maximum_charge, frequency, grace_period_days, is_active, effective_from, effective_until,
     (SELECT COUNT(*) FROM applied_penalties ap WHERE ap.penalty_rule_id = penalty_rules.id) AS usage_count',
    'penalty_rules',
    $where,
    $params
);

$pageTitle = 'Penalty Rules';
$activeNav = 'penalty_rules';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Late-payment charges that can be applied to borrower obligations.', url('pages/penalty_rules/create.php')) ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Penalty rule list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/penalty_rules/create.php'), 'Define how late-payment penalties are computed.', 'scale') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Rule</th>
                    <th scope="col">Computation</th>
                    <th scope="col">Frequency</th>
                    <th scope="col" class="num">Grace days</th>
                    <th scope="col">Effective</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="num">Applied</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Rule"><a class="table-link" href="<?= e(url('pages/penalty_rules/view.php', ['id' => $row['id']])) ?>"><?= e($row['name']) ?></a></td>
                        <td data-label="Computation"><?= e(penalty_rule_summary($row)) ?></td>
                        <td data-label="Frequency"><?= show($row['frequency']) ?></td>
                        <td data-label="Grace days" class="num"><?= e($row['grace_period_days']) ?></td>
                        <td data-label="Effective"><?= e($row['effective_from'] ? format_date($row['effective_from']) : 'Any') ?> – <?= e($row['effective_until'] ? format_date($row['effective_until']) : 'open') ?></td>
                        <td data-label="Status"><?= active_badge($row['is_active']) ?></td>
                        <td data-label="Applied" class="num"><?= e($row['usage_count']) ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/penalty_rules/view.php', ['id' => $row['id']]), 'eye', 'View ' . $row['name']) ?>
                                <?= icon_link(url('pages/penalty_rules/edit.php', ['id' => $row['id']]), 'edit', 'Edit ' . $row['name']) ?>
                                <?php if ((int) $row['usage_count'] === 0): ?>
                                    <?= post_button(url('pages/penalty_rules/delete.php'), ['id' => $row['id'], 'action' => 'delete'], 'Delete ' . $row['name'], 'trash',
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
