<?php
/**
 * My Profile - change password (current password required, new one hashed).
 */

require_once __DIR__ . '/../../includes/auth_check.php';

// Every signed-in role manages its own profile; borrowers see it inside the portal layout.
$currentUser = require_role(array_merge(ADMIN_ROLES, ['borrower']));
$layout = user_is_admin($currentUser) ? 'admin' : 'portal';

const PROFILE_MIN_PASSWORD = 8;

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
    if (strlen($new) < PROFILE_MIN_PASSWORD) {
        $errors['new_password'] = 'New password must be at least ' . PROFILE_MIN_PASSWORD . ' characters.';
    } elseif (strlen($new) > 255) {
        $errors['new_password'] = 'New password is too long.';
    } elseif ($current !== '' && hash_equals($current, $new)) {
        $errors['new_password'] = 'Choose a password different from the current one.';
    }
    if (!isset($errors['new_password']) && !hash_equals($new, $confirm)) {
        $errors['confirm_password'] = 'Passwords do not match.';
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
    'new_password'     => ['label' => 'New password', 'type' => 'password', 'max' => 255, 'required' => true, 'help' => 'At least ' . PROFILE_MIN_PASSWORD . ' characters.'],
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
