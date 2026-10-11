<?php
/**
 * Notifications - list (mine and my borrowers', or only mine), search, filter, print.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/notification_functions.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/notifications/index.php',
    'title'   => 'Notifications',
    'search'  => 'Search title, message or type…',
    'filters' => [
        'mine'    => ['label' => 'Recipient', 'type' => 'select', 'options' => ['mine' => 'Only mine', 'unread' => 'My unread'] + notification_recipient_options(), 'all' => 'All recipients'],
        'status'  => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_NOTIFY_STATUS), 'all' => 'All statuses'],
        'channel' => ['label' => 'Channel', 'type' => 'select', 'options' => enum_options(ENUM_NOTIFY_CHANNEL), 'all' => 'All channels'],
    ],
    'sorts'   => [
        'newest' => ['Newest first', 'n.created_at DESC'],
        'oldest' => ['Oldest first', 'n.created_at ASC'],
    ],
]);

$where = [notification_scope('n')];
$params = [];
add_search_condition($state, ['n.title', 'n.message', 'n.notification_type'], $where, $params);
$mine = $state['filters']['mine'];
if ($mine === 'mine' || $mine === 'unread') {
    $where[] = 'n.user_id = :me';
    $params[':me'] = $currentUser['id'];
    if ($mine === 'unread') {
        $where[] = "n.read_at IS NULL AND n.channel = 'in_app' AND n.status NOT IN ('cancelled', 'failed')";
    }
} elseif ($mine !== '') {
    $where[] = 'n.user_id = :user';
    $params[':user'] = $mine;
}
foreach (['status' => 'n.status', 'channel' => 'n.channel'] as $key => $column) {
    if ($state['filters'][$key] !== '') {
        $where[] = $column . ' = :' . $key;
        $params[':' . $key] = $state['filters'][$key];
    }
}

$result = run_list(
    $state,
    "n.id, n.title, n.notification_type, n.channel, n.status, n.created_at, n.read_at, n.user_id,
     COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, u.email) AS recipient_name",
    'notifications n JOIN users u ON u.id = n.user_id',
    $where,
    $params
);

$pageTitle = 'Notifications';
$activeNav = 'notifications';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Messages sent to users. In-app messages appear under the bell icon.', url('pages/notifications/create.php'), 'Send Notification',
    '<a href="' . e(url('pages/notifications/index.php', ['mine' => 'unread'])) . '" class="btn btn--outline">' . icon('bell') . ' <span>My unread</span></a>') ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Notification list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/notifications/create.php'), 'No notifications have been sent.', 'bell') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Title</th>
                    <th scope="col">Recipient</th>
                    <th scope="col">Type</th>
                    <th scope="col">Channel</th>
                    <th scope="col">Created</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <?php $unread = $row['user_id'] === $currentUser['id'] && $row['read_at'] === null && $row['channel'] === 'in_app' && !in_array($row['status'], ['cancelled', 'failed'], true); ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Title"><a data-modal-form class="table-link" href="<?= e(url('pages/notifications/view.php', ['id' => $row['id']])) ?>"><?= e($row['title']) ?></a><?= $unread ? ' <span class="chip">New</span>' : '' ?></td>
                        <td data-label="Recipient"><?= e($row['recipient_name']) ?></td>
                        <td data-label="Type"><?= e(humanize($row['notification_type'])) ?></td>
                        <td data-label="Channel"><?= e(humanize($row['channel'])) ?></td>
                        <td data-label="Created"><?= e(format_datetime($row['created_at'])) ?></td>
                        <td data-label="Status"><?= badge($row['status']) ?></td>
                        <td class="actions no-print"><div class="row-actions"><?= icon_link(url('pages/notifications/view.php', ['id' => $row['id']]), 'eye', 'View notification') ?></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
