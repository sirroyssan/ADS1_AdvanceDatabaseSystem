<?php
/**
 * Amounts to Repay - card purchases assigned to the signed-in user by card
 * owners (the owners' transactions themselves, includes/borrowed.php). Only
 * purchases assigned to this user are shown: never the owners' other records.
 */

require_once __DIR__ . '/../../includes/auth_check.php';

[$currentUser, $layout] = borrowed_require_user();

$items = borrowed_my_purchases();
$sum = borrowed_totals($items);

$pageTitle = 'Amounts to Repay';
$activeNav = 'borrowed';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Amounts to Repay' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Amounts to repay</h2>
        <p class="page-header__subtitle">Purchases made for you with someone else's credit card. Each is due by the card's payment due date. Record a repayment when you pay; the card owner confirms it.</p>
    </div>
    <div class="page-header__actions no-print">
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
    </div>
</section>

<?php if (!$items): ?>
    <section class="panel"><p class="empty-inline">No purchases are assigned to you. When a card owner records a purchase made for you, it appears here right away.</p></section>
<?php else: ?>
    <section class="panel pay-summary" aria-label="Summary">
        <div class="pay-cards">
            <?php foreach ([
                ['Total borrowed', format_money($sum['responsible']), $sum['count'] . ' purchase(s)', 'primary', 'credit-card'],
                ['Total outstanding', format_money($sum['outstanding']), format_money($sum['repaid']) . ' repaid (confirmed)' . ($sum['penalties'] > 0 ? ' · ' . format_money($sum['penalties']) . ' penalties' : ''), 'warning', 'wallet'],
                ['Next repayment deadline', $sum['next_due'] ? format_date($sum['next_due']) : '—', $sum['upcoming'] . ' upcoming', 'info', 'calendar'],
                ['Overdue', (string) $sum['overdue'], $sum['overdue'] ? format_money($sum['overdue_amount']) . ' past due' : 'nothing overdue', 'danger', 'alert'],
                ['Reported, not confirmed', format_money($sum['reported']), 'repayments waiting for the card owner', 'info', 'history'],
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
                    <th scope="col">Card / owner</th>
                    <th scope="col" class="num">Amount</th>
                    <th scope="col" class="num">Repaid</th>
                    <th scope="col" class="num">Penalty</th>
                    <th scope="col" class="num">Outstanding</th>
                    <th scope="col">Repay by</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $row): ?>
                    <?php $l = $row['ledger']; ?>
                    <tr>
                        <td data-label="Purchase">
                            <a data-modal-form class="table-link" href="<?= e(url('pages/borrowed/view.php', ['id' => $row['id']])) ?>"><?= e($row['merchant_name'] ?: $row['transaction_number']) ?></a>
                            <span class="text-muted"><?= e(format_date($row['transaction_date'])) ?><?= $row['notes'] ? ' · ' . e(excerpt($row['notes'], 50)) : '' ?></span>
                        </td>
                        <td data-label="Card / owner"><?= e($row['issuer']) ?> •••• <?= e($row['last_four']) ?><br><span class="text-muted"><?= e($row['lender_name'] ?? '—') ?></span></td>
                        <td data-label="Amount" class="num"><?= e(format_money($l['responsible'])) ?></td>
                        <td data-label="Repaid" class="num"><?= e(format_money($l['repaid'])) ?>
                            <?php if ($l['reported'] > 0): ?><br><span class="text-muted"><?= e(format_money($l['reported'])) ?> reported</span><?php endif; ?></td>
                        <td data-label="Penalty" class="num<?= $l['penalty_unpaid'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($l['penalty_unpaid'])) ?></td>
                        <td data-label="Outstanding" class="num"><strong><?= e(format_money($l['outstanding'])) ?></strong></td>
                        <td data-label="Repay by"><?= e(borrowed_due_text($l)) ?></td>
                        <td data-label="Status"><?= borrowed_status_badge($l['status']) ?></td>
                        <td class="actions no-print"><div class="row-actions">
                            <a data-modal-form href="<?= e(url('pages/borrowed/view.php', ['id' => $row['id']])) ?>" class="icon-button" title="View" aria-label="View"><?= icon('eye') ?></a>
                            <?php if ($l['outstanding'] - $l['reported'] > 0.004): ?>
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
