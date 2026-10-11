<?php
/**
 * Document Files - upload a file.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/document_functions.php';

$currentUser = require_admin();

$fields = document_fields();
unset($fields['original_filename']);
$data = form_defaults($fields);
$errors = [];

if (is_post()) {
    require_csrf('pages/documents/create.php');
    [$data, $errors] = validate_fields($fields, $_POST);

    $id = new_uuid();
    [$stored, $uploadError] = $errors ? [null, null] : document_store_upload($_FILES['file'] ?? [], $id);
    if ($uploadError !== null) {
        $errors['file'] = $uploadError;
    }

    if (!$errors && $stored) {
        try {
            db_insert('documents', ['id' => $id, 'document_type' => $data['document_type']] + $stored + [
                'owner_user_id' => owner_id(),
                'uploaded_by' => $currentUser['id'],
                'metadata'    => json_encode($data['description'] ? ['description' => $data['description']] : new stdClass(), JSON_UNESCAPED_UNICODE),
            ]);
            log_activity('upload', 'document', $id, 'Uploaded ' . $stored['original_filename']);
            flash('success', 'File "' . $stored['original_filename'] . '" was uploaded.');
            redirect('pages/documents/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            @unlink(APP_ROOT . '/' . DOCUMENT_STORAGE_DIR . '/' . $stored['storage_key']);
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Upload File';
$activeNav = 'documents';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Document Files' => url('pages/documents/index.php'), 'Upload' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Upload file</h2>
        <p class="page-header__subtitle">PDF, images, Word, Excel or text files up to <?= e(DOCUMENT_MAX_BYTES / 1024 / 1024) ?> MB.</p>
    </div>
</section>

<form method="post" action="<?= e(url('pages/documents/create.php')) ?>" enctype="multipart/form-data" class="form-card form-card--wide" novalidate data-validate>
    <?= csrf_field() ?>
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= e(DOCUMENT_MAX_BYTES) ?>">
    <?php if ($errors): ?>
        <div class="alert alert--error" role="alert"><?= icon('alert') ?><span><?= e($errors['_form'] ?? 'Please correct the highlighted fields below.') ?></span></div>
    <?php endif; ?>
    <div class="form-grid">
        <div class="form-group form-group--full">
            <label for="f_file">File <span class="required" aria-hidden="true">*</span></label>
            <input type="file" id="f_file" name="file" required accept=".<?= e(implode(',.', array_keys(DOCUMENT_ALLOWED))) ?>"
                   class="<?= isset($errors['file']) ? 'is-invalid' : '' ?>" aria-describedby="f_file-error">
            <p class="field-error" id="f_file-error" <?= isset($errors['file']) ? '' : 'hidden' ?>><?= e($errors['file'] ?? '') ?></p>
        </div>
    </div>
    <?= render_fields($fields, $data, $errors) ?>
    <div class="form-actions">
        <a href="<?= e(url('pages/documents/index.php')) ?>" class="btn btn--outline">Cancel</a>
        <button type="submit" class="btn btn--primary" data-loading-text="Uploading…"><?= icon('check') ?> <span class="btn__label">Upload</span></button>
    </div>
</form>

<?php require APP_ROOT . '/includes/footer.php'; ?>
