<?php
/**
 * My notifications (owner, admin and staff). New ones stand out; read ones
 * fade. Opening one shows it in a modal (and marks it read), with the record
 * it is about and - for the owner - the decision right there: approve or
 * reject a payment, confirm a card repayment, decide an authorization request.
 * POST action=read_all marks all of them read.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/includes/notifications.php';

$currentUser = require_staff_area();

if (is_post()) {
    require_csrf('pages/notifications/inbox.php');
    $count = notification_mark_all_read($currentUser['id']);
    flash('success', $count . ' notification(s) marked as read.');
    redirect('pages/notifications/inbox.php');
}

$show = input_string($_GET, 'show') === 'unread' ? 'unread' : 'all';
$kind = input_string($_GET, 'kind');
$kind = isset(NOTIFICATION_KINDS[$kind]) ? $kind : '';

$where = [NOTIFICATION_INBOX_SQL];
$params = [':me' => $currentUser['id']];
if ($show === 'unread') {
    $where[] = 'n.read_at IS NULL';
}
if ($kind !== '') {
    $where[] = 'n.entity_type = :kind';
    $params[':kind'] = $kind;
}
$notifications = db_all('SELECT n.* FROM notifications n WHERE ' . implode(' AND ', $where) . ' ORDER BY n.created_at DESC LIMIT 200', $params);
$unread = (int) db_value('SELECT COUNT(*) FROM notifications n WHERE ' . NOTIFICATION_INBOX_SQL . ' AND n.read_at IS NULL', [':me' => $currentUser['id']]);
$kinds = db_all('SELECT n.entity_type, COUNT(*) AS c FROM notifications n WHERE ' . NOTIFICATION_INBOX_SQL . ' AND n.entity_type IS NOT NULL GROUP BY n.entity_type', [':me' => $currentUser['id']]);

$pageTitle = 'My Notifications';
$activeNav = 'inbox';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['My Notifications' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">My Notifications</h2>
        <p class="page-header__subtitle"><?= e($unread) ?> unread · click one to open it<?= user_is_admin($currentUser) ? ' and decide it right there' : '' ?></p>
    </div>
    <div class="page-header__actions no-print">
        <?php if ($unread > 0): ?>
            <?= post_button(url('pages/notifications/inbox.php'), ['action' => 'read_all'], 'Mark all as read', 'check') ?>
        <?php endif; ?>
        <?php if (user_is_admin($currentUser)): ?>
            <a href="<?= e(url('pages/notifications/index.php')) ?>" class="btn btn--outline"><?= icon('bell') ?> <span>All sent notifications</span></a>
        <?php endif; ?>
    </div>
</section>

<nav class="notice-filters no-print" aria-label="Filter notifications">
    <a class="chip<?= $show === 'all' && $kind === '' ? ' is-active' : '' ?>" href="<?= e(url('pages/notifications/inbox.php')) ?>">All</a>
    <a class="chip<?= $show === 'unread' ? ' is-active' : '' ?>" href="<?= e(url('pages/notifications/inbox.php', ['show' => 'unread'])) ?>">Unread (<?= e($unread) ?>)</a>
    <?php foreach ($kinds as $k): if (!isset(NOTIFICATION_KINDS[$k['entity_type']])) { continue; } ?>
        <a class="chip<?= $kind === $k['entity_type'] ? ' is-active' : '' ?>" href="<?= e(url('pages/notifications/inbox.php', ['kind' => $k['entity_type']])) ?>"><?= e(NOTIFICATION_KINDS[$k['entity_type']][0]) ?> (<?= e($k['c']) ?>)</a>
    <?php endforeach; ?>
</nav>

<section class="panel panel--flush" aria-label="Notifications">
    <?php if (!$notifications): ?>
        <p class="empty-inline"><?= $show === 'unread' ? 'No unread notifications.' : 'No notifications yet.' ?></p>
    <?php else: ?>
        <ul class="notice-list">
            <?php foreach ($notifications as $n): ?>
                <li class="notice<?= $n['read_at'] === null ? ' is-unread' : ' is-read' ?>">
                    <a class="notice__link" data-modal-form data-notice href="<?= e(url('pages/notifications/open.php', ['id' => $n['id']])) ?>">
                        <span class="notice__dot" aria-hidden="true"></span>
                        <span class="notice__body">
                            <span class="notice__top"><?= notification_kind_chip($n) ?>
                                <?= $n['read_at'] === null ? '<span class="status status--info">New</span>' : '' ?>
                                <time class="text-muted"><?= e(format_datetime($n['created_at'])) ?></time></span>
                            <strong class="notice__title"><?= e($n['title']) ?></strong>
                            <span class="notice__message"><?= e(excerpt($n['message'], 160)) ?></span>
                        </span>
                        <span class="notice__open"><?= icon('eye', 'icon icon--sm') ?> <span>Open</span></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
