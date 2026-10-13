<?php
/**
 * Self-registration: anyone can create their own account and manage their
 * own credit cards and records. New accounts get the Owner role (an owner of
 * their own records only - see includes/ownership.php); administrator and
 * borrower accounts are still created by an administrator / a lender.
 *
 *   users.email     required, unique (checked here and by the UNIQUE key)
 *   users.username  optional, unique
 *   users.password_hash  password_hash(), never the plain password
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once APP_ROOT . '/pages/users/user_functions.php';

const REGISTER_LIMIT_PER_HOUR = 10;   // per IP address, against automated sign-ups

send_no_cache_headers();

if (is_logged_in() && !empty($_SESSION['user_roles'])) {
    redirect('pages/dashboard.php');
}

$fields = array_intersect_key(user_fields(), array_flip(['first_name', 'last_name', 'email', 'username']));
$fields['first_name']['required'] = true;
$fields['last_name']['required'] = true;
unset($fields['email']['section']);

$data = ['first_name' => '', 'last_name' => '', 'email' => '', 'username' => ''];
$errors = [];

if (is_post()) {
    if (!csrf_is_valid()) {
        $errors['_form'] = 'Your form expired. Please try again.';
    } else {
        [$data, $errors] = validate_fields($fields, $_POST);
        $data['email'] = mb_strtolower((string) $data['email']);
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';
        $errors += password_policy_errors($password, $confirm);

        // Email is unique regardless of letter case.
        if (!isset($errors['email']) && db_value('SELECT COUNT(*) FROM users WHERE LOWER(email) = :e', [':e' => $data['email']]) > 0) {
            $errors['email'] = 'An account with this email already exists. Sign in instead.';
        }
        $recent = (int) db_value(
            "SELECT COUNT(*) FROM activity_logs WHERE action = 'register' AND ip_address = :ip AND created_at > NOW() - INTERVAL 1 HOUR",
            [':ip' => client_ip()]
        );
        if (!$errors && $recent >= REGISTER_LIMIT_PER_HOUR) {
            $errors['_form'] = 'Too many new accounts from this network. Please try again later.';
        }

        if (!$errors) {
            try {
                $id = new_uuid();
                db_transaction(function () use ($id, $data, $password) {
                    db_insert('users', ['id' => $id] + $data + ['status' => 'active', 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
                    db_insert('user_roles', ['id' => new_uuid(), 'user_id' => $id, 'role' => 'owner']);
                });
                $user = ['id' => $id] + $data;
                sign_in_user($user, ['owner']);
                db_exec('UPDATE users SET last_login_at = NOW() WHERE id = :id', [':id' => $id]);
                log_activity('register', 'user', $id, 'Registered account ' . $data['email'], null, $data, $id);
                flash('success', 'Welcome, ' . $_SESSION['display_name'] . '! Your account is ready. Start by adding your first credit card.');
                redirect('pages/dashboard.php');
            } catch (PDOException $e) {
                $errors['_form'] = db_error_message($e);
            }
        }
    }
}

$fields['password'] = ['label' => 'Password', 'type' => 'password', 'max' => 255, 'required' => true,
                       'help' => 'At least ' . PASSWORD_MIN_LENGTH . ' characters, with a letter and a number.'];
$fields['password_confirm'] = ['label' => 'Confirm password', 'type' => 'password', 'max' => 255, 'required' => true];
$flashes = take_flash_messages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?= favicon_tag() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/base.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/auth.css')) ?>">
</head>
<body class="auth-page">

<main class="auth-shell">
    <section class="auth-brand" aria-hidden="true">
        <div class="auth-brand__inner">
            <div class="brand-mark brand-mark--lg"><?= icon('credit-card') ?></div>
            <h2><?= e(APP_NAME) ?></h2>
            <p><?= e(APP_TAGLINE) ?></p>
            <ul class="auth-brand__list">
                <li><?= icon('check') ?> Your own cards, statements and payments</li>
                <li><?= icon('check') ?> Private to your account</li>
                <li><?= icon('check') ?> Installments, cash loans and borrowers</li>
            </ul>
        </div>
    </section>

    <section class="auth-card" aria-labelledby="register-title">
        <header class="auth-card__header">
            <div class="brand-mark"><?= icon('credit-card') ?></div>
            <h1 id="register-title">Create your account</h1>
            <p class="text-muted">Your cards and records are visible only to you.</p>
        </header>

        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert--<?= e($flash['type']) ?>" role="status"><?= icon('info') ?><span><?= e($flash['message']) ?></span></div>
        <?php endforeach; ?>
        <?php if ($errors): ?>
            <div class="alert alert--error" role="alert"><?= icon('alert') ?>
                <span><?= e($errors['_form'] ?? 'Please correct the highlighted fields.') ?></span></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('auth/register.php')) ?>" class="auth-form" id="register-form">
            <?= csrf_field() ?>
            <?= render_fields($fields, $data, $errors) ?>
            <button type="submit" class="btn btn--primary btn--block" data-loading-text="Creating account…">
                <span class="btn__label">Create account</span>
            </button>
        </form>

        <p class="auth-card__switch">Already have an account? <a href="<?= e(url('auth/login.php')) ?>">Sign in</a></p>

        <footer class="auth-card__footer">
            &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All Rights Reserved.
        </footer>
    </section>
</main>

</body>
</html>
