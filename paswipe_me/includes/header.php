<?php
/**
 * Dashboard layout - part 1 of 3 (header.php, sidebar.php, footer.php).
 *
 * Variables a page sets before including this file:
 *   $pageTitle  (string)   - shown in <title> and the top bar
 *   $activeNav  (string)   - key of the sidebar item to highlight
 *   $pageStyles (string[]) - extra CSS files, e.g. ['assets/css/categories.css']
 *   $currentUser (array)   - returned by require_admin() / require_borrower()
 *   $layout     (string)   - 'admin' (default) or 'portal' (borrower pages)
 */

defined('APP_ROOT') or exit('No direct access.');

$pageTitle   = $pageTitle ?? 'Dashboard';
$activeNav   = $activeNav ?? '';
$pageStyles  = $pageStyles ?? [];
$currentUser = $currentUser ?? current_user();
$layout      = $layout ?? 'admin';
$isPortal    = $layout === 'portal';

// Unread in-app notifications for the signed-in user (notifications table).
$unreadNotifications = 0;
try {
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM notifications
          WHERE user_id = :id AND channel = 'in_app' AND read_at IS NULL
            AND status NOT IN ('cancelled', 'failed')"
    );
    $stmt->execute([':id' => $currentUser['id']]);
    $unreadNotifications = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('[' . APP_NAME . '] Notification count failed: ' . $e->getMessage());
}

$initials = strtoupper(mb_substr(user_display_name($currentUser), 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/base.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/dashboard.css')) ?>">
    <?php foreach ($pageStyles as $style): ?>
        <link rel="stylesheet" href="<?= e(asset($style)) ?>">
    <?php endforeach; ?>
    <script>
        // Apply the saved collapsed-sidebar state before paint to avoid a flicker.
        try { if (localStorage.getItem('sidebar-collapsed') === '1') document.documentElement.classList.add('sidebar-collapsed'); } catch (e) {}
    </script>
</head>
<body class="app">
<a class="skip-link" href="#main-content">Skip to main content</a>

<div class="app-layout">
    <?php require APP_ROOT . '/includes/sidebar.php'; ?>

    <div class="sidebar-backdrop" data-sidebar-close hidden></div>

    <div class="app-main">
        <header class="topbar no-print">
            <div class="topbar__left">
                <button type="button" class="icon-button topbar__menu" data-sidebar-toggle
                        aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false">
                    <?= icon('menu') ?>
                </button>
                <button type="button" class="icon-button topbar__collapse" data-sidebar-collapse
                        aria-label="Collapse sidebar" aria-pressed="false">
                    <?= icon('collapse') ?>
                </button>
                <div class="topbar__title">
                    <span class="topbar__system"><?= e(APP_NAME) ?></span>
                    <h1 class="topbar__page"><?= e($pageTitle) ?></h1>
                </div>
            </div>

            <div class="topbar__right">
                <a class="icon-button notification-bell" href="<?= e($isPortal ? url('portal/notifications.php') : url('pages/notifications/index.php', ['mine' => 'unread'])) ?>"
                   aria-label="<?= e($unreadNotifications) ?> unread notifications"
                   title="<?= e($unreadNotifications) ?> unread notification(s)">
                    <?= icon('bell') ?>
                    <?php if ($unreadNotifications > 0): ?>
                        <span class="badge-dot"><?= e($unreadNotifications > 99 ? '99+' : $unreadNotifications) ?></span>
                    <?php endif; ?>
                </a>

                <div class="profile-menu" data-dropdown>
                    <button type="button" class="profile-menu__button" data-dropdown-toggle
                            aria-haspopup="true" aria-expanded="false">
                        <span class="avatar" aria-hidden="true"><?= e($initials) ?></span>
                        <span class="profile-menu__text">
                            <span class="profile-menu__name"><?= e(user_display_name($currentUser)) ?></span>
                            <span class="profile-menu__role"><?= e(role_label($currentUser['role'])) ?></span>
                        </span>
                        <?= icon('chevron-down', 'icon icon--sm') ?>
                    </button>

                    <div class="dropdown" data-dropdown-menu hidden>
                        <div class="dropdown__header">
                            <strong><?= e(user_display_name($currentUser)) ?></strong>
                            <span><?= e($currentUser['email']) ?></span>
                            <?php if (!empty($currentUser['username'])): ?>
                                <span>@<?= e($currentUser['username']) ?></span>
                            <?php endif; ?>
                            <span class="dropdown__roles">
                                <?php foreach ($currentUser['roles'] as $role): ?>
                                    <span class="chip"><?= e(role_label($role)) ?></span>
                                <?php endforeach; ?>
                            </span>
                        </div>
                        <a class="dropdown__item" href="<?= e(url('pages/profile/index.php')) ?>"><?= icon('user') ?> <span>My profile</span></a>
                        <a class="dropdown__item" href="<?= e(url('pages/profile/password.php')) ?>"><?= icon('lock') ?> <span>Change password</span></a>
                        <form method="post" action="<?= e(url('auth/logout.php')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="dropdown__item dropdown__item--danger">
                                <?= icon('logout') ?> <span>Sign out</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="content" id="main-content" tabindex="-1">
            <div class="toast-stack no-print" aria-live="polite">
                <?php foreach (take_flash_messages() as $flash): ?>
                    <div class="alert alert--<?= e($flash['type']) ?> alert--dismissible" role="status" data-alert>
                        <?= icon(match ($flash['type']) { 'success' => 'check', 'error' => 'alert', default => 'info' }) ?>
                        <span><?= e($flash['message']) ?></span>
                        <button type="button" class="alert__close" data-alert-close aria-label="Dismiss message">
                            <?= icon('x', 'icon icon--sm') ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
