<?php
/**
 * Amounts to Repay - one card purchase assigned to the signed-in user:
 * amount, card and owner, repay-by date, penalty, repayment history.
 */

require_once __DIR__ . '/../../includes/auth_check.php';

[$currentUser, $layout] = borrowed_require_user();

$id = request_id();
$item = find_or_redirect(is_valid_uuid($id) ? (borrowed_my_purchases($id)[0] ?? null) : null, 'pages/borrowed/index.php', 'Borrowed purchase');
$l = $item['ledger'];
$repayments = borrowed_repayments($item['id']);

$pageTitle = 'Borrowed Purchase';
$activeNav = 'borrowed';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Amounts to Repay' => url('pages/borrowed/index.php'), $item['transaction_number'] => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($item['merchant_name'] ?: $item['transaction_number']) ?></h2>
        <p class="page-header__subtitle"><?= e(format_money($l['outstanding'])) ?> outstanding · repay by <?= e(borrowed_due_text($l)) ?> · <?= borrowed_status_badge($l['status']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/borrowed/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <?php if ($l['outstanding'] - $l['reported'] > 0.004): ?>
            <a data-modal-form href="<?= e(url('pages/borrowed/repay.php', ['id' => $item['id']])) ?>" class="btn btn--primary"><?= icon('wallet') ?> <span>Record repayment</span></a>
        <?php endif; ?>
    </div>
</section>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Purchase</h3></header>
        <?= render_details([
            'Description'      => show($item['notes'] ?: ($item['merchant_name'] ? 'Purchase at ' . $item['merchant_name'] : null)),
            'Transaction date' => e(format_date($item['transaction_date'])),
            'Credit card used' => e($item['issuer'] . ' · ' . $item['card_nickname'] . ' •••• ' . $item['last_four']),
            'Card owner'       => show($item['lender_name']),
            'Reference'        => e($item['transaction_number']),
        ]) ?>
        <h3 class="form-section">What you owe</h3>
        <?= borrowed_render_ledger($item, $l) ?>
    </section>

    <section class="panel">
        <header class="panel__header"><h3>Repayment history</h3></header>
        <?php if ($l['mode'] === 'obligation'): ?>
            <p class="field-help">This purchase is tracked as obligation <?= e($l['obligation']['obligation_number']) ?>; its repayments are listed with your payments.</p>
        <?php endif; ?>
        <?= borrowed_render_repayments($repayments, static fn ($r) => in_array($r['status'], PORTAL_CANCELLABLE_STATUSES, true)
            ? post_button(url('pages/borrowed/cancel.php'), ['id' => $r['id'], 'item' => $item['id']], 'Cancel', 'x', 'btn btn--sm btn--outline',
                'Cancel this repayment record? Use this if you entered it by mistake.', false, 'Cancel repayment?')
            : '') ?>
    </section>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
