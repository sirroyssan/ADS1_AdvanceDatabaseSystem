<?php
/**
 * Borrower portal - open one of MY files (payment proof, agreement copy,
 * notice). Anything not linked to the signed-in borrower is refused.
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once APP_ROOT . '/pages/documents/document_functions.php';

[$currentUser, $borrower] = require_borrower();

$id = input_string($_GET, 'id');
$doc = is_valid_uuid($id) ? document_find($id) : null;

if (!$doc || (int) $doc['is_deleted'] === 1 || !portal_can_see_document($doc['id'], $borrower['id'])) {
    log_activity('access_denied', 'document', is_valid_uuid($id) ? $id : null, 'Borrower portal: file not allowed');
    flash('error', 'File not found.');
    redirect('portal/index.php');
}

$content = drive_document_content($doc);   // this computer, or Google Drive
if ($content === null) {
    flash('error', drive_is_drive_key($doc['storage_key']) ? 'The file could not be opened from Google Drive right now. Please try again later.' : 'The stored file could not be found. Please tell the owner.');
    redirect('portal/index.php');
}

$mime = in_array($doc['mime_type'], DOCUMENT_ALLOWED, true) ? $doc['mime_type'] : 'application/octet-stream';
$inline = input_string($_GET, 'inline') === '1' && in_array($mime, DOCUMENT_INLINE, true);
$filename = preg_replace('/[^A-Za-z0-9._ -]/', '_', $doc['original_filename']) ?: 'document';

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($content));
header('X-Content-Type-Options: nosniff');
if ($mime !== 'application/pdf') {
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
}
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($doc['original_filename']));
header('Cache-Control: private, no-store');

echo $content;
exit;
