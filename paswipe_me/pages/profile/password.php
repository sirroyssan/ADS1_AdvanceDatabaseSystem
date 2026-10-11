<?php
/**
 * My Profile - change password (current password required, new one hashed).
 */

require_once __DIR__ . '/../../includes/auth_check.php';

// Every signed-in role manages its own profile; borrowers see it inside the portal layout.
$currentUser = require_role(array_merge(ADMIN_ROLES, ['staff', 'borrower']));
$layout = user_is_admin($currentUser) || user_is_staff($currentUser) ? 'admin' : 'portal';

$errors = [];

if (is_post()) {
    require_csrf('pages/profile/password.php');

    $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
    $new     = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    $hash = (string) db_value('SELECT password_hash FROM users WHERE id = :id', [':id' => $currentUser['id']]);

    if ($current === '') {
        $errors['current_password'] = 'Enter your current password.';
    } elseif (!password_verify($current, $hash)) {
        $errors['current_password'] = 'Your current password is incorrect.';
    }
    if ($current !== '' && $new !== '' && hash_equals($current, $new)) {
        $errors['new_password'] = 'Choose a password different from the current one.';
    } else {
        $errors += password_policy_errors($new, $confirm, 'new_password', 'confirm_password');
    }

    if (!$errors) {
        db_update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], $currentUser['id']);
        // New session id after a credential change.
        session_regenerate_id(true);
        log_activity('change_password', 'user', $currentUser['id'], 'Changed own password');
        flash('success', 'Your password was changed.');
        redirect('pages/profile/index.php');
    }
}

$fields = [
    'current_password' => ['label' => 'Current password', 'type' => 'password', 'max' => 255, 'required' => true, 'full' => true],
    'new_password'     => ['label' => 'New password', 'type' => 'password', 'max' => 255, 'required' => true, 'help' => 'At least ' . PASSWORD_MIN_LENGTH . ' characters, with a letter and a number.'],
    'confirm_password' => ['label' => 'Confirm new password', 'type' => 'password', 'max' => 255, 'required' => true],
];

$pageTitle = 'Change Password';
$activeNav = 'profile';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['My Profile' => url('pages/profile/index.php'), 'Change password' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Change password</h2>
        <p class="page-header__subtitle">Your password is stored only as a secure hash.</p>
    </div>
</section>

<?= render_record_form(url('pages/profile/password.php'), $fields, [], $errors, 'Change password', url('pages/profile/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
