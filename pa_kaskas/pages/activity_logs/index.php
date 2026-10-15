<?php
/**
 * Activity Logs - read-only audit trail (table: activity_logs).
 * No create/edit/delete: the schema notes say activity logs are append-only;
 * entries are written automatically by the application (log_activity()).
 */

require_once __DIR__ . '/../../includes/auth_check.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/activity_logs/index.php',
    'title'   => 'Activity Logs',
    'search'  => 'Search description, record id or IP address…',
    'filters' => [
        'action' => ['label' => 'Action', 'type' => 'select', 'options' => db_options('SELECT DISTINCT action, action FROM activity_logs a WHERE ' . activity_scope('a') . ' ORDER BY action'), 'all' => 'All actions'],
        'entity' => ['label' => 'Record type', 'type' => 'select', 'options' => db_options('SELECT DISTINCT entity_type, entity_type FROM activity_logs a WHERE ' . activity_scope('a') . ' ORDER BY entity_type'), 'all' => 'All record types'],
        'actor'  => ['label' => 'User', 'type' => 'select', 'options' => user_is_system_admin($currentUser) ? all_user_options() : db_options("SELECT id, CONCAT(COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''), username, email), ' <', email, '>') AS label FROM users WHERE id = " . owner_sql() . ' OR id IN ' . owned_borrower_users_sql() . ' ORDER BY label'), 'all' => 'All users'],
        'from'   => ['label' => 'From', 'type' => 'date'],
        'to'     => ['label' => 'To', 'type' => 'date'],
    ],
    'sorts'   => [
        'newest' => ['Newest first', 'a.created_at DESC'],
        'oldest' => ['Oldest first', 'a.created_at ASC'],
    ],
]);

$where = [activity_scope('a')];
$params = [];
add_search_condition($state, ['a.description', 'a.entity_id', 'a.ip_address'], $where, $params);
foreach (['action' => 'a.action', 'entity' => 'a.entity_type', 'actor' => 'a.actor_user_id'] as $key => $column) {
    if ($state['filters'][$key] !== '') {
        $where[] = $column . ' = :' . $key;
        $params[':' . $key] = $state['filters'][$key];
    }
}
if ($state['filters']['from'] !== '') {
    $where[] = 'a.created_at >= :from';
    $params[':from'] = $state['filters']['from'] . ' 00:00:00';
}
if ($state['filters']['to'] !== '') {
    $where[] = 'a.created_at <= :to';
    $params[':to'] = $state['filters']['to'] . ' 23:59:59';
}

$result = run_list(
    $state,
    "a.id, a.action, a.entity_type, a.entity_id, a.description, a.ip_address, a.created_at,
     COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, u.email) AS actor",
    'activity_logs a LEFT JOIN users u ON u.id = a.actor_user_id',
    $where,
    $params
);

$pageTitle = 'Activity Logs';
$activeNav = 'activity_logs';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'What you and your borrowers did, and when. This audit trail is read-only.', null) ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Activity list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, null, 'Activity is recorded automatically as people use the system.', 'history') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">When</th>
                    <th scope="col">User</th>
                    <th scope="col">Action</th>
                    <th scope="col">Record</th>
                    <th scope="col">Description</th>
                    <th scope="col">IP</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="When"><?= e(format_datetime($row['created_at'])) ?></td>
                        <td data-label="User"><?= $row['actor'] ? e($row['actor']) : '<span class="text-muted">System / anonymous</span>' ?></td>
                        <td data-label="Action"><?= e(humanize($row['action'])) ?></td>
                        <td data-label="Record"><?= e($row['entity_type']) ?></td>
                        <td data-label="Description"><?= show(excerpt($row['description'], 80)) ?></td>
                        <td data-label="IP"><?= show($row['ip_address']) ?></td>
                        <td class="actions no-print"><div class="row-actions"><?= icon_link(url('pages/activity_logs/view.php', ['id' => $row['id']]), 'eye', 'View log entry') ?></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
