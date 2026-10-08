<?php
/**
 * Agreements - printable agreement text, terms, signatories and attached documents.
 * The Print button prints the agreement itself (header and signature lines).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/agreement_functions.php';

$currentUser = require_admin();

$agreement = find_or_redirect(agreement_find(request_id()), 'pages/agreements/index.php', 'Agreement');
$id = $agreement['id'];
$text = agreement_text($agreement);
$locked = in_array($agreement['status'], AGREEMENT_LOCKED_STATUSES, true);

$signatories = db_all('SELECT * FROM agreement_signatories WHERE agreement_id = :id ORDER BY created_at', [':id' => $id]);
$documents = db_all(
    'SELECT ad.id AS link_id, ad.document_type AS type_label, ad.version, d.id AS document_id, d.original_filename, d.document_type
       FROM agreement_documents ad JOIN documents d ON d.id = ad.document_id
      WHERE ad.agreement_id = :id ORDER BY ad.uploaded_at',
    [':id' => $id]
);
$letters = (int) db_value('SELECT COUNT(*) FROM demand_letters WHERE agreement_id = :id', [':id' => $id]);
$related = url('pages/agreements/related.php');

$pageTitle = 'Agreement';
$activeNav = 'agreements';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Agreements' => url('pages/agreements/index.php'), $agreement['agreement_number'] => null]) ?>

<section class="page-header no-print">
    <div>
        <h2 class="page-header__title"><?= e($agreement['agreement_number']) ?></h2>
        <p class="page-header__subtitle"><?= e(borrower_name($agreement)) ?> · <?= e(format_date($agreement['agreement_date'])) ?> · <?= badge($agreement['status']) ?></p>
    </div>
    <div class="page-header__actions">
        <a href="<?= e(url('pages/agreements/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print agreement</span></button>
        <?php if (!$locked): ?>
            <a href="<?= e(url('pages/agreements/edit.php', ['id' => $id])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
        <?php endif; ?>
    </div>
</section>

<section class="panel print-document">
    <div class="print-document__head">
        <p class="print-header__system"><?= e(APP_NAME) ?></p>
        <h2><?= e($agreement['template_name'] ?? 'Agreement') ?></h2>
        <p class="text-muted">Agreement No. <?= e($agreement['agreement_number']) ?> · <?= e(format_date($agreement['agreement_date'])) ?></p>
    </div>
    <?php if ($text !== null): ?>
        <div class="document-body"><?= e($text) ?></div>
    <?php else: ?>
        <p class="empty-inline no-print">No template selected. Choose one on the edit page to produce the agreement text.</p>
        <?= render_details([
            'Borrower'        => e(borrower_name($agreement)),
            'Original amount' => show_money($agreement['original_amount']),
            'Installments'    => $agreement['installment_count'] ? e($agreement['installment_count']) . ' × ' . e(format_money($agreement['installment_amount'])) : '',
            'Agreed charges'  => e(format_money($agreement['agreed_charges'])),
            'Payment terms'   => show_multiline($agreement['payment_terms']),
        ]) ?>
    <?php endif; ?>
    <?php if ($signatories): ?>
        <div class="signature-grid">
            <?php foreach ($signatories as $s): ?>
                <div class="signature-line">
                    <span><?= e($s['name']) ?></span>
                    <small><?= e($s['role']) ?><?= $s['signed_at'] ? ' · signed ' . e(format_date($s['signed_at'])) : '' ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<div class="detail-layout no-print">
    <section class="panel">
        <header class="panel__header"><h3>Agreement record</h3></header>
        <?= render_details([
            'Agreement number' => e($agreement['agreement_number']),
            'Borrower'         => related_link($agreement['borrower_id'], borrower_name($agreement) . ' (' . $agreement['borrower_number'] . ')', 'pages/borrowers/view.php'),
            'Template'         => related_link($agreement['template_id'], $agreement['template_name'] ? $agreement['template_name'] . ' v' . $agreement['template_version'] : null, 'pages/agreement_templates/view.php'),
            'Transaction'      => related_link($agreement['transaction_id'], $agreement['transaction_number'], 'pages/transactions/view.php'),
            'Obligation'       => related_link($agreement['obligation_id'], $agreement['obligation_number'], 'pages/obligations/view.php'),
            'Original amount'  => show_money($agreement['original_amount']),
            'Installments'     => $agreement['installment_count'] ? e($agreement['installment_count']) . ' × ' . e(format_money($agreement['installment_amount'])) : '',
            'Agreed charges'   => e(format_money($agreement['agreed_charges'])),
            'Payment terms'    => show_multiline($agreement['payment_terms']),
            'Generated copy'   => related_link($agreement['generated_document_id'], $agreement['generated_filename'], 'pages/documents/view.php'),
            'Signed copy'      => related_link($agreement['signed_document_id'], $agreement['signed_filename'], 'pages/documents/view.php'),
            'Status'           => badge($agreement['status']),
            'Created'          => e(format_datetime($agreement['created_at'])) . ($agreement['created_by_name'] ? ' by ' . e($agreement['created_by_name']) : ''),
            'Last updated'     => e(format_datetime($agreement['updated_at'])),
        ]) ?>
    </section>

    <div class="detail-side">
        <section class="panel panel--danger">
            <header class="panel__header"><h3>Delete</h3></header>
            <?php if ($agreement['status'] !== 'draft' || $letters > 0): ?>
                <p class="field-help">Only drafts not referenced by demand letters can be deleted. Set the status to Cancelled instead.</p>
            <?php else: ?>
                <p class="field-help">Deleting a draft also removes its signatories and document links.</p>
                <?= post_button(url('pages/agreements/delete.php'), ['id' => $id], 'Delete draft', 'trash', 'btn btn--danger btn--block',
                    'Delete draft agreement ' . $agreement['agreement_number'] . '? This cannot be undone.') ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<section class="panel subpanel no-print">
    <header class="panel__header">
        <h3><?= icon('users') ?> Signatories</h3>
        <?php if (!$locked): ?>
            <button type="button" class="btn btn--sm btn--outline" data-toggle-panel="add-signatory"><?= icon('plus') ?> <span>Add signatory</span></button>
        <?php endif; ?>
    </header>
    <?php if (!$locked): ?>
        <form method="post" action="<?= e($related) ?>" class="inline-panel" id="add-signatory" hidden novalidate data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($id) ?>">
            <input type="hidden" name="action" value="add_signatory">
            <?= render_fields(signatory_fields(), form_defaults(signatory_fields(), ['role' => 'borrower']), []) ?>
            <div class="inline-panel__actions">
                <button type="button" class="btn btn--outline" data-toggle-panel="add-signatory">Cancel</button>
                <button type="submit" class="btn btn--primary" data-loading-text="Saving…"><?= icon('check') ?> <span class="btn__label">Add signatory</span></button>
            </div>
        </form>
    <?php endif; ?>
    <?php if (!$signatories): ?>
        <p class="empty-inline">No signatories yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col">Contact</th><th scope="col">Status</th><th scope="col">Signed</th><th scope="col" class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($signatories as $s): ?>
                    <tr>
                        <td data-label="Name"><?= e($s['name']) ?> <?= $s['borrower_id'] ? '<span class="chip chip--muted">borrower</span>' : ($s['user_id'] ? '<span class="chip chip--muted">user</span>' : '') ?></td>
                        <td data-label="Role"><?= e($s['role']) ?></td>
                        <td data-label="Contact"><?= show(trim(($s['email'] ?? '') . ' ' . ($s['mobile'] ?? ''))) ?></td>
                        <td data-label="Status"><?= badge($s['status']) ?></td>
                        <td data-label="Signed"><?= show($s['signed_at'] ? format_datetime($s['signed_at']) : null) ?><?= $s['signature_reference'] ? '<br><span class="text-muted">' . e($s['signature_reference']) . '</span>' : '' ?></td>
                        <td class="actions"><div class="row-actions">
                            <?php if (!$locked && $s['status'] === 'pending'): ?>
                                <form method="post" action="<?= e($related) ?>" class="schedule-pay">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e($id) ?>">
                                    <input type="hidden" name="action" value="sign">
                                    <input type="hidden" name="item_id" value="<?= e($s['id']) ?>">
                                    <label class="visually-hidden" for="ref-<?= e($s['id']) ?>">Signature reference</label>
                                    <input type="text" id="ref-<?= e($s['id']) ?>" name="signature_reference" maxlength="255" placeholder="Reference (optional)">
                                    <button type="submit" class="btn btn--sm btn--primary"><?= icon('check') ?> <span class="btn__label">Signed</span></button>
                                </form>
                                <?= post_button($related, ['id' => $id, 'action' => 'decline', 'item_id' => $s['id']], 'Mark declined', 'x', 'icon-button', 'Mark this signatory as declined?', true, 'Mark declined?') ?>
                                <?= post_button($related, ['id' => $id, 'action' => 'delete_signatory', 'item_id' => $s['id']], 'Remove signatory', 'trash', 'icon-button icon-button--danger', 'Remove this signatory?', true) ?>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<div class="no-print">
    <?= render_attachments_panel($documents, $related, ['id' => $id], true, !$locked) ?>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
