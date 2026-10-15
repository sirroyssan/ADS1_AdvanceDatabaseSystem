<?php
/**
 * Shown when a signed-in user lacks the role for a page (HTTP 403),
 * and as the landing page for roles whose pages are not built yet.
 */

require_once __DIR__ . '/../includes/auth_check.php';

$user = require_login();
$isAdmin = user_is_admin($user);
$flashes = take_flash_messages();

if (http_response_code() === 200 && $isAdmin) {
    // Admins opening this page directly have nothing to see here.
    redirect('pages/dashboard.php');
}
if (http_response_code() === 200 && in_array('borrower', $user['roles'], true)) {
    redirect('portal/index.php');
}
$isBorrower = in_array('borrower', $user['roles'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?= favicon_tag() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access denied · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/base.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/auth.css')) ?>">
</head>
<body class="auth-page">

<main class="auth-shell auth-shell--single">
    <section class="auth-card auth-card--center" aria-labelledby="denied-title">
        <div class="status-icon status-icon--danger"><?= icon('shield', 'icon icon--xl') ?></div>

        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert--<?= e($flash['type']) ?>" role="status">
                <span><?= e($flash['message']) ?></span>
            </div>
        <?php endforeach; ?>

        <?php if (isset($deniedTitle)): ?>
            <h1 id="denied-title"><?= e($deniedTitle) ?></h1>
            <p class="text-muted"><?= e($deniedMessage ?? '') ?></p>
        <?php elseif (http_response_code() === 403): ?>
            <h1 id="denied-title">Access denied</h1>
            <p class="text-muted">
                Your account (<?= e(role_label($user['role'])) ?>) does not have permission to open this page.
            </p>
        <?php else: ?>
            <h1 id="denied-title">Signed in as <?= e(user_display_name($user)) ?></h1>
            <p class="text-muted">
                The <?= e(role_label($user['role'])) ?> portal is not available yet.
                Only administrators can use the system in this version.
            </p>
        <?php endif; ?>

        <div class="auth-actions">
            <?php if ($isAdmin): ?>
                <a class="btn btn--primary" href="<?= e(url('pages/dashboard.php')) ?>">
                    <?= icon('dashboard') ?> <span>Back to dashboard</span>
                </a>
            <?php elseif ($isBorrower && !isset($deniedTitle)): ?>
                <a class="btn btn--primary" href="<?= e(url('portal/index.php')) ?>">
                    <?= icon('dashboard') ?> <span>Back to my account</span>
                </a>
            <?php endif; ?>
            <form method="post" action="<?= e(url('auth/logout.php')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--outline"><?= icon('logout') ?> <span>Sign out</span></button>
            </form>
        </div>
    </section>
</main>

</body>
</html>
