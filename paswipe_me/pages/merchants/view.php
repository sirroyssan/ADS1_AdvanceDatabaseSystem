<?php
/**
 * Merchants - view details and recent transactions.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/merchant_functions.php';

$currentUser = require_admin();

$merchant = find_or_redirect(merchant_find(request_id()), 'pages/merchants/index.php', 'Merchant');
$usage = merchant_usage($merchant['id']);

$transactions = db_all(
    'SELECT t.id, t.transaction_number, t.transaction_date, t.amount, t.status, b.first_name, b.last_name
       FROM transactions t LEFT JOIN borrowers b ON b.id = t.borrower_id
      WHERE t.merchant_id = :id
      ORDER BY t.transaction_date DESC LIMIT 10',
    [':id' => $merchant['id']]
);
$totalSpent = db_value("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE merchant_id = :id AND status NOT IN ('cancelled', 'refunded')", [':id' => $merchant['id']]);

$pageTitle = 'Merchant Details';
$activeNav = 'merchants';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Merchants' => url('pages/merchants/index.php'), merchant_label($merchant) => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Merchant Record</h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($merchant['merchant_name']) ?></h2>
        <p class="page-header__subtitle"><?= e($merchant['branch'] ?: 'Main') ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/merchants/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <a href="<?= e(url('pages/merchants/edit.php', ['id' => $merchant['id']])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
    </div>
</section>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Record details</h3></header>
        <?= render_details([
            'Merchant name'       => e($merchant['merchant_name']),
            'Branch'              => show($merchant['branch']),
            'Category'            => related_link($merchant['category_id'], $merchant['category_name'], 'pages/categories/view.php'),
            'Merchant type'       => show($merchant['merchant_type']),
            'Platform'            => show($merchant['platform']),
            'Website'             => $merchant['website'] ? '<a href="' . e($merchant['website']) . '" target="_blank" rel="noopener noreferrer">' . e($merchant['website']) . '</a>' : '',
            'Contact'             => show($merchant['contact']),
            'Address'             => show_multiline($merchant['address']),
            'Barangay'            => show($merchant['barangay']),
            'Municipality / City' => show($merchant['municipality_city']),
            'Province'            => show($merchant['province']),
            'Notes'               => show_multiline($merchant['notes']),
            'Created'             => e(format_datetime($merchant['created_at'])),
            'Last updated'        => e(format_datetime($merchant['updated_at'])),
        ]) ?>
    </section>

    <div class="detail-side">
        <section class="panel">
            <header class="panel__header"><h3>Usage</h3></header>
            <div class="figure-grid">
                <div class="figure"><p class="figure__label">Transactions</p><p class="figure__value"><?= e($usage['transactions']) ?></p></div>
                <div class="figure"><p class="figure__label">Total purchases</p><p class="figure__value"><?= e(format_money($totalSpent)) ?></p></div>
            </div>
        </section>

        <section class="panel panel--danger no-print">
            <header class="panel__header"><h3>Delete</h3></header>
            <?php if ($usage['total'] > 0): ?>
                <p class="field-help">This merchant is used by <?= e(usage_text($usage)) ?> and cannot be deleted. The merchants table has no status column, so it stays on record.</p>
            <?php else: ?>
                <p class="field-help">Deleting is permanent.</p>
                <?= post_button(url('pages/merchants/delete.php'), ['id' => $merchant['id']], 'Delete merchant', 'trash', 'btn btn--danger btn--block',
                    'Are you sure you want to delete the merchant "' . $merchant['merchant_name'] . '"? This cannot be undone.') ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<section class="panel panel--flush">
    <header class="panel__header panel__header--padded"><h3>Recent transactions</h3></header>
    <?php if (!$transactions): ?>
        <p class="empty-inline">No transactions with this merchant yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr><th scope="col">Transaction #</th><th scope="col">Date</th><th scope="col">Borrower</th><th scope="col" class="num">Amount</th><th scope="col">Status</th></tr></thead>
                <tbody>
                <?php foreach ($transactions as $t): ?>
                    <tr>
                        <td data-label="Transaction #"><a href="<?= e(url('pages/transactions/view.php', ['id' => $t['id']])) ?>"><?= e($t['transaction_number']) ?></a></td>
                        <td data-label="Date"><?= e(format_date($t['transaction_date'])) ?></td>
                        <td data-label="Borrower"><?= show(borrower_name($t)) ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($t['amount'])) ?></td>
                        <td data-label="Status"><?= badge($t['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
