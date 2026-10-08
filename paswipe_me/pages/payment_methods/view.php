<?php
/**
 * Payment Methods - view details.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/payment_method_functions.php';

$currentUser = require_admin();

$method = find_or_redirect(payment_method_find(request_id()), 'pages/payment_methods/index.php', 'Payment method');
$usage = payment_method_usage($method['id']);

$pageTitle = 'Payment Method Details';
$activeNav = 'payment_methods';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Payment Methods' => url('pages/payment_methods/index.php'), $method['display_name'] => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Payment Method Record</h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($method['display_name']) ?></h2>
        <p class="page-header__subtitle"><?= e(strtoupper(humanize($method['method_type']))) ?> · <?= active_badge($method['is_active']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/payment_methods/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <a href="<?= e(url('pages/payment_methods/edit.php', ['id' => $method['id']])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
    </div>
</section>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Record details</h3></header>
        <?= render_details([
            'Display name'          => e($method['display_name']),
            'Method type'           => e(strtoupper(humanize($method['method_type']))),
            'Account name'          => show($method['account_name']),
            'Masked account number' => show($method['masked_account_number']),
            'Last four digits'      => show($method['last_four']),
            'Belongs to'            => $method['borrower_id']
                ? related_link($method['borrower_id'], borrower_name($method) . ' (' . $method['borrower_number'] . ')', 'pages/borrowers/view.php')
                : ($method['owner_name'] ? e($method['owner_name']) . ' (owner)' : ''),
            'Status'                => active_badge($method['is_active']),
            'Notes'                 => show_multiline($method['notes']),
            'Created'               => e(format_datetime($method['created_at'])),
            'Last updated'          => e(format_datetime($method['updated_at'])),
        ]) ?>
    </section>

    <div class="detail-side">
        <section class="panel">
            <header class="panel__header"><h3>Usage</h3></header>
            <div class="figure-grid">
                <div class="figure"><p class="figure__label">Borrower payments</p><p class="figure__value"><?= e($usage['borrower payments']) ?></p></div>
                <div class="figure"><p class="figure__label">Card payments</p><p class="figure__value"><?= e($usage['card payments']) ?></p></div>
            </div>
        </section>

        <section class="panel panel--danger no-print">
            <header class="panel__header"><h3>Delete or deactivate</h3></header>
            <?php if ($usage['total'] > 0): ?>
                <p class="field-help">Used by <?= e(usage_text($usage)) ?>, so it cannot be deleted. Deactivate it to hide it from new payments.</p>
                <?php if ((int) $method['is_active'] === 1): ?>
                    <?= post_button(url('pages/payment_methods/delete.php'), ['id' => $method['id'], 'action' => 'deactivate'], 'Deactivate', 'power', 'btn btn--warning btn--block',
                        'Deactivate "' . $method['display_name'] . '"? It stays linked to past payments.', false, 'Deactivate payment method?') ?>
                <?php endif; ?>
            <?php else: ?>
                <p class="field-help">Deleting is permanent.</p>
                <?= post_button(url('pages/payment_methods/delete.php'), ['id' => $method['id'], 'action' => 'delete'], 'Delete payment method', 'trash', 'btn btn--danger btn--block',
                    'Are you sure you want to delete "' . $method['display_name'] . '"? This cannot be undone.') ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
