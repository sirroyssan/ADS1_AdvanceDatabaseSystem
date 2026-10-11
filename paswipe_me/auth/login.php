<?php
/**
 * Login page.
 *
 * Authentication data comes from the schema:
 *   users.email (required, unique) or users.username (optional, unique)
 *   users.password_hash  - verified with password_verify()
 *   users.status         - only 'active' may sign in
 *   users.failed_login_attempts / users.locked_until - brute-force lockout
 *   user_roles.role      - owner | admin | borrower
 */

require_once __DIR__ . '/../includes/auth_check.php';

/**
 * Where each role goes after signing in.
 */
function home_for(array $roles): string
{
    if (array_intersect(ADMIN_ROLES, $roles)) {
        return 'pages/dashboard.php';
    }

    if (in_array('borrower', $roles, true)) {
        return 'portal/index.php';
    }

    return 'auth/access_denied.php';
}

send_no_cache_headers();

// Already signed in: go straight to the right page.
if (is_logged_in() && !empty($_SESSION['user_roles'])) {
    redirect(home_for($_SESSION['user_roles']));
}

$errors = [];
$identifier = '';

if (is_post()) {
    $identifier = input_string($_POST, 'identifier');
    $password   = $_POST['password'] ?? '';
    $password   = is_string($password) ? $password : '';

    // --- Server-side validation ---------------------------------------
    if (!csrf_is_valid()) {
        $errors[] = 'Your login form expired. Please try again.';
    }
    if ($identifier === '') {
        $errors[] = 'Please enter your username or email.';
    } elseif (mb_strlen($identifier) > 255) {
        $errors[] = 'Username or email is too long.';
    }
    if ($password === '') {
        $errors[] = 'Please enter your password.';
    }

    if (!$errors) {
        // --- Database query -------------------------------------------
        // Match email first, then username (both are UNIQUE in the schema).
        $stmt = db()->prepare(
            'SELECT id, username, email, password_hash, first_name, last_name, status,
                    failed_login_attempts, locked_until,
                    (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked,
                    GREATEST(1, CEIL(TIMESTAMPDIFF(SECOND, NOW(), locked_until) / 60)) AS lock_minutes
               FROM users
              WHERE email = :email OR username = :username
              ORDER BY (email = :email_order) DESC
              LIMIT 1'
        );
        $stmt->execute([
            ':email'       => $identifier,
            ':username'    => $identifier,
            ':email_order' => $identifier,
        ]);
        $user = $stmt->fetch();

        $genericError = 'Invalid username/email or password.';

        if (!$user) {
            // Spend the same time as a real check so unknown accounts are not revealed.
            password_verify($password, '$2y$10$lP/U9g1CsY2SSTsHM1eLAO4xRmFvcng19QuSMAw/WeJPdXZpOnZ/e');
            log_activity('login_failed', 'user', null, 'Unknown account: ' . mb_substr($identifier, 0, 100));
            $errors[] = $genericError;
        } elseif ((int) $user['is_locked'] === 1) {
            $errors[] = 'Too many failed attempts. Try again in ' . (int) $user['lock_minutes'] . ' minute(s).';
        } elseif (!password_verify($password, $user['password_hash'])) {
            // --- Wrong password: count it and lock if needed ---------------
            $attempts = (int) $user['failed_login_attempts'] + 1;

            if ($attempts >= MAX_FAILED_LOGINS) {
                $update = db()->prepare(
                    'UPDATE users
                        SET failed_login_attempts = 0,
                            locked_until = NOW() + INTERVAL ' . (int) LOCKOUT_MINUTES . ' MINUTE
                      WHERE id = :id'
                );
                $update->execute([':id' => $user['id']]);
                log_activity('login_locked', 'user', $user['id'], 'Locked after too many failed logins', null, null, $user['id']);
                $errors[] = 'Too many failed attempts. Try again in ' . LOCKOUT_MINUTES . ' minute(s).';
            } else {
                $update = db()->prepare('UPDATE users SET failed_login_attempts = :n WHERE id = :id');
                $update->execute([':n' => $attempts, ':id' => $user['id']]);
                log_activity('login_failed', 'user', $user['id'], 'Wrong password', null, null, $user['id']);
                $errors[] = $genericError;
            }
        } else {
            // --- Password correct: check account status ---------------------
            $statusMessages = [
                'pending'   => 'Your account is pending activation. Please contact the administrator.',
                'suspended' => 'Your account has been suspended. Please contact the administrator.',
                'locked'    => 'Your account is locked. Please contact the administrator.',
                'inactive'  => 'Your account is inactive. Please contact the administrator.',
            ];

            // --- Check user role ------------------------------------------
            $roles = fetch_user_roles($user['id']);

            if ($user['status'] !== 'active') {
                log_activity('login_blocked', 'user', $user['id'], 'Status: ' . $user['status'], null, null, $user['id']);
                $errors[] = $statusMessages[$user['status']] ?? 'Your account cannot sign in.';
            } elseif (!$roles) {
                log_activity('login_blocked', 'user', $user['id'], 'No role assigned', null, null, $user['id']);
                $errors[] = 'Your account has no role assigned. Please contact the administrator.';
            } else {
                // Upgrade the hash automatically if PHP's default algorithm changed.
                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    $rehash = db()->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
                    $rehash->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id']]);
                }

                $reset = db()->prepare(
                    'UPDATE users
                        SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW()
                      WHERE id = :id'
                );
                $reset->execute([':id' => $user['id']]);

                // --- Create session (new id prevents session fixation) ------
                sign_in_user($user, $roles);

                log_activity('login', 'user', $user['id'], 'Signed in as ' . $roles[0]);

                flash('success', 'Welcome back, ' . $_SESSION['display_name'] . '!');
                redirect(home_for($roles));
            }
        }
    }
}

$flashes = take_flash_messages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · <?= e(APP_NAME) ?></title>
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
                <li><?= icon('check') ?> Card swipes and installments</li>
                <li><?= icon('check') ?> Borrower obligations and payments</li>
                <li><?= icon('check') ?> Agreements, reports and audit trail</li>
            </ul>
        </div>
    </section>

    <section class="auth-card" aria-labelledby="login-title">
        <header class="auth-card__header">
            <div class="brand-mark"><?= icon('credit-card') ?></div>
            <h1 id="login-title">Sign in</h1>
            <p class="text-muted">Use your <?= e(APP_NAME) ?> account to continue.</p>
        </header>

        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert--<?= e($flash['type']) ?>" role="status">
                <?= icon($flash['type'] === 'success' ? 'check' : 'info') ?>
                <span><?= e($flash['message']) ?></span>
            </div>
        <?php endforeach; ?>

        <?php if ($errors): ?>
            <div class="alert alert--error" role="alert">
                <?= icon('alert') ?>
                <div>
                    <?php foreach ($errors as $error): ?>
                        <p><?= e($error) ?></p>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('auth/login.php')) ?>" class="auth-form" id="login-form" novalidate>
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="identifier">Username or email</label>
                <div class="input-icon">
                    <?= icon('user') ?>
                    <input type="text" id="identifier" name="identifier" maxlength="255"
                           value="<?= e($identifier) ?>" autocomplete="username"
                           required autofocus aria-describedby="identifier-error">
                </div>
                <p class="field-error" id="identifier-error" hidden></p>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-icon input-icon--action">
                    <?= icon('lock') ?>
                    <input type="password" id="password" name="password"
                           autocomplete="current-password" required aria-describedby="password-error">
                    <button type="button" class="input-action" id="toggle-password"
                            aria-label="Show password" aria-pressed="false">
                        <span class="when-hidden"><?= icon('eye') ?></span>
                        <span class="when-shown"><?= icon('eye-off') ?></span>
                    </button>
                </div>
                <p class="field-error" id="password-error" hidden></p>
            </div>

            <button type="submit" class="btn btn--primary btn--block" id="login-button" data-loading-text="Signing in…">
                <span class="btn__label">Sign in</span>
            </button>
        </form>

        <p class="auth-card__switch">New to <?= e(APP_NAME) ?>? <a href="<?= e(url('auth/register.php')) ?>">Create an account</a></p>

        <footer class="auth-card__footer">
            &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All Rights Reserved.
        </footer>
    </section>
</main>

<script src="<?= e(asset('assets/js/auth.js')) ?>"></script>
</body>
</html>
