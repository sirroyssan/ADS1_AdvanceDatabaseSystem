<?php
/**
 * Borrower portal - read one notice and acknowledge it (POST: sent -> acknowledged).
 */

require_once __DIR__ . '/../includes/auth_check.php';

[$currentUser, $borrower] = require_borrower();

$sql = 'SELECT * FROM demand_letters WHERE id = :id AND borrower_id = :b AND status IN (' . sql_in_list(PORTAL_LETTER_STATUSES) . ')';
$letter = portal_find($sql, is_post() ? input_string($_POST, 'id') : input_string($_GET, 'id'), $borrower['id'], 'portal/letters.php', 'Notice');

if (is_post()) {
    require_csrf('portal/letter.php?id=' . $letter['id']);
    $count = db_exec(
        "UPDATE demand_letters SET status = 'acknowledged', acknowledged_at = NOW() WHERE id = :id AND borrower_id = :b AND status = 'sent'",
        [':id' => $letter['id'], ':b' => $borrower['id']]
    );
    if ($count) {
        log_activity('acknowledge', 'demand_letter', $letter['id'], 'Borrower acknowledged ' . $letter['letter_number']);
        notify_admins('letter_acknowledged', 'Notice acknowledged: ' . $letter['letter_number'],
            borrower_name($borrower) . ' acknowledged notice ' . $letter['letter_number'] . '.', 'demand_letter', $letter['id']);
        flash('success', 'Thank you. The owner can see that you received this notice.');
    }
    redirect('portal/letter.php', ['id' => $letter['id']]);
}

$pageTitle = 'Notice';
$activeNav = 'portal_letters';
$layout = 'portal';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Notices' => url('portal/letters.php'), $letter['letter_number'] => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Notice <?= e($letter['letter_number']) ?></h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($letter['letter_number']) ?></h2>
        <p class="page-header__subtitle"><?= e(format_date($letter['generated_date'])) ?> · <?= badge($letter['status']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('portal/letters.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <?php if ($letter['status'] === 'sent'): ?>
            <?= post_button(url('portal/letter.php'), ['id' => $letter['id']], 'I have read this notice', 'check', 'btn btn--primary') ?>
        <?php endif; ?>
    </div>
</section>

<section class="figure-grid">
    <div class="figure"><p class="figure__label">Original amount</p><p class="figure__value"><?= e(format_money($letter['original_amount'])) ?></p></div>
    <div class="figure"><p class="figure__label">Paid</p><p class="figure__value"><?= e(format_money($letter['total_paid'])) ?></p></div>
    <div class="figure"><p class="figure__label">Remaining balance</p><p class="figure__value"><?= e(format_money($letter['remaining_balance'])) ?></p></div>
</section>

<section class="panel">
    <header class="panel__header"><h3>Notice</h3>
        <?php if ($letter['document_id']): ?>
            <a class="btn btn--sm btn--outline no-print" href="<?= e(url('portal/download.php', ['id' => $letter['document_id'], 'inline' => '1'])) ?>" target="_blank" rel="noopener"><?= icon('file-text') ?> <span>Open file</span></a>
        <?php endif; ?>
    </header>
    <div class="document-body"><?= e($letter['content']) ?></div>
    <?php if ($letter['acknowledged_at']): ?>
        <p class="field-help">You acknowledged this notice on <?= e(format_datetime($letter['acknowledged_at'])) ?>.</p>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
