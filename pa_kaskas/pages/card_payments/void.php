<?php
/**
 * Card Payments - void a payment (opened in a modal): the reason is required.
 * The payment leaves every total; a full copy stays in the voided list and
 * the activity log (card_payment_void()).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/card_payment_functions.php';

$currentUser = require_admin();

$payment = find_or_redirect(card_payment_find(request_id()), 'pages/card_payments/index.php', 'Card payment');
$back = 'pages/card_payments/view.php?id=' . $payment['id'];
$error = null;
$reason = '';

if (is_post()) {
    require_csrf($back);
    $reason = trim(mb_substr(input_string($_POST, 'reason'), 0, 1000));
    if (mb_strlen($reason) < 5) {
        $error = 'Enter the reason for voiding this payment (at least 5 characters).';
    } else {
        try {
            $error = card_payment_void($payment, $reason, $currentUser);
        } catch (PDOException $e) {
            $error = db_error_message($e);
        }
        if ($error === null) {
            flash('success', 'Card payment of ' . format_money($payment['amount']) . ' was voided. It no longer counts on the card; it is kept under Voided card payments.');
            redirect('pages/card_payments/index.php');
        }
    }
}

$pageTitle = 'Void card payment';
$activeNav = 'card_payments';
require APP_ROOT . '/includes/header.php';
?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Void card payment</h2>
        <p class="page-header__subtitle"><?= e(format_money($payment['amount'])) ?> · <?= e(format_date($payment['payment_date'])) ?> · <?= e($payment['card_nickname'] . ' •••• ' . $payment['last_four']) ?><?= $payment['reference_number'] ? ' · ref ' . e($payment['reference_number']) : '' ?></p>
    </div>
</section>

<form method="post" action="<?= e(url('pages/card_payments/void.php', ['id' => $payment['id']])) ?>" class="form-card" novalidate data-validate>
    <?= csrf_field() ?>
    <?php if ($error !== null): ?><div class="alert alert--error" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>
    <div class="form-group">
        <label for="f_void_reason">Reason for voiding <span class="required" aria-hidden="true">*</span></label>
        <textarea id="f_void_reason" name="reason" rows="4" maxlength="1000" required minlength="5" autofocus
                  placeholder="e.g. Entered twice; the bank shows one payment only"><?= e($reason) ?></textarea>
        <p class="field-help">The payment stops counting on the card at once (available credit and statements are recalculated). It is never erased: the full record, your reason, who and when are kept under Voided card payments and in the activity log.</p>
    </div>
    <div class="form-actions">
        <a href="<?= e(url($back)) ?>" class="btn btn--outline">Cancel</a>
        <button type="submit" class="btn btn--danger" data-loading-text="Voiding…"><?= icon('trash') ?> <span class="btn__label">Void payment</span></button>
    </div>
</form>

<?php require APP_ROOT . '/includes/footer.php'; ?>
