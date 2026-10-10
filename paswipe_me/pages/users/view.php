<?php
/**
 * Users & Roles - view account, roles, linked borrower and recent activity.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/user_functions.php';

$currentUser = require_admin();

$user = find_or_redirect(user_find(request_id()), 'pages/users/index.php', 'User');
$isSelf = $user['id'] === $currentUser['id'];

$linkedBorrower = db_row(
    'SELECT b.id, b.borrower_number, b.first_name, b.last_name, a.activated_at
       FROM borrower_user_accounts a JOIN borrowers b ON b.id = a.borrower_id
      WHERE a.user_id = :id',
    [':id' => $user['id']]
);

$activity = db_all(
    'SELECT action, entity_type, description, created_at FROM activity_logs
      WHERE actor_user_id = :id ORDER BY created_at DESC LIMIT 8',
    [':id' => $user['id']]
);

$pageTitle = 'User Details';
$activeNav = 'users';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Users & Roles' => url('pages/users/index.php'), user_display_name($user) => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">User Account</h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e(user_display_name($user)) ?></h2>
        <p class="page-header__subtitle"><?= e($user['email']) ?> · <?= badge($user['status']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/users/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <a data-modal-form href="<?= e(url('pages/users/edit.php', ['id' => $user['id']])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
    </div>
</section>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Account details</h3></header>
        <?= render_details([
            'First name'      => show($user['first_name']),
            'Middle name'     => show($user['middle_name']),
            'Last name'       => show($user['last_name']),
            'Email'           => e($user['email']),
            'Username'        => show($user['username']),
            'Mobile'          => show($user['mobile']),
            'Roles'           => implode(' ', array_map(static fn ($r) => '<span class="chip">' . e(role_label($r)) . '</span>', $user['roles'])) ?: '<span class="text-danger">No role assigned</span>',
            'Status'          => badge($user['status']),
            'Email verified'  => show($user['email_verified_at'] ? format_datetime($user['email_verified_at']) : null),
            'Mobile verified' => show($user['mobile_verified_at'] ? format_datetime($user['mobile_verified_at']) : null),
            'Last sign-in'    => e($user['last_login_at'] ? format_datetime($user['last_login_at']) : 'Never'),
            'Failed sign-ins' => e($user['failed_login_attempts']) . ((int) $user['is_locked'] === 1 ? ' · <span class="text-danger">locked until ' . e(format_datetime($user['locked_until'])) . '</span>' : ''),
            'Created'         => e(format_datetime($user['created_at'])),
            'Last updated'    => e(format_datetime($user['updated_at'])),
        ]) ?>
    </section>

    <div class="detail-side">
        <section class="panel no-print">
            <header class="panel__header"><h3>Account actions</h3></header>
            <div class="action-bar">
                <?php if ($isSelf): ?>
                    <p class="field-help">You cannot change the status of your own account. Use <a href="<?= e(url('pages/profile/index.php')) ?>">My Profile</a> to change your password.</p>
                <?php else: ?>
                    <?php if ($user['status'] !== 'active'): ?>
                        <?= post_button(url('pages/users/actions.php'), ['id' => $user['id'], 'action' => 'status', 'status' => 'active'], 'Activate', 'check', 'btn btn--primary') ?>
                    <?php endif; ?>
                    <?php if ($user['status'] === 'active'): ?>
                        <?= post_button(url('pages/users/actions.php'), ['id' => $user['id'], 'action' => 'status', 'status' => 'suspended'], 'Suspend', 'shield', 'btn btn--warning',
                            'Suspend this account? The user is signed out on their next page load.', false, 'Suspend account?') ?>
                        <?= post_button(url('pages/users/actions.php'), ['id' => $user['id'], 'action' => 'status', 'status' => 'inactive'], 'Deactivate', 'power', 'btn btn--outline',
                            'Deactivate this account? The user can no longer sign in.', false, 'Deactivate account?') ?>
                    <?php endif; ?>
                    <?php if ((int) $user['is_locked'] === 1 || (int) $user['failed_login_attempts'] > 0): ?>
                        <?= post_button(url('pages/users/actions.php'), ['id' => $user['id'], 'action' => 'unlock'], 'Clear sign-in lock', 'lock', 'btn btn--outline') ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <p class="field-help">Users are never deleted because their id is kept in audit trails and financial records.</p>
        </section>

        <section class="panel">
            <header class="panel__header"><h3>Linked borrower</h3></header>
            <?php if ($linkedBorrower): ?>
                <p><?= related_link($linkedBorrower['id'], borrower_name($linkedBorrower) . ' (' . $linkedBorrower['borrower_number'] . ')', 'pages/borrowers/view.php') ?></p>
                <p class="field-help">Activated <?= e(format_datetime($linkedBorrower['activated_at'])) ?></p>
            <?php elseif (in_array('borrower', $user['roles'], true)): ?>
                <p class="field-help">Not linked yet. Open a borrower record to link this account.</p>
            <?php else: ?>
                <p class="field-help">Only accounts with the Borrower role are linked to borrower records.</p>
            <?php endif; ?>
        </section>

        <section class="panel">
            <header class="panel__header"><h3>Recent activity</h3></header>
            <?php if (!$activity): ?>
                <p class="empty-inline">No activity recorded.</p>
            <?php else: ?>
                <ul class="activity-list">
                    <?php foreach ($activity as $log): ?>
                        <li><span class="activity-list__dot" aria-hidden="true"></span>
                            <div><p><?= e(humanize($log['action'])) ?> · <?= e($log['entity_type']) ?></p>
                                <time class="text-muted"><?= e(format_datetime($log['created_at'])) ?></time></div></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
