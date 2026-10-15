<?php
/**
 * Persistent sidebar (included by header.php on every dashboard page).
 *
 * Every item links to a finished module. Pages set $activeNav to the item's
 * 'key' so the current module is highlighted.
 */

defined('APP_ROOT') or exit('No direct access.');

$navigation = [
    [
        'label' => null,
        'items' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'path' => 'pages/dashboard.php'],
            ['key' => 'inbox',     'label' => 'My Notifications', 'icon' => 'bell', 'path' => 'pages/notifications/inbox.php'],
            ['key' => 'borrowed',  'label' => 'Amounts to Repay', 'icon' => 'wallet', 'path' => 'pages/borrowed/index.php', 'borrowed' => true],
        ],
    ],
    [
        'label' => 'Master Data',
        'items' => [
            ['key' => 'categories',      'label' => 'Categories',      'icon' => 'tag',    'path' => 'pages/categories/index.php'],
            ['key' => 'merchants',       'label' => 'Merchants',       'icon' => 'store',  'path' => 'pages/merchants/index.php'],
            ['key' => 'payment_methods', 'label' => 'Payment Methods', 'icon' => 'wallet', 'path' => 'pages/payment_methods/index.php'],
            ['key' => 'penalty_rules',   'label' => 'Penalty Rules',   'icon' => 'scale',  'path' => 'pages/penalty_rules/index.php'],
            ['key' => 'installment_rules', 'label' => 'Installment Rules', 'icon' => 'layers', 'path' => 'pages/installment_rules/index.php'],
        ],
    ],
    [
        'label' => 'Borrowers',
        'items' => [
            ['key' => 'borrowers',         'label' => 'Borrowers',         'icon' => 'users',     'path' => 'pages/borrowers/index.php'],
            ['key' => 'obligations',       'label' => 'Obligations',       'icon' => 'file-text', 'path' => 'pages/obligations/index.php'],
            ['key' => 'borrower_payments', 'label' => 'Borrower Payments', 'icon' => 'receipt',   'path' => 'pages/borrower_payments/index.php'],
            ['key' => 'late_charges',      'label' => 'Late & Finance Charges', 'icon' => 'alert', 'path' => 'pages/late_charges/index.php'],
            ['key' => 'staff_receive',     'label' => 'Receive Payment',   'icon' => 'wallet',    'path' => 'pages/staff/receive.php'],
            ['key' => 'staff_receipts',    'label' => 'Receipts',          'icon' => 'file-text', 'path' => 'pages/staff/receipts.php'],
        ],
    ],
    [
        'label' => 'Credit Cards',
        'items' => [
            ['key' => 'credit_cards',  'label' => 'Credit Cards',  'icon' => 'credit-card', 'path' => 'pages/credit_cards/index.php'],
            ['key' => 'statements',    'label' => 'Statements',    'icon' => 'calendar',    'path' => 'pages/statements/index.php'],
            ['key' => 'card_payments', 'label' => 'Card Payments', 'icon' => 'banknote',    'path' => 'pages/card_payments/index.php'],
            ['key' => 'bank_charges',  'label' => 'Bank Charges',  'icon' => 'receipt',     'path' => 'pages/bank_charges/index.php'],
        ],
    ],
    [
        'label' => 'Transactions',
        'items' => [
            ['key' => 'transactions',  'label' => 'Transactions',      'icon' => 'layers',   'path' => 'pages/transactions/index.php'],
            ['key' => 'installments',  'label' => 'Installment Plans', 'icon' => 'repeat',   'path' => 'pages/installment_plans/index.php'],
            ['key' => 'cash_loans',    'label' => 'Cash Loans',        'icon' => 'banknote', 'path' => 'pages/cash_loans/index.php'],
            ['key' => 'cash_advances', 'label' => 'Cash Advances',     'icon' => 'wallet',   'path' => 'pages/cash_advances/index.php'],
        ],
    ],
    [
        'label' => 'Documents',
        'items' => [
            ['key' => 'agreements',          'label' => 'Agreements',          'icon' => 'file-sign',  'path' => 'pages/agreements/index.php'],
            ['key' => 'demand_letters',      'label' => 'Demand Letters',      'icon' => 'mail-alert', 'path' => 'pages/demand_letters/index.php'],
            ['key' => 'documents',           'label' => 'Document Files',      'icon' => 'folder',     'path' => 'pages/documents/index.php'],
            ['key' => 'agreement_templates', 'label' => 'Agreement Templates', 'icon' => 'file-text',  'path' => 'pages/agreement_templates/index.php'],
            ['key' => 'letter_templates',    'label' => 'Letter Templates',    'icon' => 'mail-alert', 'path' => 'pages/letter_templates/index.php'],
        ],
    ],
    [
        'label' => 'Reports',
        'items' => [
            ['key' => 'reports', 'label' => 'Reports', 'icon' => 'chart', 'path' => 'pages/reports/index.php'],
        ],
    ],
    [
        'label' => 'System',
        'items' => [
            ['key' => 'users',         'label' => 'Users & Roles', 'icon' => 'shield',  'path' => 'pages/users/index.php', 'admin' => true],
            ['key' => 'approvals',     'label' => 'Approvals',     'icon' => 'check',   'path' => 'pages/users/approvals.php', 'admin' => true, 'badge' => 'approvals'],
            ['key' => 'notifications', 'label' => 'Sent Notifications', 'icon' => 'bell',    'path' => 'pages/notifications/index.php'],
            ['key' => 'activity_logs', 'label' => 'Activity Logs', 'icon' => 'history', 'path' => 'pages/activity_logs/index.php'],
            ['key' => 'google_drive',  'label' => 'Google Drive',  'icon' => 'folder',  'path' => 'pages/drive/index.php'],
            ['key' => 'staff_accounts', 'label' => 'Staff Accounts', 'icon' => 'users', 'path' => 'pages/staff_accounts/index.php'],
            ['key' => 'authorizations', 'label' => 'Authorization Requests', 'icon' => 'shield', 'path' => 'pages/authorizations/index.php'],
            ['key' => 'data_reset',    'label' => 'Reset Test Data', 'icon' => 'trash', 'path' => 'pages/data_reset/index.php'],
            ['key' => 'profile',       'label' => 'My Profile',    'icon' => 'user',    'path' => 'pages/profile/index.php'],
        ],
    ],
];

// Account management is for system administrators only.
if (!in_array('admin', (array) ($_SESSION['user_roles'] ?? []), true)) {
    foreach ($navigation as &$group) {
        $group['items'] = array_values(array_filter($group['items'], static fn ($item) => empty($item['admin'])));
    }
    unset($group);
}

// Borrowers see only their own account pages.
if (($layout ?? 'admin') === 'portal') {
    $navigation = [
        [
            'label' => null,
            'items' => [
                ['key' => 'portal_home', 'label' => 'My Account', 'icon' => 'dashboard', 'path' => 'portal/index.php'],
            ],
        ],
        [
            'label' => 'My Balance',
            'items' => [
                ['key' => 'portal_obligations', 'label' => 'What I Owe',     'icon' => 'file-text', 'path' => 'portal/obligations.php'],
                ['key' => 'borrowed',           'label' => 'Card Purchases', 'icon' => 'credit-card', 'path' => 'pages/borrowed/index.php', 'borrowed' => true],
                ['key' => 'portal_penalties',   'label' => 'Late Charges',   'icon' => 'alert',     'path' => 'portal/penalties.php'],
                ['key' => 'portal_payments',    'label' => 'My Payments',    'icon' => 'receipt',   'path' => 'portal/payments.php'],
                ['key' => 'portal_pay',         'label' => 'Submit Payment', 'icon' => 'wallet',    'path' => 'portal/pay.php'],
            ],
        ],
        [
            'label' => 'Documents',
            'items' => [
                ['key' => 'portal_agreements', 'label' => 'Agreements', 'icon' => 'file-sign',  'path' => 'portal/agreements.php'],
                ['key' => 'portal_letters',    'label' => 'Notices',    'icon' => 'mail-alert', 'path' => 'portal/letters.php'],
            ],
        ],
        [
            'label' => 'Account',
            'items' => [
                ['key' => 'portal_notifications', 'label' => 'Notifications', 'icon' => 'bell', 'path' => 'portal/notifications.php'],
                ['key' => 'profile',              'label' => 'My Profile',    'icon' => 'user', 'path' => 'pages/profile/index.php'],
            ],
        ],
    ];
}

// Staff keep the owner's books: only the staff pages (each one is also checked on the server).
if (($layout ?? 'admin') !== 'portal' && !array_intersect(ADMIN_ROLES, (array) ($_SESSION['user_roles'] ?? []))
    && in_array('staff', (array) ($_SESSION['user_roles'] ?? []), true)) {
    $navigation = [
        [
            'label' => null,
            'items' => [
                ['key' => 'staff_home', 'label' => 'Staff Dashboard', 'icon' => 'dashboard', 'path' => 'pages/staff/index.php'],
                ['key' => 'inbox',      'label' => 'My Notifications', 'icon' => 'bell', 'path' => 'pages/notifications/inbox.php'],
            ],
        ],
        [
            'label' => 'Daily Work',
            'items' => [
                ['key' => 'transactions',    'label' => 'Transactions',    'icon' => 'layers',    'path' => 'pages/transactions/index.php'],
                ['key' => 'staff_receive',   'label' => 'Receive Payment', 'icon' => 'wallet',    'path' => 'pages/staff/receive.php'],
                ['key' => 'staff_receipts',  'label' => 'Receipts',        'icon' => 'file-text', 'path' => 'pages/staff/receipts.php'],
                ['key' => 'staff_due_dates', 'label' => 'Card Due Dates',  'icon' => 'calendar',  'path' => 'pages/staff/due_dates.php'],
            ],
        ],
        [
            'label' => 'Records',
            'items' => [
                ['key' => 'credit_cards',   'label' => 'Credit Cards',           'icon' => 'credit-card', 'path' => 'pages/credit_cards/index.php'],
                ['key' => 'installments',   'label' => 'Installment Plans',      'icon' => 'repeat',      'path' => 'pages/installment_plans/index.php'],
                ['key' => 'authorizations', 'label' => 'My Change Requests',     'icon' => 'shield',      'path' => 'pages/authorizations/index.php'],
            ],
        ],
        [
            'label' => 'Account',
            'items' => [
                ['key' => 'profile', 'label' => 'My Profile', 'icon' => 'user', 'path' => 'pages/profile/index.php'],
            ],
        ],
    ];
}

// "Amounts to Repay" only for users who have card purchases assigned to them.
if (!borrowed_has_items()) {
    foreach ($navigation as &$group) {
        $group['items'] = array_values(array_filter($group['items'], static fn ($item) => empty($item['borrowed'])));
    }
    unset($group);
}

?>
<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="sidebar__brand">
        <span class="brand-mark"><?= icon('credit-card') ?></span>
        <span class="sidebar__brand-text">
            <strong><?= e(APP_NAME) ?></strong>
            <small><?= ($layout ?? 'admin') === 'portal' ? 'Borrower Portal'
                : (in_array('staff', (array) ($_SESSION['user_roles'] ?? []), true) && !array_intersect(ADMIN_ROLES, (array) ($_SESSION['user_roles'] ?? [])) ? 'Staff Panel' : 'Admin Panel') ?></small>
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
                    <?php foreach ($group['items'] as $navItem): ?>
                        <?php $isActive = $navItem['key'] === $activeNav; ?>
                        <li>
                            <a href="<?= e(url($navItem['path'])) ?>"
                               class="nav-link<?= $isActive ? ' is-active' : '' ?>"
                               <?= $isActive ? 'aria-current="page"' : '' ?>
                               title="<?= e($navItem['label']) ?>">
                                <?= icon($navItem['icon']) ?>
                                <span class="nav-link__label"><?= e($navItem['label']) ?></span>
                                <?php if (($navItem['badge'] ?? null) === 'approvals' && ($waiting = approvals_waiting()) > 0): ?>
                                    <span class="nav-link__badge" title="<?= e($waiting) ?> waiting"><?= e($waiting) ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </nav>
    <script>
        // Keep the menu where it was when moving to another module (restored before paint, so it does not jump to the top),
        // and make sure the current module is in view (e.g. opened from a link on a page).
        (function () {
            var nav = document.currentScript.previousElementSibling, key = 'sidebar-scroll', saved = null;
            try { saved = sessionStorage.getItem(key); } catch (e) {}
            var place = function () {
                if (saved !== null) {
                    nav.scrollTop = parseInt(saved, 10) || 0;
                }
                var active = nav.querySelector('.nav-link.is-active');
                if (active) {
                    var box = nav.getBoundingClientRect(), item = active.getBoundingClientRect();
                    if (item.top < box.top || item.bottom > box.bottom) {
                        nav.scrollTop += item.top - box.top - box.height / 3;
                    }
                }
            };
            place();
            window.addEventListener('load', place);   // again once icons and fonts have their final size
            var save = function () { try { sessionStorage.setItem(key, String(nav.scrollTop)); } catch (e) {} };
            nav.addEventListener('click', save);
            window.addEventListener('pagehide', save);
        })();
    </script>

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
