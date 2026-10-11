<?php
/**
 * Amounts to Repay - purchases the signed-in user made with other users'
 * credit cards (includes/borrowed.php). Only the user's own borrowed items
 * are shown: never the card owner's other transactions or balances.
 */

require_once __DIR__ . '/../../includes/auth_check.php';

$currentUser = require_admin();

$items = borrowed_items();
$sum = borrowed_summary();

$pageTitle = 'Amounts to Repay';
$activeNav = 'borrowed';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Amounts to Repay' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Amounts to repay</h2>
        <p class="page-header__subtitle">Purchases you made with someone else's credit card. Each is due on that card's payment due date. Record a repayment when you pay; the card owner confirms it.</p>
    </div>
    <div class="page-header__actions no-print">
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
    </div>
</section>

<?php if (!$items): ?>
    <section class="panel"><p class="empty-inline">Nothing borrowed. When a card owner records a purchase you made with their card, it appears here with its due date.</p></section>
<?php else: ?>
    <section class="panel pay-summary" aria-label="Summary">
        <div class="pay-cards">
            <?php foreach ([
                ['Total borrowed', format_money($sum['borrowed']), $sum['count'] . ' purchase(s)', 'primary', 'credit-card'],
                ['Outstanding to repay', format_money($sum['outstanding']), format_money($sum['repaid']) . ' repaid so far', 'warning', 'wallet'],
                ['Next repayment deadline', $sum['next_due'] ? format_date($sum['next_due']) : '—', $sum['upcoming'] . ' upcoming', 'info', 'calendar'],
                ['Overdue', (string) $sum['overdue'], $sum['overdue'] ? format_money($sum['overdue_amount']) . ' past due' : 'nothing overdue', 'danger', 'alert'],
                ['Waiting for confirmation', format_money($sum['pending']), 'repayments you recorded, not confirmed yet', 'info', 'history'],
            ] as [$label, $value, $meta, $tone, $iconName]): ?>
                <div class="pay-card pay-card--<?= e($tone) ?>">
                    <span class="pay-card__icon"><?= icon($iconName) ?></span>
                    <span class="pay-card__label"><?= e($label) ?></span>
                    <span class="pay-card__value"><?= e($value) ?></span>
                    <span class="pay-card__meta"><?= e($meta) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel panel--flush" aria-label="Borrowed purchases">
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col">Purchase</th>
                    <th scope="col">Card owner</th>
                    <th scope="col" class="num">Amount</th>
                    <th scope="col" class="num">Repaid</th>
                    <th scope="col" class="num">Remaining</th>
                    <th scope="col">Due date</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $row): ?>
                    <tr>
                        <td data-label="Purchase">
                            <a data-modal-form class="table-link" href="<?= e(url('pages/borrowed/view.php', ['id' => $row['id']])) ?>"><?= e($row['merchant_name'] ?: ($row['description'] ?: $row['obligation_number'])) ?></a>
                            <span class="text-muted"><?= e(format_date($row['transaction_date'] ?? $row['obligation_date'])) ?><?= $row['last_four'] ? ' · ' . e($row['issuer']) . ' •••• ' . e($row['last_four']) : '' ?></span>
                        </td>
                        <td data-label="Card owner"><?= e($row['lender_name'] ?? '—') ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($row['total_amount_due'])) ?></td>
                        <td data-label="Repaid" class="num"><?= e(format_money($row['total_paid'])) ?>
                            <?php if ((float) $row['pending'] > 0): ?><br><span class="text-muted"><?= e(format_money($row['pending'])) ?> to confirm</span><?php endif; ?></td>
                        <td data-label="Remaining" class="num"><strong><?= e(format_money($row['outstanding_amount'])) ?></strong></td>
                        <td data-label="Due date"><?= $row['due_date'] ? e(format_date($row['due_date'])) : '<span class="text-muted">—</span>' ?></td>
                        <td data-label="Status"><?= borrowed_status_badge($row['repay_status']) ?></td>
                        <td class="actions no-print"><div class="row-actions">
                            <a data-modal-form href="<?= e(url('pages/borrowed/view.php', ['id' => $row['id']])) ?>" class="icon-button" title="View" aria-label="View"><?= icon('eye') ?></a>
                            <?php if ($row['repay_status'] !== 'paid' && (float) $row['outstanding_amount'] - (float) $row['pending'] > 0.004): ?>
                                <a data-modal-form href="<?= e(url('pages/borrowed/repay.php', ['id' => $row['id']])) ?>" class="btn btn--sm btn--primary"><?= icon('wallet') ?> <span>Repay</span></a>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
