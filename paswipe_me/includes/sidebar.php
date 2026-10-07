<?php
/**
 * Persistent sidebar (included by header.php on every dashboard page).
 *
 * Menu groups follow the tables in the PA-SWIPE AKO schema.
 * Items with 'path' => null are planned modules: shown disabled, no fake pages.
 * To publish a new module, set its 'path' to the module's index page.
 */

defined('APP_ROOT') or exit('No direct access.');

$navigation = [
    [
        'label' => null,
        'items' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'path' => 'pages/dashboard.php'],
        ],
    ],
    [
        'label' => 'Master Data',
        'items' => [
            ['key' => 'categories',      'label' => 'Categories',      'icon' => 'tag',    'path' => 'pages/categories/index.php'],
            ['key' => 'merchants',       'label' => 'Merchants',       'icon' => 'store',  'path' => null],
            ['key' => 'payment_methods', 'label' => 'Payment Methods', 'icon' => 'wallet', 'path' => null],
            ['key' => 'penalty_rules',   'label' => 'Penalty Rules',   'icon' => 'scale',  'path' => null],
        ],
    ],
    [
        'label' => 'Borrowers',
        'items' => [
            ['key' => 'borrowers',         'label' => 'Borrowers',         'icon' => 'users',     'path' => null],
            ['key' => 'obligations',       'label' => 'Obligations',       'icon' => 'file-text', 'path' => null],
            ['key' => 'borrower_payments', 'label' => 'Borrower Payments', 'icon' => 'receipt',   'path' => null],
        ],
    ],
    [
        'label' => 'Credit Cards',
        'items' => [
            ['key' => 'credit_cards', 'label' => 'Credit Cards',       'icon' => 'credit-card', 'path' => null],
            ['key' => 'statements',   'label' => 'Statements',         'icon' => 'calendar',    'path' => null],
            ['key' => 'card_payments','label' => 'Card Payments & Charges', 'icon' => 'banknote', 'path' => null],
        ],
    ],
    [
        'label' => 'Transactions',
        'items' => [
            ['key' => 'transactions', 'label' => 'Transactions',       'icon' => 'layers', 'path' => null],
            ['key' => 'installments', 'label' => 'Installment Plans',  'icon' => 'repeat', 'path' => null],
            ['key' => 'cash_loans',   'label' => 'Cash Loans & Advances', 'icon' => 'banknote', 'path' => null],
        ],
    ],
    [
        'label' => 'Documents',
        'items' => [
            ['key' => 'agreements',     'label' => 'Agreements',     'icon' => 'file-sign',  'path' => null],
            ['key' => 'demand_letters', 'label' => 'Demand Letters', 'icon' => 'mail-alert', 'path' => null],
            ['key' => 'documents',      'label' => 'Document Files', 'icon' => 'folder',     'path' => null],
        ],
    ],
    [
        'label' => 'Reports',
        'items' => [
            ['key' => 'reports', 'label' => 'Reports', 'icon' => 'chart', 'path' => null],
        ],
    ],
    [
        'label' => 'System',
        'items' => [
            ['key' => 'users',         'label' => 'Users & Roles', 'icon' => 'shield',  'path' => null],
            ['key' => 'activity_logs', 'label' => 'Activity Logs', 'icon' => 'history', 'path' => null],
        ],
    ],
];
?>
<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="sidebar__brand">
        <span class="brand-mark"><?= icon('credit-card') ?></span>
        <span class="sidebar__brand-text">
            <strong><?= e(APP_NAME) ?></strong>
            <small>Admin Panel</small>
        </span>
        <button type="button" class="icon-button sidebar__close" data-sidebar-close aria-label="Close navigation">
            <?= icon('x') ?>
        </button>
    </div>

    <nav class="sidebar__nav">
        <?php foreach ($navigation as $group): ?>
            <div class="nav-group">
                <?php if ($group['label'] !== null): ?>
                    <p class="nav-group__label"><?= e($group['label']) ?></p>
                <?php endif; ?>
                <ul>
                    <?php foreach ($group['items'] as $item): ?>
                        <?php $isActive = $item['key'] === $activeNav; ?>
                        <li>
                            <?php if ($item['path'] !== null): ?>
                                <a href="<?= e(url($item['path'])) ?>"
                                   class="nav-link<?= $isActive ? ' is-active' : '' ?>"
                                   <?= $isActive ? 'aria-current="page"' : '' ?>
                                   title="<?= e($item['label']) ?>">
                                    <?= icon($item['icon']) ?>
                                    <span class="nav-link__label"><?= e($item['label']) ?></span>
                                </a>
                            <?php else: ?>
                                <span class="nav-link is-disabled" aria-disabled="true"
                                      title="<?= e($item['label']) ?> (planned)">
                                    <?= icon($item['icon']) ?>
                                    <span class="nav-link__label"><?= e($item['label']) ?></span>
                                    <span class="nav-link__badge">Soon</span>
                                </span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar__footer">
        <form method="post" action="<?= e(url('auth/logout.php')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="nav-link nav-link--button" title="Sign out">
                <?= icon('logout') ?>
                <span class="nav-link__label">Sign out</span>
            </button>
        </form>
    </div>
</aside>
