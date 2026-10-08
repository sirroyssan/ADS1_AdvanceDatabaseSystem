<?php
/**
 * Users & Roles - create an account with roles and an initial password.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/user_functions.php';

$currentUser = require_admin();

$data = form_defaults(user_fields());
$roles = ['admin'];
$errors = [];

if (is_post()) {
    require_csrf('pages/users/create.php');
    [$data, $errors, $roles, $password] = user_validate($_POST, null, $currentUser['id']);

    if (!$errors) {
        try {
            $id = new_uuid();
            db_transaction(function () use ($id, $data, $roles, $password) {
                db_insert('users', ['id' => $id] + $data + ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
                user_save_roles($id, $roles);
            });
            log_activity('create', 'user', $id, 'Created user ' . $data['email'] . ' (' . implode(', ', $roles) . ')', null, $data + ['roles' => $roles]);
            flash('success', 'User ' . $data['email'] . ' was created successfully.');
            redirect('pages/users/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$formAction  = url('pages/users/create.php');
$submitLabel = 'Create user';
$cancelUrl   = url('pages/users/index.php');
$isNew       = true;

$pageTitle = 'Add User';
$activeNav = 'users';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Users & Roles' => url('pages/users/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add user</h2>
        <p class="page-header__subtitle">The password is stored as a secure hash; nobody can read it back.</p>
    </div>
</section>

<?php require __DIR__ . '/_form.php'; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
