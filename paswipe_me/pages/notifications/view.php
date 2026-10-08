<?php
/**
 * Notifications - message, delivery log, mark as read (own) or cancel (pending).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/notification_functions.php';

/** Pages that open the record a notification is about. */
const ADMIN_ENTITY_PAGES = [
    'borrower_payment' => 'pages/borrower_payments/view.php',
    'demand_letter'    => 'pages/demand_letters/view.php',
    'obligation'       => 'pages/obligations/view.php',
    'agreement'        => 'pages/agreements/view.php',
];

$currentUser = require_admin();

$note = find_or_redirect(notification_find(request_id()), 'pages/notifications/index.php', 'Notification');
$isMine = $note['user_id'] === $currentUser['id'];

if (is_post()) {
    require_csrf('pages/notifications/view.php?id=' . $note['id']);
    $action = input_string($_POST, 'action');

    if ($action === 'read' && $isMine && $note['read_at'] === null) {
        db_update('notifications', ['status' => 'read', 'read_at' => date('Y-m-d H:i:s')], $note['id']);
        db_insert('notification_logs', ['id' => new_uuid(), 'notification_id' => $note['id'], 'channel' => $note['channel'], 'status' => 'read']);
        flash('success', 'Marked as read.');
    } elseif ($action === 'cancel' && $note['status'] === 'pending') {
        db_update('notifications', ['status' => 'cancelled'], $note['id']);
        db_insert('notification_logs', ['id' => new_uuid(), 'notification_id' => $note['id'], 'channel' => $note['channel'], 'status' => 'cancelled']);
        log_activity('cancel', 'notification', $note['id'], 'Cancelled notification "' . $note['title'] . '"');
        flash('success', 'Notification was cancelled.');
    } else {
        flash('error', 'That action is not available for this notification.');
    }
    redirect('pages/notifications/view.php', ['id' => $note['id']]);
}

$logs = db_all('SELECT * FROM notification_logs WHERE notification_id = :id ORDER BY attempted_at', [':id' => $note['id']]);

$pageTitle = 'Notification';
$activeNav = 'notifications';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Notifications' => url('pages/notifications/index.php'), $note['title'] => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Notification</h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($note['title']) ?></h2>
        <p class="page-header__subtitle">To <?= e($note['recipient_name']) ?> · <?= e(humanize($note['channel'])) ?> · <?= badge($note['status']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/notifications/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <?php if ($isMine && $note['read_at'] === null && !in_array($note['status'], ['cancelled', 'failed'], true)): ?>
            <?= post_button(url('pages/notifications/view.php', ['id' => $note['id']]), ['action' => 'read'], 'Mark as read', 'check', 'btn btn--primary') ?>
        <?php endif; ?>
        <?php if ($note['status'] === 'pending'): ?>
            <?= post_button(url('pages/notifications/view.php', ['id' => $note['id']]), ['action' => 'cancel'], 'Cancel', 'x', 'btn btn--outline',
                'Cancel this pending notification?', false, 'Cancel notification?') ?>
        <?php endif; ?>
    </div>
</section>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Message</h3></header>
        <div class="document-body"><?= e($note['message']) ?></div>
    </section>
    <div class="detail-side">
        <section class="panel">
            <header class="panel__header"><h3>Details</h3></header>
            <?= render_details([
                'Recipient'      => related_link($note['user_id'], $note['recipient_name'] . ' <' . $note['email'] . '>', 'pages/users/view.php'),
                'Type'           => e(humanize($note['notification_type'])),
                'Channel'        => e(humanize($note['channel'])),
                'Status'         => badge($note['status']),
                'Related record' => $note['entity_type'] ? (isset(ADMIN_ENTITY_PAGES[$note['entity_type']]) && $note['entity_id']
                    ? '<a href="' . e(url(ADMIN_ENTITY_PAGES[$note['entity_type']], ['id' => $note['entity_id']])) . '">Open ' . e(humanize($note['entity_type'])) . '</a>'
                    : e($note['entity_type']) . ($note['entity_id'] ? ' <code>' . e($note['entity_id']) . '</code>' : '')) : '',
                'Scheduled'      => show($note['scheduled_at'] ? format_datetime($note['scheduled_at']) : null),
                'Sent'           => show($note['sent_at'] ? format_datetime($note['sent_at']) : null),
                'Read'           => show($note['read_at'] ? format_datetime($note['read_at']) : null),
                'Created'        => e(format_datetime($note['created_at'])),
            ]) ?>
        </section>
        <section class="panel">
            <header class="panel__header"><h3>Delivery log</h3></header>
            <?php if (!$logs): ?>
                <p class="empty-inline">No delivery attempts.</p>
            <?php else: ?>
                <ul class="activity-list">
                    <?php foreach ($logs as $log): ?>
                        <li><span class="activity-list__dot" aria-hidden="true"></span>
                            <div><p><?= badge($log['status']) ?> · <?= e(humanize($log['channel'])) ?></p>
                                <?php if ($log['error_message']): ?><p class="text-muted"><?= e($log['error_message']) ?></p><?php endif; ?>
                                <time class="text-muted"><?= e(format_datetime($log['attempted_at'])) ?></time></div></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
