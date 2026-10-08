<?php
/**
 * Users & Roles - edit details, status, roles and optionally reset the password.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/user_functions.php';

$currentUser = require_admin();

$user = find_or_redirect(user_find(request_id()), 'pages/users/index.php', 'User');
$data = form_data_from_row(user_fields(), $user);
$roles = $user['roles'];
$errors = [];

if (is_post()) {
    require_csrf('pages/users/edit.php?id=' . $user['id']);
    [$data, $errors, $roles, $password] = user_validate($_POST, $user, $currentUser['id']);

    if (!$errors) {
        try {
            db_transaction(function () use ($user, $data, $roles, $password) {
                $update = $data;
                if ($password !== null) {
                    $update['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
                if ($data['status'] === 'active' && $user['status'] === 'locked') {
                    $update['failed_login_attempts'] = 0;
                    $update['locked_until'] = null;
                }
                db_update('users', $update, $user['id']);
                user_save_roles($user['id'], $roles);
            });

            $old = array_intersect_key($user, $data) + ['roles' => $user['roles']];
            log_activity('update', 'user', $user['id'], 'Updated user ' . $data['email'] . ($password !== null ? ' (password reset)' : ''), $old, $data + ['roles' => $roles]);
            flash('success', 'User ' . $data['email'] . ' was updated successfully.');
            redirect('pages/users/view.php', ['id' => $user['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$formAction  = url('pages/users/edit.php', ['id' => $user['id']]);
$submitLabel = 'Save changes';
$cancelUrl   = url('pages/users/view.php', ['id' => $user['id']]);
$isNew       = false;

$pageTitle = 'Edit User';
$activeNav = 'users';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Users & Roles' => url('pages/users/index.php'),
    user_display_name($user) => url('pages/users/view.php', ['id' => $user['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit user</h2>
        <p class="page-header__subtitle"><?= $user['id'] === $currentUser['id'] ? 'This is your own account; your status and administrator role are protected.' : 'Changes apply on the user\'s next page load.' ?></p>
    </div>
</section>

<?php require __DIR__ . '/_form.php'; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
