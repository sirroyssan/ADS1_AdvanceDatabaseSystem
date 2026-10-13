<?php
/**
 * Document Files module (table: documents).
 *
 * Files are stored on disk in storage/documents/YYYY/MM/<uuid>.<ext>
 * (storage/ is blocked from the web by .htaccess); documents.storage_key keeps
 * that relative path (schema note #2). Files are served only through
 * download.php after the admin check.
 * No hard delete: documents are proof for statements, payments, agreements
 * and letters. is_deleted = 1 archives a file; it can be restored.
 */

defined('APP_ROOT') or exit('No direct access.');

const DOCUMENT_MAX_BYTES = 10 * 1024 * 1024;
const DOCUMENT_STORAGE_DIR = 'storage/documents';
const DOCUMENT_ALLOWED = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'txt'  => 'text/plain',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];
// Types that browsers may show inline; everything else is downloaded.
const DOCUMENT_INLINE = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];

function document_fields(): array
{
    return [
        'document_type'     => ['label' => 'Document type', 'type' => 'select', 'options' => enum_options(ENUM_DOCUMENT_TYPE), 'required' => true, 'empty_label' => 'Select type'],
        'original_filename' => ['label' => 'File name', 'type' => 'text', 'max' => 500, 'required' => true],
        'description'       => ['label' => 'Description', 'type' => 'textarea', 'rows' => 2, 'max' => 2000, 'help' => 'Saved in the document\'s metadata.'],
    ];
}

function document_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    $doc = db_row(
        "SELECT d.*, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email) AS uploaded_by_name
           FROM documents d LEFT JOIN users u ON u.id = d.uploaded_by
          WHERE d.id = :id AND " . owner_scope('documents', 'd'),
        [':id' => $id]
    );

    if ($doc) {
        $meta = json_decode((string) $doc['metadata'], true);
        $doc['meta'] = is_array($meta) ? $meta : [];
        $doc['description'] = $doc['meta']['description'] ?? null;
    }

    return $doc;
}

function document_usage(string $id): array
{
    return record_usage([
        'transactions'         => ['transaction_attachments', 'document_id'],
        'payments'             => ['payment_attachments', 'document_id'],
        'agreements'           => ['agreement_documents', 'document_id'],
        'statements'           => ['credit_card_statements', 'attachment_document_id'],
        'card payment receipts' => ['credit_card_payments', 'receipt_document_id'],
        'generated agreements' => ['agreements', 'generated_document_id'],
        'signed agreements'    => ['agreements', 'signed_document_id'],
        'demand letters'       => ['demand_letters', 'document_id'],
    ], $id);
}

/**
 * Absolute path of a stored file, refusing anything outside the storage folder.
 */
function document_path(string $storageKey): ?string
{
    $base = realpath(APP_ROOT . '/' . DOCUMENT_STORAGE_DIR);
    $path = realpath(APP_ROOT . '/' . DOCUMENT_STORAGE_DIR . '/' . $storageKey);

    if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
        return null;
    }

    return $path;
}

/**
 * Validate and store an uploaded file. Returns [storage data, error message].
 *
 * $driveOwner: for a proof of payment, the owner whose Google Drive gets it.
 * When that Drive is connected the file goes straight there (storage_key
 * "gdrive:<file id>", data['drive'] = {file_id, link, uploaded_at}) and
 * nothing is saved on this computer; when Drive cannot be reached it is saved
 * here as before, and sent to Drive later (System → Google Drive).
 *
 * @return array{0: ?array, 1: ?string}
 */
function document_store_upload(array $file, string $documentId, ?string $driveOwner = null, string $driveName = ''): array
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($error === UPLOAD_ERR_NO_FILE) {
        return [null, 'Choose a file to upload.'];
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || ($file['size'] ?? 0) > DOCUMENT_MAX_BYTES) {
        return [null, 'The file is too large. The maximum is ' . (DOCUMENT_MAX_BYTES / 1024 / 1024) . ' MB (and PHP\'s upload_max_filesize).'];
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        return [null, 'The upload failed. Please try again.'];
    }

    $name = basename(str_replace('\\', '/', (string) $file['name']));
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset(DOCUMENT_ALLOWED[$extension])) {
        return [null, 'Allowed file types: ' . implode(', ', array_keys(DOCUMENT_ALLOWED)) . '.'];
    }

    // Check the real content type, not just the extension.
    $mime = DOCUMENT_ALLOWED[$extension];
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = (string) finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $compatible = $detected === $mime
            || ($extension === 'txt' && str_starts_with($detected, 'text/'))
            || (in_array($extension, ['doc', 'docx', 'xls', 'xlsx'], true) && in_array($detected, ['application/zip', 'application/octet-stream', 'application/x-ole-storage', 'application/CDFV2', $mime], true));
        if (!$compatible) {
            return [null, 'The file content does not match its extension (.' . $extension . ').'];
        }
    }

    // Google Drive first (proofs of payment, when the owner connected it).
    $driveError = null;
    if ($driveOwner !== null && function_exists('drive_is_connected') && drive_is_connected($driveOwner)) {
        $upload = drive_upload($driveOwner, $file['tmp_name'], ($driveName !== '' ? $driveName . ' - ' : date('Y-m-d') . ' ') . $name, $mime);
        if ($upload !== null) {
            return [[
                'original_filename' => mb_substr($name, 0, 500),
                'storage_key'       => DRIVE_KEY_PREFIX . $upload['id'],
                'mime_type'         => $mime,
                'file_size'         => filesize($file['tmp_name']),
                'checksum'          => hash_file('sha256', $file['tmp_name']),
                'drive'             => ['file_id' => $upload['id'], 'link' => $upload['link'], 'uploaded_at' => date('c')],
            ], null];
        }
        $driveError = drive_last_error();   // not reachable now: keep it on this computer instead
    }

    $folder = date('Y') . '/' . date('m');
    $dir = APP_ROOT . '/' . DOCUMENT_STORAGE_DIR . '/' . $folder;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return [null, ($driveError ? 'Google Drive could not be reached (' . $driveError . ') and ' : '')
            . 'The storage folder could not be created. Check that storage/documents is writable. On XAMPP for macOS, run in Terminal: sudo chmod -R 777 ' . APP_ROOT . '/storage'];
    }

    $storageKey = $folder . '/' . $documentId . '.' . $extension;
    if (!@move_uploaded_file($file['tmp_name'], APP_ROOT . '/' . DOCUMENT_STORAGE_DIR . '/' . $storageKey)) {
        return [null, ($driveError ? 'Google Drive could not be reached (' . $driveError . ') and t' : 'T') . 'he file could not be saved. Check that storage/documents is writable. On XAMPP for macOS, run in Terminal: sudo chmod -R 777 ' . APP_ROOT . '/storage'];
    }

    $stored = APP_ROOT . '/' . DOCUMENT_STORAGE_DIR . '/' . $storageKey;

    return [[
        'original_filename' => mb_substr($name, 0, 500),
        'storage_key'       => $storageKey,
        'mime_type'         => $mime,
        'file_size'         => filesize($stored),
        'checksum'          => hash_file('sha256', $stored),
    ] + ($driveError ? ['drive_error' => ['message' => $driveError, 'at' => date('c')]] : []), null];   // waits for "Send pending proofs"
}

function format_bytes(?int $bytes): string
{
    if ($bytes === null) {
        return '—';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }

    return $bytes >= 1024 ? number_format($bytes / 1024, 0) . ' KB' : $bytes . ' B';
}

/** Undo a stored upload that was not saved in the end (the local file, or the Drive file). */
function document_discard_stored(?array $stored, ?string $driveOwner = null): void
{
    if (!$stored) {
        return;
    }
    if (str_starts_with($stored['storage_key'], DRIVE_KEY_PREFIX)) {
        if ($driveOwner !== null) {
            drive_delete($driveOwner, substr($stored['storage_key'], strlen(DRIVE_KEY_PREFIX)));
        }
        return;
    }
    @unlink(APP_ROOT . '/' . DOCUMENT_STORAGE_DIR . '/' . $stored['storage_key']);
}

/** Split the Drive data off a stored upload: [columns for the documents row, metadata entries (drive / drive_error)]. */
function document_stored_columns(array $stored): array
{
    $meta = array_intersect_key($stored, ['drive' => 1, 'drive_error' => 1]);

    return [array_diff_key($stored, $meta), $meta];
}
