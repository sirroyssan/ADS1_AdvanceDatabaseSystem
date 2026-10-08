<?php
/**
 * Document Files - archive (is_deleted = 1) or restore. Files are never
 * removed from disk or the database because other records may point to them.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/document_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/documents/index.php');
}

$doc = find_or_redirect(document_find(request_id()), 'pages/documents/index.php', 'Document');
require_csrf('pages/documents/view.php?id=' . $doc['id']);

$restore = input_string($_POST, 'action') === 'restore';
db_update('documents', ['is_deleted' => $restore ? 0 : 1], $doc['id']);
log_activity($restore ? 'restore' : 'archive', 'document', $doc['id'], ($restore ? 'Restored ' : 'Archived ') . $doc['original_filename']);
flash('success', 'File "' . $doc['original_filename'] . '" was ' . ($restore ? 'restored.' : 'archived.'));

redirect('pages/documents/view.php', ['id' => $doc['id']]);
