<?php
/**
 * Cash Advances - view, mark completed or cancel (instead of delete).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/cash_advance_functions.php';

$currentUser = require_admin();

$advance = find_or_redirect(cash_advance_find(request_id()), 'pages/cash_advances/index.php', 'Cash advance');

if (is_post()) {
    require_csrf('pages/cash_advances/view.php?id=' . $advance['id']);
    $status = input_string($_POST, 'status');

    if ($advance['status'] === 'active' && in_array($status, ['completed', 'cancelled'], true)) {
        card_usage_apply([$advance['credit_card_id']], fn () => db_update('cash_advances', ['status' => $status], $advance['id']));
        log_activity('status_change', 'cash_advance', $advance['id'], 'Cash advance marked ' . $status, ['status' => $advance['status']], ['status' => $status]);
        flash('success', 'Cash advance marked as ' . humanize($status) . '.');
    } else {
        flash('error', 'That status change is not allowed.');
    }
    redirect('pages/cash_advances/view.php', ['id' => $advance['id']]);
}

$pageTitle = 'Cash Advance';
$activeNav = 'cash_advances';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Cash Advances' => url('pages/cash_advances/index.php'), format_date($advance['advance_date']) => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Cash Advance</h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e(format_money($advance['total_amount'])) ?></h2>
        <p class="page-header__subtitle"><?= e($advance['card_nickname']) ?> •••• <?= e($advance['last_four']) ?> · <?= e(format_date($advance['advance_date'])) ?> · <?= badge($advance['status']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/cash_advances/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <?php if ($advance['status'] === 'active'): ?>
            <?= post_button(url('pages/cash_advances/view.php', ['id' => $advance['id']]), ['status' => 'completed'], 'Mark completed', 'check', 'btn btn--outline') ?>
            <?= post_button(url('pages/cash_advances/view.php', ['id' => $advance['id']]), ['status' => 'cancelled'], 'Cancel', 'x', 'btn btn--outline',
                'Cancel this cash advance? It stays on record as cancelled and can no longer be edited.', false, 'Cancel cash advance?') ?>
        <?php endif; ?>
        <?php if ($advance['status'] !== 'cancelled'): ?>
            <a href="<?= e(url('pages/cash_advances/edit.php', ['id' => $advance['id']])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
        <?php endif; ?>
    </div>
</section>

<section class="panel">
    <header class="panel__header"><h3>Cash advance details</h3></header>
    <?= render_details([
        'Card'             => related_link($advance['credit_card_id'], $advance['card_nickname'] . ' •••• ' . $advance['last_four'], 'pages/credit_cards/view.php'),
        'Advance date'     => e(format_date($advance['advance_date'])),
        'Reference number' => show($advance['reference_number']),
        'Channel'          => show($advance['channel']),
        'Amount withdrawn' => e(format_money($advance['amount'])),
        'Fee'              => e(format_money($advance['fee'])),
        'Interest'         => e(format_money($advance['interest'])),
        'Total amount'     => '<strong>' . e(format_money($advance['total_amount'])) . '</strong>',
        'Statement'        => related_link($advance['statement_id'], $advance['statement_date'] ? 'Statement of ' . format_date($advance['statement_date']) : null, 'pages/statements/view.php'),
        'Status'           => badge($advance['status']),
        'Notes'            => show_multiline($advance['notes']),
        'Recorded'         => e(format_datetime($advance['created_at'])),
        'Last updated'     => e(format_datetime($advance['updated_at'])),
    ]) ?>
    <p class="field-help no-print">Cash advances are never deleted; cancel a mistaken entry instead.</p>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
