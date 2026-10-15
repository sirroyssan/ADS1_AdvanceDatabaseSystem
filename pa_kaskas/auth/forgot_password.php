<?php
/**
 * Forgot password: ask for a new password. It does not change anything yet -
 * a system administrator approves the request first (System → Approvals),
 * then the new password works. See includes/account_approval.php.
 *
 * The answer is the same whether or not the account exists, and requests are
 * limited per network, so the page cannot be used to find accounts.
 */

require_once __DIR__ . '/../includes/auth_check.php';

send_no_cache_headers();

if (is_logged_in() && !empty($_SESSION['user_roles'])) {
    redirect('pages/profile/index.php');   // signed in: change it in My Profile
}

$identifier = '';
$errors = [];

if (is_post()) {
    if (!csrf_is_valid()) {
        $errors['_form'] = 'Your form expired. Please try again.';
    } else {
        $identifier = trim(input_string($_POST, 'identifier'));
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';
        if ($identifier === '' || mb_strlen($identifier) > 255) {
            $errors['identifier'] = 'Enter your username or email.';
        }
        $errors += password_policy_errors($password, $confirm);
        $recent = (int) db_value(
            "SELECT COUNT(*) FROM activity_logs WHERE action = 'password_reset_request' AND ip_address = :ip AND created_at > NOW() - INTERVAL 1 HOUR",
            [':ip' => client_ip()]
        );
        if (!$errors && $recent >= PASSWORD_RESET_LIMIT_PER_HOUR) {
            $errors['_form'] = 'Too many requests from this network. Please try again later.';
        }
        if (!$errors) {
            try {
                password_reset_request($identifier, $password);
                flash('success', 'Your request was sent to the administrator. Once it is approved, sign in with your new password. Until then, your current password still works.');
                redirect('auth/login.php');
            } catch (PDOException $e) {
                $errors['_form'] = db_error_message($e);
            }
        }
    }
}

$fields = [
    'identifier'       => ['label' => 'Username or email', 'type' => 'text', 'max' => 255, 'required' => true],
    'password'         => ['label' => 'New password', 'type' => 'password', 'max' => 255, 'required' => true,
                           'help' => 'At least ' . PASSWORD_MIN_LENGTH . ' characters, with a letter and a number.'],
    'password_confirm' => ['label' => 'Confirm new password', 'type' => 'password', 'max' => 255, 'required' => true],
];
$flashes = take_flash_messages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?= favicon_tag() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot password · <?= e(APP_NAME) ?></title>
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
                <li><?= icon('check') ?> Choose your new password</li>
                <li><?= icon('check') ?> The administrator approves the request</li>
                <li><?= icon('check') ?> Then sign in with the new password</li>
            </ul>
        </div>
    </section>

    <section class="auth-card" aria-labelledby="forgot-title">
        <header class="auth-card__header">
            <div class="brand-mark"><?= icon('lock') ?></div>
            <h1 id="forgot-title">Forgot password</h1>
            <p class="text-muted">Enter your account and a new password. It starts working once the administrator approves the request.</p>
        </header>

        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert--<?= e($flash['type']) ?>" role="status"><?= icon('info') ?><span><?= e($flash['message']) ?></span></div>
        <?php endforeach; ?>
        <?php if ($errors): ?>
            <div class="alert alert--error" role="alert"><?= icon('alert') ?>
                <span><?= e($errors['_form'] ?? 'Please correct the highlighted fields.') ?></span></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('auth/forgot_password.php')) ?>" class="auth-form" id="forgot-form">
            <?= csrf_field() ?>
            <?= render_fields($fields, ['identifier' => $identifier, 'password' => '', 'password_confirm' => ''], $errors) ?>
            <button type="submit" class="btn btn--primary btn--block" data-loading-text="Sending…">
                <span class="btn__label">Send request</span>
            </button>
        </form>

        <p class="auth-card__switch">Remembered it? <a href="<?= e(url('auth/login.php')) ?>">Sign in</a></p>

        <footer class="auth-card__footer">
            &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All Rights Reserved.
        </footer>
    </section>
</main>
</body>
</html>
