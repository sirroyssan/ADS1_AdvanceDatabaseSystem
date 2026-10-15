<?php
/**
 * Borrower portal - my notifications. Opening one marks it read;
 * POST action=read_all marks every notification read.
 */

require_once __DIR__ . '/../includes/auth_check.php';

[$currentUser, $borrower] = require_borrower();

const PORTAL_NOTIFICATION_LINKS = [
    'borrower_payment'      => 'portal/payment.php',
    'borrower_obligation'   => 'portal/obligation.php',
    'obligation'            => 'portal/obligation.php',
    'agreement'             => 'portal/agreement.php',
    'demand_letter'         => 'portal/letter.php',
    'borrowed_purchase'     => 'pages/borrowed/view.php',
];

if (is_post()) {
    require_csrf('portal/notifications.php');
    $count = db_exec(
        "UPDATE notifications SET read_at = NOW(), status = 'read' WHERE user_id = :u AND read_at IS NULL AND status NOT IN ('cancelled', 'failed')",
        [':u' => $currentUser['id']]
    );
    flash('success', $count . ' notification(s) marked as read.');
    redirect('portal/notifications.php');
}

$openId = input_string($_GET, 'open');
if (is_valid_uuid($openId)) {
    $n = db_row('SELECT * FROM notifications WHERE id = :id AND user_id = :u', [':id' => $openId, ':u' => $currentUser['id']]);
    if ($n) {
        if ($n['read_at'] === null) {
            db_exec("UPDATE notifications SET read_at = NOW(), status = 'read' WHERE id = :id", [':id' => $n['id']]);
        }
        $target = PORTAL_NOTIFICATION_LINKS[$n['entity_type']] ?? null;
        if ($target && $n['entity_id']) {
            redirect($target, ['id' => $n['entity_id']]);
        }
    }
    redirect('portal/notifications.php');
}

$notifications = db_all(
    "SELECT * FROM notifications WHERE user_id = :u AND channel = 'in_app' AND status NOT IN ('cancelled', 'failed', 'pending')
      ORDER BY created_at DESC LIMIT 100",
    [':u' => $currentUser['id']]
);
$unread = count(array_filter($notifications, static fn ($n) => $n['read_at'] === null));

$pageTitle = 'Notifications';
$activeNav = 'portal_notifications';
$layout = 'portal';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Notifications' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Notifications</h2>
        <p class="page-header__subtitle"><?= e($unread) ?> unread · latest 100</p>
    </div>
    <?php if ($unread > 0): ?>
        <div class="page-header__actions"><?= post_button(url('portal/notifications.php'), ['action' => 'read_all'], 'Mark all as read', 'check') ?></div>
    <?php endif; ?>
</section>

<section class="panel">
    <?php if (!$notifications): ?>
        <p class="empty-inline">No notifications yet.</p>
    <?php else: ?>
        <ul class="activity-list">
            <?php foreach ($notifications as $n): ?>
                <li class="<?= $n['read_at'] === null ? 'is-unread' : '' ?>">
                    <span class="activity-list__dot" aria-hidden="true"></span>
                    <div>
                        <p><a href="<?= e(url('portal/notifications.php', ['open' => $n['id']])) ?>"><strong><?= e($n['title']) ?></strong></a>
                            <?= $n['read_at'] === null ? '<span class="status status--info">New</span>' : '' ?></p>
                        <p><?= e($n['message']) ?></p>
                        <p class="text-muted"><?= e(format_datetime($n['created_at'])) ?></p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
