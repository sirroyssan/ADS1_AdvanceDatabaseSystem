<?php
/**
 * Staff Accounts (owner) - the people who record transactions and receive
 * payments for you. A staff account sees only your records, cannot change
 * settings, and needs your approval to edit or delete a confirmed record.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/pages/users/user_functions.php';

$currentUser = require_admin();

$fields = array_intersect_key(user_fields(), array_flip(['first_name', 'last_name', 'email', 'username']));
$fields['first_name']['required'] = true;
$fields['last_name']['required'] = true;
unset($fields['email']['section']);
$data = ['first_name' => '', 'last_name' => '', 'email' => '', 'username' => ''];
$errors = [];

if (is_post()) {
    require_csrf('pages/staff_accounts/index.php');
    $action = input_string($_POST, 'action');
    if ($action === 'toggle') {
        // Only a staff member of this owner (the row is found by both ids).
        $staff = db_row('SELECT s.*, u.email FROM staff_members s JOIN users u ON u.id = s.user_id WHERE s.id = :id AND s.owner_user_id = :o',
            [':id' => input_string($_POST, 'id'), ':o' => owner_id()]);
        if (!$staff) {
            flash('error', 'Staff account not found.');
        } else {
            $active = (int) $staff['is_active'] === 1 ? 0 : 1;
            db_update('staff_members', ['is_active' => $active, 'deactivated_at' => $active ? null : date('Y-m-d H:i:s')], $staff['id']);
            log_activity($active ? 'staff_activate' : 'staff_deactivate', 'user', $staff['user_id'], ($active ? 'Re-activated' : 'Deactivated') . ' staff account ' . $staff['email']);
            flash('success', 'Staff account ' . $staff['email'] . ($active ? ' can sign in again.' : ' was deactivated; it can no longer sign in to your records.'));
        }
        redirect('pages/staff_accounts/index.php');
    }

    [$data, $errors] = validate_fields($fields, $_POST);
    $data['email'] = mb_strtolower((string) $data['email']);
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';
    $errors += password_policy_errors($password, $confirm);
    if (!isset($errors['email']) && db_value('SELECT COUNT(*) FROM users WHERE LOWER(email) = :e', [':e' => $data['email']]) > 0) {
        $errors['email'] = 'An account with this email already exists.';
    }
    if (!$errors && !form_once_use('staff_create', input_string($_POST, 'once'))) {
        flash('info', 'This staff account was already created.');
        redirect('pages/staff_accounts/index.php');
    }
    if (!$errors) {
        try {
            $id = new_uuid();
            db_transaction(static function () use ($id, $data, $password, $currentUser) {
                db_insert('users', ['id' => $id] + $data + ['status' => 'active', 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
                db_insert('user_roles', ['id' => new_uuid(), 'user_id' => $id, 'role' => 'staff']);
                db_insert('staff_members', ['id' => new_uuid(), 'user_id' => $id, 'owner_user_id' => owner_id(), 'created_by' => $currentUser['id']]);
            });
            log_activity('staff_create', 'user', $id, 'Created staff account ' . $data['email'], null, $data);
            flash('success', 'Staff account for ' . $data['first_name'] . ' was created. They sign in with ' . $data['email'] . ' and the password you set.');
            redirect('pages/staff_accounts/index.php');
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$staff = staff_accounts();
$fields['password'] = ['label' => 'Password', 'type' => 'password', 'max' => 255, 'required' => true,
                       'help' => 'At least ' . PASSWORD_MIN_LENGTH . ' characters, with a letter and a number. Give it to the staff member privately.'];
$fields['password_confirm'] = ['label' => 'Confirm password', 'type' => 'password', 'max' => 255, 'required' => true];

$pageTitle = 'Staff Accounts';
$activeNav = 'staff_accounts';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Staff Accounts' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Staff accounts</h2>
        <p class="page-header__subtitle">Staff record transactions, receive borrower payments and print receipts for you. They see only your records and need your approval to edit or delete a confirmed record.</p>
    </div>
    <div class="page-header__actions">
        <a href="<?= e(url('pages/authorizations/index.php')) ?>" class="btn btn--outline"><?= icon('shield') ?> <span>Authorization requests</span></a>
        <button type="button" class="btn btn--primary" data-modal-panel="add-staff"><?= icon('plus') ?> <span>Add staff</span></button>
    </div>
</section>

<form method="post" action="<?= e(url('pages/staff_accounts/index.php')) ?>" class="inline-panel" id="add-staff" <?= $errors ? '' : 'hidden' ?> novalidate data-validate>
    <?= csrf_field() ?>
    <?= form_once_field('staff_create') ?>
    <?php if (isset($errors['_form'])): ?><div class="alert alert--error"><?= icon('alert') ?><span><?= e($errors['_form']) ?></span></div><?php endif; ?>
    <?= render_fields($fields, $data, $errors) ?>
    <div class="inline-panel__actions">
        <button type="button" class="btn btn--outline" data-toggle-panel="add-staff">Cancel</button>
        <button type="submit" class="btn btn--primary" data-loading-text="Saving…"><?= icon('check') ?> <span class="btn__label">Create staff account</span></button>
    </div>
</form>

<section class="panel panel--flush" aria-label="Staff accounts">
    <?php if (!$staff): ?>
        <p class="empty-inline">No staff yet. Add someone who records transactions or collects payments for you.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr><th scope="col">Name</th><th scope="col">Sign-in</th><th scope="col">Last sign-in</th><th scope="col">Status</th><th scope="col" class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($staff as $s): ?>
                    <tr>
                        <td data-label="Name"><?= e(trim($s['first_name'] . ' ' . $s['last_name'])) ?></td>
                        <td data-label="Sign-in"><?= e($s['email']) ?><?= $s['username'] ? '<br><span class="text-muted">' . e($s['username']) . '</span>' : '' ?></td>
                        <td data-label="Last sign-in"><?= $s['last_login_at'] ? e(format_datetime($s['last_login_at'])) : '<span class="text-muted">Never</span>' ?></td>
                        <td data-label="Status"><?= active_badge($s['is_active']) ?></td>
                        <td class="actions"><?= post_button(url('pages/staff_accounts/index.php'), ['action' => 'toggle', 'id' => $s['staff_id']],
                            (int) $s['is_active'] ? 'Deactivate' : 'Activate', 'power', 'btn btn--sm btn--outline',
                            (int) $s['is_active'] ? 'Deactivate this staff account? They can no longer sign in to your records.' : 'Let this staff member sign in again?', false) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
