<?php
/**
 * Document Files - stream a stored file to an administrator.
 * ?inline=1 shows PDFs/images in the browser; everything else downloads.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/document_functions.php';

$currentUser = require_admin();

$doc = find_or_redirect(document_find(request_id()), 'pages/documents/index.php', 'Document');
$path = document_path($doc['storage_key']) ?? drive_restore_document($doc);   // missing here: get it back from Google Drive

if ($path === null) {
    flash('error', 'The stored file could not be found.');
    redirect('pages/documents/view.php', ['id' => $doc['id']]);
}

$mime = in_array($doc['mime_type'], DOCUMENT_ALLOWED, true) ? $doc['mime_type'] : 'application/octet-stream';
$inline = input_string($_GET, 'inline') === '1' && in_array($mime, DOCUMENT_INLINE, true);
$filename = preg_replace('/[^A-Za-z0-9._ -]/', '_', $doc['original_filename']) ?: 'document';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
if ($mime !== 'application/pdf') {
    // Nothing in a served file may run scripts (the browser's PDF viewer needs its own rules).
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
}
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($doc['original_filename']));
header('Cache-Control: private, no-store');

readfile($path);
exit;
