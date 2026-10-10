<?php
/**
 * Demand Letter Templates - list, search, filter, paginate, print.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/template_functions.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/letter_templates/index.php',
    'title'   => 'Demand Letter Templates',
    'search'  => 'Search name, version or text…',
    'filters' => [
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'all' => 'All statuses'],
    ],
    'sorts'   => [
        'name'   => ['Name (A–Z)', 't.name ASC, t.version DESC'],
        'newest' => ['Recently updated', 't.updated_at DESC'],
    ],
]);

$where = [];
$params = [];
add_search_condition($state, ['t.name', 't.version', 't.template_body'], $where, $params);
if ($state['filters']['status'] !== '') {
    $where[] = 't.is_active = ' . ($state['filters']['status'] === 'active' ? '1' : '0');
}

$result = run_list(
    $state,
    't.id, t.name, t.version, t.is_active, t.updated_at,
     (SELECT COUNT(*) FROM demand_letters d WHERE d.template_id = t.id) AS usage_count',
    'demand_letter_templates t',
    $where,
    $params
);

$pageTitle = 'Demand Letter Templates';
$activeNav = 'letter_templates';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Reusable demand letter wording with placeholders for borrower and balances.', url('pages/letter_templates/create.php')) ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Template list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/letter_templates/create.php'), 'Create the first demand letter template.', 'mail-alert') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Template</th>
                    <th scope="col">Version</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="num">Used</th>
                    <th scope="col">Updated</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Template"><a data-modal-form class="table-link" href="<?= e(url('pages/letter_templates/view.php', ['id' => $row['id']])) ?>"><?= e($row['name']) ?></a></td>
                        <td data-label="Version"><?= e($row['version']) ?></td>
                        <td data-label="Status"><?= active_badge($row['is_active']) ?></td>
                        <td data-label="Used" class="num"><?= e($row['usage_count']) ?></td>
                        <td data-label="Updated"><?= e(format_date($row['updated_at'])) ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/letter_templates/view.php', ['id' => $row['id']]), 'eye', 'View ' . $row['name']) ?>
                                <?= icon_link(url('pages/letter_templates/edit.php', ['id' => $row['id']]), 'edit', 'Edit ' . $row['name']) ?>
                                <?php if ((int) $row['usage_count'] === 0): ?>
                                    <?= post_button(url('pages/letter_templates/delete.php'), ['id' => $row['id'], 'action' => 'delete'], 'Delete ' . $row['name'], 'trash',
                                        'icon-button icon-button--danger', 'Are you sure you want to delete the template "' . $row['name'] . '"? This cannot be undone.', true) ?>
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
