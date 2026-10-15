<?php
/**
 * Reports - list of available reports. Reports are read-only: they offer
 * search, filters, date ranges and print, never create/edit/delete.
 */

require_once __DIR__ . '/../../includes/auth_check.php';

$currentUser = require_admin();

$reports = [
    ['borrower_balances.php', 'users',       'Borrower Balances',          'Charged, paid, outstanding and overdue per borrower (view vw_borrower_balances).'],
    ['overdue.php',           'alert',       'Overdue Obligations',        'Obligations past their due date with days overdue (view vw_overdue_obligations).'],
    ['pending_payments.php',  'receipt',     'Pending Payment Approvals',  'Borrower payments waiting for review (view vw_pending_payment_approvals).'],
    ['collections.php',       'banknote',    'Collections',                'Approved borrower payments by date range and payment method.'],
    ['transactions.php',      'layers',      'Transactions',               'Card swipes by date range, card, borrower and payment type with totals.'],
    ['card_utilization.php',  'credit-card', 'Credit Card Utilization',    'Limit, used credit (from transactions, cash loans, advances, charges and payments) and utilization of active cards.'],
];

$pageTitle = 'Reports';
$activeNav = 'reports';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Reports' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Reports</h2>
        <p class="page-header__subtitle">Every report can be filtered and printed with the current filters.</p>
    </div>
</section>

<section class="report-grid" aria-label="Available reports">
    <?php foreach ($reports as [$file, $iconName, $title, $description]): ?>
        <a class="report-card" href="<?= e(url('pages/reports/' . $file)) ?>">
            <?= icon($iconName, 'icon icon--xl') ?>
            <strong><?= e($title) ?></strong>
            <span class="text-muted"><?= e($description) ?></span>
        </a>
    <?php endforeach; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
