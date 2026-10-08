<?php
/**
 * Activity Logs - one entry with its old/new values (read-only).
 */

require_once __DIR__ . '/../../includes/auth_check.php';

$currentUser = require_admin();

$id = request_id();
$log = find_or_redirect($id === '' ? null : db_row(
    "SELECT a.*, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, u.email) AS actor
       FROM activity_logs a LEFT JOIN users u ON u.id = a.actor_user_id
      WHERE a.id = :id",
    [':id' => $id]
), 'pages/activity_logs/index.php', 'Log entry');

/**
 * Pretty-print stored JSON for display.
 */
function pretty_json(?string $json): string
{
    if ($json === null || $json === '') {
        return '<span class="text-muted">—</span>';
    }
    $decoded = json_decode($json, true);

    return '<pre class="json-block">' . e($decoded === null ? $json : json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
}

$pageTitle = 'Activity Log Entry';
$activeNav = 'activity_logs';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Activity Logs' => url('pages/activity_logs/index.php'), format_datetime($log['created_at']) => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Audit Log Entry</h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e(humanize($log['action'])) ?> · <?= e($log['entity_type']) ?></h2>
        <p class="page-header__subtitle"><?= e(format_datetime($log['created_at'])) ?> · <?= e($log['actor'] ?? 'System / anonymous') ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/activity_logs/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
    </div>
</section>

<section class="panel">
    <?= render_details([
        'When'        => e(format_datetime($log['created_at'])),
        'User'        => $log['actor_user_id'] ? related_link($log['actor_user_id'], $log['actor'], 'pages/users/view.php') : 'System / anonymous',
        'Action'      => e($log['action']),
        'Record type' => e($log['entity_type']),
        'Record id'   => $log['entity_id'] ? '<code>' . e($log['entity_id']) . '</code>' : '',
        'Description' => show_multiline($log['description']),
        'IP address'  => show($log['ip_address']),
        'Browser'     => show($log['user_agent']),
        'Old values'  => pretty_json($log['old_values']),
        'New values'  => pretty_json($log['new_values']),
    ]) ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
