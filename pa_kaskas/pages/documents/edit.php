<?php
/**
 * Document Files - edit type, displayed file name and description.
 * The stored file itself is never replaced (upload a new document instead).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/document_functions.php';

$currentUser = require_admin();

$doc = find_or_redirect(document_find(request_id()), 'pages/documents/index.php', 'Document');
$fields = document_fields();
$data = form_data_from_row($fields, $doc);
$errors = [];

if (is_post()) {
    require_csrf('pages/documents/edit.php?id=' . $doc['id']);
    [$data, $errors] = validate_fields($fields, $_POST, $doc['id']);

    if (!$errors) {
        try {
            $meta = $doc['meta'];
            if ($data['description']) {
                $meta['description'] = $data['description'];
            } else {
                unset($meta['description']);
            }
            db_update('documents', [
                'document_type'     => $data['document_type'],
                'original_filename' => $data['original_filename'],
                'metadata'          => json_encode($meta ?: new stdClass(), JSON_UNESCAPED_UNICODE),
            ], $doc['id']);
            log_activity('update', 'document', $doc['id'], 'Updated document details', array_intersect_key($doc, $data), $data);
            flash('success', 'Document details were updated.');
            redirect('pages/documents/view.php', ['id' => $doc['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Document';
$activeNav = 'documents';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Document Files' => url('pages/documents/index.php'),
    $doc['original_filename'] => url('pages/documents/view.php', ['id' => $doc['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit document</h2>
        <p class="page-header__subtitle">To replace the file, upload a new document.</p>
    </div>
</section>

<?= render_record_form(url('pages/documents/edit.php', ['id' => $doc['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/documents/view.php', ['id' => $doc['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
