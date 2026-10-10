<?php
/**
 * Users & Roles - list, search, filter by status/role, paginate, print.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/user_functions.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/users/index.php',
    'title'   => 'Users & Roles',
    'search'  => 'Search name, email, username or mobile…',
    'filters' => [
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_USER_STATUS), 'all' => 'All statuses'],
        'role'   => ['label' => 'Role', 'type' => 'select', 'options' => array_combine(ENUM_USER_ROLE, array_map('role_label', ENUM_USER_ROLE)), 'all' => 'All roles'],
    ],
    'sorts'   => [
        'name'       => ['Name (A–Z)', 'u.last_name ASC, u.first_name ASC, u.email ASC'],
        'newest'     => ['Newest first', 'u.created_at DESC'],
        'last_login' => ['Last sign-in', 'u.last_login_at DESC'],
    ],
]);

$where = [];
$params = [];
add_search_condition($state, ['u.first_name', 'u.last_name', 'u.email', 'u.username', 'u.mobile'], $where, $params);
if ($state['filters']['status'] !== '') {
    $where[] = 'u.status = :status';
    $params[':status'] = $state['filters']['status'];
}
if ($state['filters']['role'] !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role = :role)';
    $params[':role'] = $state['filters']['role'];
}

$result = run_list(
    $state,
    "u.id, u.username, u.email, u.first_name, u.last_name, u.mobile, u.status, u.last_login_at, u.created_at,
     (SELECT GROUP_CONCAT(r.role ORDER BY FIELD(r.role, 'owner', 'admin', 'borrower') SEPARATOR ',') FROM user_roles r WHERE r.user_id = u.id) AS roles",
    'users u',
    $where,
    $params
);

$pageTitle = 'Users & Roles';
$activeNav = 'users';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Accounts that can sign in, and the roles they hold.', url('pages/users/create.php'), 'Add User') ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="User list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/users/create.php'), 'Create user accounts for administrators and borrowers.', 'users') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Name</th>
                    <th scope="col">Email / Username</th>
                    <th scope="col">Roles</th>
                    <th scope="col">Status</th>
                    <th scope="col">Last sign-in</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <?php $name = user_display_name($row); ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Name">
                            <a data-modal-form class="table-link" href="<?= e(url('pages/users/view.php', ['id' => $row['id']])) ?>"><?= e($name) ?></a>
                            <?php if ($row['id'] === $currentUser['id']): ?><span class="chip">You</span><?php endif; ?>
                        </td>
                        <td data-label="Email / Username"><?= e($row['email']) ?><?php if ($row['username']): ?><br><span class="text-muted">@<?= e($row['username']) ?></span><?php endif; ?></td>
                        <td data-label="Roles">
                            <?php foreach (array_filter(explode(',', (string) $row['roles'])) as $role): ?>
                                <span class="chip"><?= e(role_label($role)) ?></span>
                            <?php endforeach; ?>
                            <?php if (!$row['roles']): ?><span class="text-muted">No role</span><?php endif; ?>
                        </td>
                        <td data-label="Status"><?= badge($row['status']) ?></td>
                        <td data-label="Last sign-in"><?= e($row['last_login_at'] ? format_datetime($row['last_login_at']) : 'Never') ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/users/view.php', ['id' => $row['id']]), 'eye', 'View ' . $name) ?>
                                <?= icon_link(url('pages/users/edit.php', ['id' => $row['id']]), 'edit', 'Edit ' . $name) ?>
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
