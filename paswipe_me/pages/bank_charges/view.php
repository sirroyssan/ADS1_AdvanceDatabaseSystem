<?php
/**
 * Bank Charges - view a charge.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/bank_charge_functions.php';

$currentUser = require_admin();

$charge = find_or_redirect(bank_charge_find(request_id()), 'pages/bank_charges/index.php', 'Bank charge');

$pageTitle = 'Bank Charge';
$activeNav = 'bank_charges';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Bank Charges' => url('pages/bank_charges/index.php'), $charge['charge_type'] => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Bank Charge</h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($charge['charge_type']) ?> · <?= e(format_money($charge['amount'])) ?></h2>
        <p class="page-header__subtitle"><?= e($charge['card_nickname']) ?> •••• <?= e($charge['last_four']) ?> · <?= e(format_date($charge['charge_date'])) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/bank_charges/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <a data-modal-form href="<?= e(url('pages/bank_charges/edit.php', ['id' => $charge['id']])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
    </div>
</section>

<section class="panel">
    <header class="panel__header"><h3>Charge details</h3></header>
    <?= render_details([
        'Card'             => related_link($charge['credit_card_id'], $charge['card_nickname'] . ' •••• ' . $charge['last_four'], 'pages/credit_cards/view.php'),
        'Statement'        => related_link($charge['statement_id'], $charge['statement_date'] ? 'Statement of ' . format_date($charge['statement_date']) : null, 'pages/statements/view.php'),
        'Charge date'      => e(format_date($charge['charge_date'])),
        'Charge type'      => e($charge['charge_type']),
        'Amount'           => '<strong>' . e(format_money($charge['amount'])) . '</strong>',
        'Reference number' => show($charge['reference_number']),
        'Description'      => show_multiline($charge['description']),
        'Recorded'         => e(format_datetime($charge['created_at'])),
    ]) ?>
    <p class="field-help no-print">Bank charges are part of the financial history and cannot be deleted. Use Edit to correct a mistake.</p>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
