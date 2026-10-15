<?php
/**
 * Borrower portal - my agreements (generated, active, signed, completed).
 */

require_once __DIR__ . '/../includes/auth_check.php';

[$currentUser, $borrower] = require_borrower();

$agreements = db_all(
    'SELECT a.id, a.agreement_number, a.agreement_date, a.status, a.original_amount, a.installment_amount, a.installment_count, o.obligation_number
       FROM agreements a LEFT JOIN borrower_obligations o ON o.id = a.obligation_id
      WHERE a.borrower_id = :b AND a.status IN (' . sql_in_list(PORTAL_AGREEMENT_STATUSES) . ')
      ORDER BY a.agreement_date DESC',
    [':b' => $borrower['id']]
);

$pageTitle = 'Agreements';
$activeNav = 'portal_agreements';
$layout = 'portal';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Agreements' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">My agreements</h2>
        <p class="page-header__subtitle">Payment agreements between you and the owner.</p>
    </div>
</section>

<section class="panel panel--flush">
    <?php if (!$agreements): ?>
        <div class="empty-state"><div class="empty-state__icon"><?= icon('file-sign', 'icon icon--xl') ?></div>
            <h3>No agreements yet.</h3><p class="text-muted">Agreements the owner prepares for you will appear here.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr><th scope="col">Agreement</th><th scope="col">Date</th><th scope="col">For</th><th scope="col" class="num">Amount</th><th scope="col">Installments</th><th scope="col">Status</th></tr></thead>
                <tbody>
                <?php foreach ($agreements as $a): ?>
                    <tr>
                        <td data-label="Agreement"><a data-modal-form class="table-link" href="<?= e(url('portal/agreement.php', ['id' => $a['id']])) ?>"><?= e($a['agreement_number']) ?></a></td>
                        <td data-label="Date"><?= e(format_date($a['agreement_date'])) ?></td>
                        <td data-label="For"><?= show($a['obligation_number']) ?></td>
                        <td data-label="Amount" class="num"><?= show_money($a['original_amount']) ?></td>
                        <td data-label="Installments"><?= $a['installment_count'] ? e($a['installment_count']) . ' × ' . e(format_money($a['installment_amount'])) : '<span class="text-muted">—</span>' ?></td>
                        <td data-label="Status"><?= badge($a['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
