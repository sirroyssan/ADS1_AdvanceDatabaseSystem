<?php
/**
 * Document Files - details, preview (images/PDF), where it is used, archive/restore.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/document_functions.php';

$currentUser = require_admin();

$doc = find_or_redirect(document_find(request_id()), 'pages/documents/index.php', 'Document');
$usage = document_usage($doc['id']);
$fileExists = drive_is_drive_key($doc['storage_key']) || document_path($doc['storage_key']) !== null;   // a Drive-only proof opens from Drive
$previewUrl = url('pages/documents/download.php', ['id' => $doc['id'], 'inline' => '1']);
$isImage = str_starts_with((string) $doc['mime_type'], 'image/');
$isPdf = $doc['mime_type'] === 'application/pdf';

$pageTitle = 'Document';
$activeNav = 'documents';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Document Files' => url('pages/documents/index.php'), $doc['original_filename'] => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($doc['original_filename']) ?></h2>
        <p class="page-header__subtitle"><?= e(humanize($doc['document_type'])) ?> · <?= e(format_bytes($doc['file_size'] !== null ? (int) $doc['file_size'] : null)) ?>
            <?= (int) $doc['is_deleted'] === 1 ? ' · ' . badge('inactive') . ' archived' : '' ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/documents/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <?php if ($fileExists): ?>
            <a href="<?= e(url('pages/documents/download.php', ['id' => $doc['id']])) ?>" class="btn btn--outline"><?= icon('folder') ?> <span>Download</span></a>
        <?php endif; ?>
        <a data-modal-form href="<?= e(url('pages/documents/edit.php', ['id' => $doc['id']])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
    </div>
</section>

<?php if (!$fileExists): ?>
    <div class="alert alert--warning"><?= icon('alert') ?><span>The stored file is missing from <code><?= e(DOCUMENT_STORAGE_DIR) ?></code>. The record is kept for reference.</span></div>
<?php endif; ?>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Preview</h3></header>
        <?php if ($fileExists && $isImage): ?>
            <img src="<?= e($previewUrl) ?>" alt="<?= e($doc['original_filename']) ?>" class="document-preview">
        <?php elseif ($fileExists && $isPdf): ?>
            <iframe src="<?= e($previewUrl) ?>" title="<?= e($doc['original_filename']) ?>" class="document-preview document-preview--pdf"></iframe>
        <?php else: ?>
            <p class="empty-inline">No preview for this file type. Use Download to open it.</p>
        <?php endif; ?>
    </section>

    <div class="detail-side">
        <section class="panel">
            <header class="panel__header"><h3>Details</h3></header>
            <?= render_details([
                'File name'   => e($doc['original_filename']),
                'Type'        => e(humanize($doc['document_type'])),
                'Description' => show_multiline($doc['description']),
                'MIME type'   => show($doc['mime_type']),
                'Size'        => e(format_bytes($doc['file_size'] !== null ? (int) $doc['file_size'] : null)),
                'SHA-256'     => $doc['checksum'] ? '<code>' . e($doc['checksum']) . '</code>' : '',
                'Uploaded'    => e(format_datetime($doc['uploaded_at'])) . ($doc['uploaded_by_name'] ? ' by ' . e($doc['uploaded_by_name']) : ''),
                'Google Drive' => $doc['document_type'] === 'payment_proof' ? (ltrim(drive_link_html($doc)) ?: '<span class="text-muted">Not saved there (connect it in Google Drive)</span>') : '',
                'Used by'     => $usage['total'] ? e(usage_text($usage)) : 'Not attached to any record',
            ]) ?>
        </section>

        <section class="panel panel--danger no-print">
            <header class="panel__header"><h3>Archive</h3></header>
            <?php if ((int) $doc['is_deleted'] === 1): ?>
                <p class="field-help">This file is archived and hidden from attachment lists.</p>
                <?= post_button(url('pages/documents/delete.php'), ['id' => $doc['id'], 'action' => 'restore'], 'Restore file', 'repeat', 'btn btn--outline btn--block') ?>
            <?php else: ?>
                <p class="field-help">Files are never permanently deleted because they serve as proof. Archiving hides the file from new attachments.</p>
                <?= post_button(url('pages/documents/delete.php'), ['id' => $doc['id'], 'action' => 'archive'], 'Archive file', 'trash', 'btn btn--danger btn--block',
                    'Archive "' . $doc['original_filename'] . '"? Records already linked to it keep the link.', false, 'Archive file?') ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
