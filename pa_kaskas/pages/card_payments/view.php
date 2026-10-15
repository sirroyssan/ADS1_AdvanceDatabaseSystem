<?php
/**
 * Card Payments - view a payment.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/card_payment_functions.php';

$currentUser = require_admin();

$payment = find_or_redirect(card_payment_find(request_id()), 'pages/card_payments/index.php', 'Card payment');

$pageTitle = 'Card Payment';
$activeNav = 'card_payments';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Card Payments' => url('pages/card_payments/index.php'), format_date($payment['payment_date']) => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Credit Card Payment</h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e(format_money($payment['amount'])) ?></h2>
        <p class="page-header__subtitle"><?= e($payment['card_nickname']) ?> •••• <?= e($payment['last_four']) ?> · <?= e(format_date($payment['payment_date'])) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/card_payments/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <a data-modal-form href="<?= e(url('pages/card_payments/edit.php', ['id' => $payment['id']])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
    </div>
</section>

<section class="panel">
    <header class="panel__header"><h3>Payment details</h3></header>
    <?= render_details([
        'Card'                    => related_link($payment['credit_card_id'], $payment['card_nickname'] . ' •••• ' . $payment['last_four'], 'pages/credit_cards/view.php'),
        'Statement'               => related_link($payment['statement_id'], $payment['statement_date'] ? 'Statement of ' . format_date($payment['statement_date']) : null, 'pages/statements/view.php'),
        'Payment date'            => e(format_date($payment['payment_date'])),
        'Amount'                  => '<strong>' . e(format_money($payment['amount'])) . '</strong>',
        'Payment type'            => show($payment['payment_type']),
        'Paid from'               => related_link($payment['payment_method_id'], $payment['method_name'], 'pages/payment_methods/view.php'),
        'Channel'                 => show($payment['payment_channel']),
        'Source account (masked)' => show($payment['source_account_masked']),
        'Reference number'        => show($payment['reference_number']),
        'Receipt file'            => related_link($payment['receipt_document_id'], $payment['original_filename'], 'pages/documents/view.php'),
        'Notes'                   => show_multiline($payment['notes']),
        'Recorded'                => e(format_datetime($payment['created_at'])),
        'Last updated'            => e(format_datetime($payment['updated_at'])),
    ]) ?>
    <p class="field-help no-print">Payments to the bank are part of the financial history and cannot be deleted. Use Edit to correct a mistake.</p>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
