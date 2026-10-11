<?php
/**
 * Notifications - send (or queue) a notification and write its delivery log.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/notification_functions.php';

$currentUser = require_admin();

$fields = notification_fields();
$preset = input_string($_GET, 'user_id');
$data = form_defaults($fields, isset($fields['user_id']['options'][$preset]) ? ['user_id' => $preset] : []);
$errors = [];

if (is_post()) {
    require_csrf('pages/notifications/create.php');
    [$data, $errors] = validate_fields($fields, $_POST);
    if ($data['entity_id'] !== null) {
        $data['entity_id'] = strtolower($data['entity_id']);
    }

    if (!$errors) {
        try {
            $id = new_uuid();
            $now = date('Y-m-d H:i:s');
            $deliverNow = $data['channel'] === 'in_app' && ($data['scheduled_at'] === null || $data['scheduled_at'] <= $now);
            $status = $deliverNow ? 'delivered' : 'pending';

            db_transaction(function () use ($id, $data, $status, $deliverNow, $now) {
                db_insert('notifications', ['id' => $id] + $data + ['status' => $status, 'sent_at' => $deliverNow ? $now : null]);
                db_insert('notification_logs', [
                    'id' => new_uuid(), 'notification_id' => $id, 'channel' => $data['channel'], 'status' => $status,
                    'error_message' => $deliverNow ? null : ($data['channel'] === 'in_app' ? 'Scheduled for ' . $data['scheduled_at'] : 'No ' . $data['channel'] . ' provider is configured; queued.'),
                ]);
            });

            log_activity('create', 'notification', $id, 'Notification "' . $data['title'] . '" (' . $data['channel'] . ', ' . $status . ')');
            flash('success', $deliverNow ? 'Notification was delivered in-app.' : 'Notification was queued as pending.');
            redirect('pages/notifications/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Send Notification';
$activeNav = 'notifications';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Notifications' => url('pages/notifications/index.php'), 'Send' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Send notification</h2>
        <p class="page-header__subtitle">In-app messages are delivered immediately. Email, SMS and push are queued because no provider is configured in this installation.</p>
    </div>
</section>

<?= render_record_form(url('pages/notifications/create.php'), $fields, $data, $errors, 'Send', url('pages/notifications/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
