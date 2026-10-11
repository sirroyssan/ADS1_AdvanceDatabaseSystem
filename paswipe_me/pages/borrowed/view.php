<?php
/**
 * Amounts to Repay - one borrowed purchase: amount, due date, repayment history.
 */

require_once __DIR__ . '/../../includes/auth_check.php';

$currentUser = require_admin();

$id = request_id();
$item = is_valid_uuid($id) ? (borrowed_items($id)[0] ?? null) : null;
$item = find_or_redirect($item, 'pages/borrowed/index.php', 'Borrowed purchase');
$repayments = borrowed_repayments($item['id']);
$canRepay = $item['repay_status'] !== 'paid' && (float) $item['outstanding_amount'] - (float) $item['pending'] > 0.004;

$pageTitle = 'Borrowed Purchase';
$activeNav = 'borrowed';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Amounts to Repay' => url('pages/borrowed/index.php'), $item['obligation_number'] => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($item['merchant_name'] ?: ($item['description'] ?: $item['obligation_number'])) ?></h2>
        <p class="page-header__subtitle"><?= e(format_money($item['outstanding_amount'])) ?> left to repay<?= $item['due_date'] ? ' · due ' . e(format_date($item['due_date'])) : '' ?> · <?= borrowed_status_badge($item['repay_status']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/borrowed/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <?php if ($canRepay): ?>
            <a data-modal-form href="<?= e(url('pages/borrowed/repay.php', ['id' => $item['id']])) ?>" class="btn btn--primary"><?= icon('wallet') ?> <span>Record repayment</span></a>
        <?php endif; ?>
    </div>
</section>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Purchase</h3></header>
        <?= render_details([
            'Card owner'         => show($item['lender_name']),
            'Card used'          => $item['last_four'] ? e($item['issuer'] . ' •••• ' . $item['last_four']) : '—',
            'Purchase date'      => e(format_date($item['transaction_date'] ?? $item['obligation_date'])),
            'Description'        => show($item['description']),
            'Notes'              => show_multiline($item['transaction_notes']),
            'Reference'          => e($item['obligation_number']) . ($item['transaction_number'] ? ' · ' . e($item['transaction_number']) : ''),
            'Amount to repay'    => e(format_money($item['total_amount_due'])),
            'Repaid (confirmed)' => e(format_money($item['total_paid'])),
            'Waiting for confirmation' => e(format_money($item['pending'])),
            'Remaining'          => '<strong>' . e(format_money($item['outstanding_amount'])) . '</strong>',
            'Repay by'           => $item['due_date'] ? '<strong>' . e(format_date($item['due_date'])) . '</strong> <span class="text-muted">(the card\'s payment due date for this purchase)</span>' : '—',
            'Status'             => borrowed_status_badge($item['repay_status']),
        ]) ?>
    </section>

    <section class="panel">
        <header class="panel__header"><h3>Repayment history</h3></header>
        <?php if (!$repayments): ?>
            <p class="empty-inline">No repayments recorded yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--cards table--compact">
                    <thead><tr><th scope="col">Date</th><th scope="col" class="num">Amount</th><th scope="col">Status</th><th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($repayments as $r): ?>
                        <tr>
                            <td data-label="Date"><?= e(format_date($r['payment_date'])) ?> <span class="text-muted"><?= e($r['payment_number']) ?><?= $r['reference_number'] ? ' · ref ' . e($r['reference_number']) : '' ?></span>
                                <?php if ($r['status'] === 'rejected' && $r['rejection_reason']): ?><br><span class="text-danger"><?= e($r['rejection_reason']) ?></span><?php endif; ?></td>
                            <td data-label="Amount" class="num"><?= e(format_money($r['amount_for_item'])) ?>
                                <?php if ($r['status'] === 'approved'): ?><br><span class="text-muted"><?= e(format_money($r['applied'])) ?> applied</span><?php endif; ?></td>
                            <td data-label="Status"><?= badge($r['status'] === 'approved' ? 'approved' : $r['status']) ?>
                                <?php if (in_array($r['status'], ['submitted', 'under_review'], true)): ?><br><span class="text-muted">waiting for the card owner</span><?php endif; ?></td>
                            <td class="actions no-print">
                                <?php if (in_array($r['status'], PORTAL_CANCELLABLE_STATUSES, true)): ?>
                                    <?= post_button(url('pages/borrowed/cancel.php'), ['id' => $r['id'], 'item' => $item['id']], 'Cancel', 'x', 'btn btn--sm btn--outline',
                                        'Cancel this repayment record? Use this if you entered it by mistake.', false, 'Cancel repayment?') ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
