<?php
/**
 * Borrower portal - one agreement: text, files and who has signed.
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once APP_ROOT . '/pages/agreements/agreement_functions.php';

[$currentUser, $borrower] = require_borrower();

$found = portal_find(
    'SELECT id FROM agreements WHERE id = :id AND borrower_id = :b AND status IN (' . sql_in_list(PORTAL_AGREEMENT_STATUSES) . ')',
    input_string($_GET, 'id'), $borrower['id'], 'portal/agreements.php', 'Agreement'
);
$agreement = agreement_find($found['id']);
$text = agreement_text($agreement);

$files = db_all(
    "SELECT d.id, d.original_filename, d.file_size, 'Agreement copy' AS label FROM documents d WHERE d.id = :g AND d.is_deleted = 0
     UNION ALL SELECT d.id, d.original_filename, d.file_size, 'Signed copy' FROM documents d WHERE d.id = :s AND d.is_deleted = 0
     UNION ALL SELECT d.id, d.original_filename, d.file_size, ad.document_type FROM agreement_documents ad JOIN documents d ON d.id = ad.document_id
                WHERE ad.agreement_id = :a AND d.is_deleted = 0 AND d.id <> COALESCE(:g2, '') AND d.id <> COALESCE(:s2, '')",
    [':g' => $agreement['generated_document_id'] ?? '', ':s' => $agreement['signed_document_id'] ?? '', ':a' => $agreement['id'],
     ':g2' => $agreement['generated_document_id'], ':s2' => $agreement['signed_document_id']]
);
$signatories = db_all('SELECT name, role, status, signed_at FROM agreement_signatories WHERE agreement_id = :a ORDER BY created_at', [':a' => $agreement['id']]);

$pageTitle = 'Agreement';
$activeNav = 'portal_agreements';
$layout = 'portal';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Agreements' => url('portal/agreements.php'), $agreement['agreement_number'] => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Agreement <?= e($agreement['agreement_number']) ?></h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($agreement['agreement_number']) ?></h2>
        <p class="page-header__subtitle"><?= e(format_date($agreement['agreement_date'])) ?> · <?= badge($agreement['status']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('portal/agreements.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
    </div>
</section>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Terms</h3></header>
        <?= render_details([
            'Original amount' => show_money($agreement['original_amount']),
            'Installments'    => $agreement['installment_count'] ? e($agreement['installment_count']) . ' × ' . e(format_money($agreement['installment_amount'])) : '<span class="text-muted">—</span>',
            'Agreed charges'  => e(format_money($agreement['agreed_charges'])),
            'Payment terms'   => show_multiline($agreement['payment_terms']),
            'For'             => show($agreement['obligation_number'] ?? $agreement['transaction_number']),
        ]) ?>
    </section>
    <div class="detail-side">
        <section class="panel">
            <header class="panel__header"><h3>Files</h3></header>
            <?php if (!$files): ?>
                <p class="empty-inline">No file attached.</p>
            <?php else: ?>
                <ul class="child-list">
                    <?php foreach ($files as $f): ?>
                        <li><a href="<?= e(url('portal/download.php', ['id' => $f['id'], 'inline' => '1'])) ?>" target="_blank" rel="noopener"><?= e($f['original_filename']) ?></a>
                            <span><?= e(humanize($f['label'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <section class="panel">
            <header class="panel__header"><h3>Signatures</h3></header>
            <?php if (!$signatories): ?>
                <p class="empty-inline">No signatories listed.</p>
            <?php else: ?>
                <ul class="child-list">
                    <?php foreach ($signatories as $s): ?>
                        <li><span><?= e($s['name']) ?> <span class="text-muted">(<?= e($s['role']) ?>)</span></span>
                            <span><?= badge($s['status']) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php if ($text): ?>
    <section class="panel subpanel">
        <header class="panel__header"><h3>Agreement text</h3></header>
        <div class="document-body"><?= e($text) ?></div>
    </section>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
