<?php
/**
 * Approvals (system administrators): new accounts waiting to be approved and
 * password reset requests. Approving an account makes it active; rejecting
 * makes it inactive (accounts are never deleted). Approving a reset sets the
 * new password the user chose; rejecting leaves the password as it was.
 * See includes/account_approval.php.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/user_functions.php';

$currentUser = require_system_admin();
password_reset_upgrade();
$self = url('pages/users/approvals.php');

if (is_post()) {
    require_csrf('pages/users/approvals.php');
    $note = trim(mb_substr(input_string($_POST, 'note'), 0, 1000));
    $id = input_string($_POST, 'id');
    $error = match (input_string($_POST, 'action')) {
        'approve_account' => account_decide($id, 'approved', $currentUser),
        'reject_account'  => account_decide($id, 'rejected', $currentUser, $note),
        'approve_reset'   => password_reset_decide($id, 'approved', $currentUser),
        'reject_reset'    => password_reset_decide($id, 'rejected', $currentUser, $note),
        default           => 'Unknown action.',
    };
    $done = [
        'approve_account' => 'The account was approved. The owner can sign in now.',
        'reject_account'  => 'The account was rejected (set to inactive). It cannot sign in.',
        'approve_reset'   => 'The password reset was approved. The user can sign in with the new password now.',
        'reject_reset'    => 'The password reset was rejected. The password was not changed.',
    ];
    flash($error === null ? 'success' : 'error', $error ?? ($done[input_string($_POST, 'action')] ?? 'Done.'));
    redirect('pages/users/approvals.php');
}

$accounts = db_all(
    "SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.mobile, u.created_at,
            (SELECT GROUP_CONCAT(r.role ORDER BY r.role) FROM user_roles r WHERE r.user_id = u.id) AS roles,
            (SELECT l.ip_address FROM activity_logs l WHERE l.action = 'register' AND l.entity_id = u.id ORDER BY l.created_at LIMIT 1) AS ip
       FROM users u WHERE u.status = 'pending' ORDER BY u.created_at"
);
$resets = db_all(
    "SELECT p.*, u.username, u.email, u.first_name, u.last_name, u.status AS user_status, u.last_login_at
       FROM password_reset_requests p JOIN users u ON u.id = p.user_id
      WHERE p.status = 'pending' ORDER BY p.requested_at"
);
$history = db_all(
    "SELECT p.request_number, p.status, p.requested_at, p.decided_at, p.decision_note, u.email, u.first_name, u.last_name, u.username,
            d.first_name AS d_first, d.last_name AS d_last, d.email AS d_email
       FROM password_reset_requests p JOIN users u ON u.id = p.user_id LEFT JOIN users d ON d.id = p.decided_by
      WHERE p.status <> 'pending' ORDER BY COALESCE(p.decided_at, p.requested_at) DESC LIMIT 20"
);


$pageTitle = 'Approvals';
$activeNav = 'approvals';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Users & Roles' => url('pages/users/index.php'), 'Approvals' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Approvals</h2>
        <p class="page-header__subtitle">New owner accounts and password resets wait here until an administrator decides them.</p>
    </div>
</section>

<section class="panel">
    <header class="panel__header"><h3><?= icon('users') ?> New accounts waiting for approval <span class="chip"><?= e(count($accounts)) ?></span></h3></header>
    <?php if (!$accounts): ?>
        <p class="empty-inline">No account is waiting for approval.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr><th scope="col">Name</th><th scope="col">Email / Username</th><th scope="col">Role</th><th scope="col">Registered</th><th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($accounts as $a): ?>
                    <tr>
                        <td data-label="Name"><a class="table-link" href="<?= e(url('pages/users/view.php', ['id' => $a['id']])) ?>"><?= e(user_display_name($a)) ?></a><?= $a['mobile'] ? '<br><span class="text-muted">' . e($a['mobile']) . '</span>' : '' ?></td>
                        <td data-label="Email / Username"><?= e($a['email']) ?><?= $a['username'] ? '<br><span class="text-muted">@' . e($a['username']) . '</span>' : '' ?></td>
                        <td data-label="Role"><?php foreach (array_filter(explode(',', (string) $a['roles'])) as $role): ?><span class="chip"><?= e(role_label($role)) ?></span> <?php endforeach; ?></td>
                        <td data-label="Registered"><?= e(format_datetime($a['created_at'])) ?><?= $a['ip'] ? '<br><span class="text-muted">from ' . e($a['ip']) . '</span>' : '' ?></td>
                        <td class="actions no-print">
                            <?= decision_bar([$self, ['action' => 'approve_account', 'id' => $a['id']],
                                'Approve the account of ' . user_display_name($a) . '? They can sign in right away as an owner of their own records.', 'Approve account?'],
                                'account', ['id' => $a['id']]) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <header class="panel__header"><h3><?= icon('lock') ?> Password reset requests <span class="chip"><?= e(count($resets)) ?></span></h3></header>
    <p class="field-help">Before approving, confirm it is really the account holder (for example, call or message them). Approving sets the new password they chose; you never see it. You cannot decide your own request.</p>
    <?php if (!$resets): ?>
        <p class="empty-inline">No password reset is waiting.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr><th scope="col">Request</th><th scope="col">Account</th><th scope="col">Requested</th><th scope="col">Last sign-in</th><th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($resets as $r): ?>
                    <tr>
                        <td data-label="Request"><strong><?= e($r['request_number']) ?></strong></td>
                        <td data-label="Account"><a class="table-link" href="<?= e(url('pages/users/view.php', ['id' => $r['user_id']])) ?>"><?= e(user_display_name($r)) ?></a><br><span class="text-muted"><?= e($r['email']) ?></span> <?= badge($r['user_status']) ?></td>
                        <td data-label="Requested"><?= e(format_datetime($r['requested_at'])) ?><?= $r['requester_ip'] ? '<br><span class="text-muted">from ' . e($r['requester_ip']) . '</span>' : '' ?></td>
                        <td data-label="Last sign-in"><?= e($r['last_login_at'] ? format_datetime($r['last_login_at']) : 'Never') ?></td>
                        <td class="actions no-print">
                            <?php if ($r['user_id'] === $currentUser['id']): ?>
                                <span class="text-muted">Your own request: another administrator decides it.</span>
                            <?php else: ?>
                                <?= decision_bar([$self, ['action' => 'approve_reset', 'id' => $r['id']],
                                    'Approve the new password of ' . user_display_name($r) . '? Make sure the request really came from them.', 'Approve password reset?'],
                                    'reset', ['id' => $r['id']]) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($history): ?>
<section class="panel">
    <header class="panel__header"><h3><?= icon('history') ?> Recent password reset decisions</h3></header>
    <div class="table-wrap">
        <table class="table table--cards">
            <thead><tr><th scope="col">Request</th><th scope="col">Account</th><th scope="col">Status</th><th scope="col">Decided</th><th scope="col">Note</th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td data-label="Request"><?= e($h['request_number']) ?><br><span class="text-muted"><?= e(format_datetime($h['requested_at'])) ?></span></td>
                    <td data-label="Account"><?= e(user_display_name($h)) ?><br><span class="text-muted"><?= e($h['email']) ?></span></td>
                    <td data-label="Status"><?= badge($h['status']) ?></td>
                    <td data-label="Decided"><?= e($h['decided_at'] ? format_datetime($h['decided_at']) : '—') ?><?= $h['d_email'] ? '<br><span class="text-muted">by ' . e(trim($h['d_first'] . ' ' . $h['d_last']) ?: $h['d_email']) . '</span>' : '' ?></td>
                    <td data-label="Note"><?= e($h['decision_note'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
