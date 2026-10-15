<?php
/**
 * My Profile - view and edit the signed-in user's own details (users table).
 * Status and roles are not editable here; they are managed in Users & Roles.
 */

require_once __DIR__ . '/../../includes/auth_check.php';

// Every signed-in role manages its own profile; borrowers see it inside the portal layout.
$currentUser = require_role(array_merge(ADMIN_ROLES, ['staff', 'borrower']));
$layout = user_is_admin($currentUser) || user_is_staff($currentUser) ? 'admin' : 'portal';

$fields = [
    'first_name'  => ['label' => 'First name', 'type' => 'text', 'max' => 100],
    'middle_name' => ['label' => 'Middle name', 'type' => 'text', 'max' => 100],
    'last_name'   => ['label' => 'Last name', 'type' => 'text', 'max' => 100],
    'mobile'      => ['label' => 'Mobile', 'type' => 'tel', 'max' => 30, 'pattern' => '/^[0-9+()\-\s]{7,30}$/', 'pattern_message' => 'Enter a valid mobile number.'],
    'email'       => ['label' => 'Email', 'type' => 'email', 'max' => 255, 'required' => true, 'unique' => ['users', 'email', 'This email is already used by another account.']],
    'username'    => ['label' => 'Username', 'type' => 'text', 'max' => 100, 'pattern' => '/^[A-Za-z0-9._-]{3,100}$/',
                      'pattern_message' => 'Use 3–100 letters, numbers, dots, dashes or underscores.', 'unique' => ['users', 'username', 'This username is already taken.']],
];

$profile = db_row('SELECT id, username, email, first_name, middle_name, last_name, mobile, last_login_at, created_at, updated_at FROM users WHERE id = :id', [':id' => $currentUser['id']]);
$data = form_data_from_row($fields, $profile);
$errors = [];

if (is_post()) {
    require_csrf('pages/profile/index.php');
    [$data, $errors] = validate_fields($fields, $_POST, $currentUser['id']);

    if (!$errors) {
        try {
            db_update('users', $data, $currentUser['id']);
            log_activity('update_profile', 'user', $currentUser['id'], 'Updated own profile', array_intersect_key($profile, $data), $data);
            flash('success', 'Your profile was updated.');
            redirect('pages/profile/index.php');
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'My Profile';
$activeNav = 'profile';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['My Profile' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">My profile</h2>
        <p class="page-header__subtitle">
            <?php foreach ($currentUser['roles'] as $role): ?><span class="chip"><?= e(role_label($role)) ?></span> <?php endforeach; ?>
            · Last sign-in <?= e(format_datetime($profile['last_login_at'])) ?>
        </p>
    </div>
    <div class="page-header__actions">
        <a href="<?= e(url('pages/profile/password.php')) ?>" class="btn btn--outline"><?= icon('lock') ?> <span>Change password</span></a>
    </div>
</section>

<?= render_record_form(url('pages/profile/index.php'), $fields, $data, $errors, 'Save profile', url($layout === 'portal' ? 'portal/index.php' : (user_is_staff($currentUser) ? 'pages/staff/index.php' : 'pages/dashboard.php'))) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
