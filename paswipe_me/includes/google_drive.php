<?php
/**
 * Proofs of payment saved to Google Drive (personal Gmail) - no schema change.
 * Once the owner's Drive is connected, a new proof goes straight to Drive
 * (storage_key "gdrive:<file id>", nothing saved on the computer); the
 * computer is only the fallback when Drive cannot be reached.
 *
 *   - The app signs in to Google with OAuth 2.0 (scope drive.file: it can only
 *     see and change the files it creates itself - never the rest of the Drive).
 *   - Client ID / secret (one Google Cloud OAuth client for the app) and each
 *     owner's connection (refresh token, the "PA-KASKAS Proofs" folder) are
 *     kept in storage/google_drive/ - storage/ is closed to the web and the
 *     folder is not committed or zipped. Secrets are never shown again.
 *   - Each proof goes to the Drive of the owner who receives the payment
 *     (documents.owner_user_id). Its Drive file id and link are kept in the
 *     document's metadata JSON: metadata.drive = {file_id, link, uploaded_at}.
 *   - Drive never blocks a payment: when it cannot be reached the proof stays
 *     on the computer, the error is noted (metadata.drive_error) and "Send
 *     pending proofs" uploads it later. A proof missing on the computer is
 *     downloaded back from Drive when opened.
 */

defined('APP_ROOT') or exit('No direct access.');

const DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.file openid email';
const DRIVE_FOLDER_NAME = 'PA-KASKAS Proofs';
const DRIVE_TIMEOUT = 25;   // seconds per request to Google
const DRIVE_KEY_PREFIX = 'gdrive:';   // documents.storage_key of a proof kept only in Google Drive

function drive_dir(): string
{
    $dir = APP_ROOT . '/storage/google_drive';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    return $dir;
}

function drive_read(string $file): ?array
{
    $path = drive_dir() . '/' . $file;
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : null;
}

function drive_write(string $file, array $data): bool
{
    $path = drive_dir() . '/' . $file;
    $ok = @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
    if ($ok) {
        @chmod($path, 0600);
    }

    return $ok;
}

/** The app's Google Cloud OAuth client: {client_id, client_secret} or null. */
function drive_client(): ?array
{
    $c = drive_read('client.json');

    return $c && !empty($c['client_id']) && !empty($c['client_secret']) ? $c : null;
}

/** An owner's Drive connection, or null. */
function drive_account(?string $userId): ?array
{
    return $userId && is_valid_uuid($userId) ? drive_read('account-' . $userId . '.json') : null;
}

/** True when the owner's Drive is set up and connected (no request to Google). */
function drive_is_connected(?string $ownerId): bool
{
    return drive_client() !== null && drive_account($ownerId) !== null;
}

/** True when the Drive settings folder can be written (else nothing can be saved). */
function drive_storage_writable(): bool
{
    $dir = drive_dir();

    return is_dir($dir) && is_writable($dir);
}

function drive_storage_message(): string
{
    return 'The Google Drive settings could not be saved: the folder ' . APP_ROOT . '/storage is not writable. On XAMPP for macOS, run in Terminal: sudo chmod -R 777 ' . APP_ROOT . '/storage - then try again.';
}

/** True when the document lives only in Google Drive. */
function drive_is_drive_key(?string $storageKey): bool
{
    return is_string($storageKey) && str_starts_with($storageKey, DRIVE_KEY_PREFIX);
}

function drive_account_save(string $userId, array $account): bool
{
    return drive_write('account-' . $userId . '.json', $account);
}

function drive_account_delete(string $userId): void
{
    @unlink(drive_dir() . '/account-' . $userId . '.json');
}

/** The address Google sends the user back to (it must be added to the OAuth client exactly). */
function drive_redirect_uri(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';

    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('pages/drive/callback.php');
}

function drive_auth_url(string $state): string
{
    $client = drive_client();

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $client['client_id'] ?? '', 'redirect_uri' => drive_redirect_uri(), 'response_type' => 'code',
        'scope' => DRIVE_SCOPE, 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state,
    ]);
}

/**
 * One HTTPS request to Google. Returns [status, body]; status 0 = no connection.
 * TLS is always verified; when PHP has no CA list set, the usual macOS / XAMPP ones are used.
 */
function drive_http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    if (!function_exists('curl_init')) {
        return [0, 'The PHP curl extension is not enabled.'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => DRIVE_TIMEOUT, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if (!ini_get('curl.cainfo') && !ini_get('openssl.cafile')) {
        foreach (['/Applications/XAMPP/xamppfiles/share/curl/curl-ca-bundle.crt', '/etc/ssl/cert.pem', '/etc/ssl/certs/ca-certificates.crt'] as $ca) {
            if (is_readable($ca)) {
                curl_setopt($ch, CURLOPT_CAINFO, $ca);
                break;
            }
        }
    }
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return $response === false ? [0, $error ?: 'No connection to Google.'] : [$status, (string) $response];
}

/** Google's error message from a response body. */
function drive_error_text(string $body): string
{
    $j = json_decode($body, true);
    $msg = $j['error_description'] ?? ($j['error']['message'] ?? ($j['error'] ?? null));

    return is_string($msg) && $msg !== '' ? $msg : mb_substr(trim($body), 0, 200);
}

/** After "Connect": trade the code for tokens and keep the connection. Returns an error or null. */
function drive_connect(string $userId, string $code): ?string
{
    $client = drive_client();
    if (!$client) {
        return 'Save the Client ID and Client secret first.';
    }
    [$status, $body] = drive_http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'code' => $code, 'client_id' => $client['client_id'], 'client_secret' => $client['client_secret'],
        'redirect_uri' => drive_redirect_uri(), 'grant_type' => 'authorization_code',
    ]));
    $t = json_decode($body, true);
    if ($status !== 200 || empty($t['access_token'])) {
        return 'Google did not connect: ' . drive_error_text($body);
    }
    if (empty($t['refresh_token'])) {
        return 'Google did not give a long-term permission. Remove PA-KASKAS from your Google account\'s third-party access and connect again.';
    }
    // The Gmail address, from the ID token Google just returned over HTTPS (shown on the settings page).
    $email = null;
    if (!empty($t['id_token']) && count($parts = explode('.', $t['id_token'])) === 3) {
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        $email = $claims['email'] ?? null;
    }
    if (!drive_account_save($userId, [
        'email' => $email, 'refresh_token' => $t['refresh_token'], 'access_token' => $t['access_token'],
        'expires_at' => time() + (int) ($t['expires_in'] ?? 3600) - 60, 'folder_id' => null, 'connected_at' => date('c'),
    ])) {
        return drive_storage_message();
    }

    return drive_folder($userId) === null ? 'Connected, but the Drive folder could not be created yet: ' . (drive_last_error() ?? 'unknown error') : null;
}

function drive_last_error(?string $set = null): ?string
{
    static $last = null;
    if ($set !== null) {
        $last = $set;
    }

    return $last;
}

/** A valid access token for the owner (refreshed when needed), or null. */
function drive_token(string $userId): ?string
{
    $a = drive_account($userId);
    $client = drive_client();
    if (!$a || !$client) {
        drive_last_error('Google Drive is not connected.');
        return null;
    }
    if (!empty($a['access_token']) && (int) ($a['expires_at'] ?? 0) > time()) {
        return $a['access_token'];
    }
    [$status, $body] = drive_http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'client_id' => $client['client_id'], 'client_secret' => $client['client_secret'],
        'refresh_token' => $a['refresh_token'], 'grant_type' => 'refresh_token',
    ]));
    $t = json_decode($body, true);
    if ($status !== 200 || empty($t['access_token'])) {
        drive_last_error($status === 0 ? 'No internet connection to Google (' . $body . ').' : 'Google refused the connection: ' . drive_error_text($body) . ' - connect Google Drive again.');
        return null;
    }
    $a['access_token'] = $t['access_token'];
    $a['expires_at'] = time() + (int) ($t['expires_in'] ?? 3600) - 60;
    drive_account_save($userId, $a);

    return $a['access_token'];
}

/** The "PA-KASKAS Proofs" folder in the owner's Drive (created when missing). */
function drive_folder(string $userId): ?string
{
    $token = drive_token($userId);
    if ($token === null) {
        return null;
    }
    $a = drive_account($userId);
    if (!empty($a['folder_id'])) {
        [$status, $body] = drive_http('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($a['folder_id']) . '?fields=id,trashed', ['Authorization: Bearer ' . $token]);
        $f = json_decode($body, true);
        if ($status === 200 && empty($f['trashed'])) {
            return $a['folder_id'];
        }
    }
    [$status, $body] = drive_http('POST', 'https://www.googleapis.com/drive/v3/files?fields=id,webViewLink', ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        json_encode(['name' => DRIVE_FOLDER_NAME, 'mimeType' => 'application/vnd.google-apps.folder']));
    $f = json_decode($body, true);
    if ($status !== 200 || empty($f['id'])) {
        drive_last_error('The Drive folder could not be created: ' . drive_error_text($body));
        return null;
    }
    $a['folder_id'] = $f['id'];
    $a['folder_link'] = $f['webViewLink'] ?? null;
    drive_account_save($userId, $a);

    return $f['id'];
}

/** Upload one file into the owner's folder. Returns ['id', 'link'] or null (see drive_last_error). */
function drive_upload(string $userId, string $path, string $name, string $mime): ?array
{
    $folder = drive_folder($userId);
    $token = $folder ? drive_token($userId) : null;
    if ($folder === null || $token === null) {
        return null;
    }
    $boundary = 'pakaskas' . bin2hex(random_bytes(8));
    $body = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
        . json_encode(['name' => $name, 'parents' => [$folder]], JSON_UNESCAPED_UNICODE) . "\r\n"
        . "--$boundary\r\nContent-Type: $mime\r\n\r\n" . file_get_contents($path) . "\r\n--$boundary--";
    [$status, $resp] = drive_http('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,webViewLink',
        ['Authorization: Bearer ' . $token, 'Content-Type: multipart/related; boundary=' . $boundary], $body);
    $f = json_decode($resp, true);
    if ($status !== 200 || empty($f['id'])) {
        drive_last_error($status === 0 ? 'No internet connection to Google (' . $resp . ').' : 'Google Drive refused the upload: ' . drive_error_text($resp));
        return null;
    }

    return ['id' => $f['id'], 'link' => $f['webViewLink'] ?? null];
}

/** Remove a file the app created (used when the payment it belonged to was not saved). */
function drive_delete(string $userId, string $fileId): bool
{
    $token = drive_token($userId);
    if ($token === null) {
        return false;
    }
    [$status] = drive_http('DELETE', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId), ['Authorization: Bearer ' . $token]);

    return $status === 204 || $status === 200;
}

/** A Drive file's content, or null. */
function drive_download(string $userId, string $fileId): ?string
{
    $token = drive_token($userId);
    if ($token === null) {
        return null;
    }
    [$status, $body] = drive_http('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?alt=media', ['Authorization: Bearer ' . $token]);

    return $status === 200 ? $body : null;
}

function drive_doc_meta(array $doc): array
{
    $m = json_decode((string) ($doc['metadata'] ?? '{}'), true);

    return is_array($m) ? $m : [];
}

/**
 * Save one proof of payment to its owner's Google Drive (once). Never throws:
 * returns 'uploaded', 'already', 'not_connected', 'skipped' or 'failed'.
 */
function drive_backup_document(string $documentId): string
{
    try {
        $doc = db_row('SELECT * FROM documents WHERE id = :id', [':id' => $documentId]);
        if (!$doc || $doc['document_type'] !== 'payment_proof' || (int) $doc['is_deleted'] === 1) {
            return 'skipped';
        }
        $meta = drive_doc_meta($doc);
        if (!empty($meta['drive']['file_id']) || drive_is_drive_key($doc['storage_key'])) {
            return 'already';
        }
        $owner = $doc['owner_user_id'];
        if (!drive_client() || !drive_account($owner)) {
            return 'not_connected';
        }
        require_once APP_ROOT . '/pages/documents/document_functions.php';
        $path = document_path($doc['storage_key']);
        if ($path === null) {
            return 'skipped';
        }
        // A readable name in Drive: date · payment number · borrower · original file name.
        $pay = db_row(
            "SELECT p.payment_number, p.payment_date, CONCAT(b.first_name, ' ', b.last_name) AS borrower
               FROM payment_attachments pa JOIN borrower_payments p ON p.id = pa.borrower_payment_id JOIN borrowers b ON b.id = p.borrower_id
              WHERE pa.document_id = :d ORDER BY p.created_at LIMIT 1",
            [':d' => $documentId]
        );
        $name = trim(($pay ? $pay['payment_date'] . ' ' . $pay['payment_number'] . ' ' . $pay['borrower'] : substr((string) $doc['uploaded_at'], 0, 10) . ' proof') . ' - ' . $doc['original_filename']);
        $file = drive_upload($owner, $path, $name, $doc['mime_type'] ?: 'application/octet-stream');
        if ($file === null) {
            $meta['drive_error'] = ['message' => drive_last_error(), 'at' => date('c')];
            db_update('documents', ['metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], $documentId);
            error_log('[' . APP_NAME . '] Google Drive upload failed for document ' . $documentId . ': ' . drive_last_error());
            return 'failed';
        }
        unset($meta['drive_error']);
        $meta['drive'] = ['file_id' => $file['id'], 'link' => $file['link'], 'uploaded_at' => date('c')];
        db_update('documents', ['metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], $documentId);

        return 'uploaded';
    } catch (Throwable $e) {
        error_log('[' . APP_NAME . '] Google Drive upload error for document ' . $documentId . ': ' . $e->getMessage());
        return 'failed';
    }
}

/** Proofs of the owner not yet in Drive. */
function drive_pending_documents(string $ownerId): array
{
    return db_all(
        "SELECT id, original_filename, uploaded_at, metadata FROM documents
          WHERE owner_user_id = :o AND document_type = 'payment_proof' AND is_deleted = 0
            AND (JSON_EXTRACT(metadata, '$.drive.file_id') IS NULL)
          ORDER BY uploaded_at",
        [':o' => $ownerId]
    );
}

/** Proofs of the owner already in Drive. */
function drive_saved_count(string $ownerId): int
{
    return (int) db_value(
        "SELECT COUNT(*) FROM documents WHERE owner_user_id = :o AND document_type = 'payment_proof' AND is_deleted = 0
            AND JSON_EXTRACT(metadata, '$.drive.file_id') IS NOT NULL",
        [':o' => $ownerId]
    );
}

/**
 * A proof missing on the computer: download it back from Drive into its
 * place. Returns the local path, or null.
 */
function drive_restore_document(array $doc): ?string
{
    require_once APP_ROOT . '/pages/documents/document_functions.php';
    $meta = drive_doc_meta($doc);
    if (drive_is_drive_key($doc['storage_key']) || empty($meta['drive']['file_id']) || empty($doc['owner_user_id'])) {
        return null;
    }
    $content = drive_download($doc['owner_user_id'], $meta['drive']['file_id']);
    if ($content === null) {
        return null;
    }
    $target = APP_ROOT . '/' . DOCUMENT_STORAGE_DIR . '/' . $doc['storage_key'];
    if (!preg_match('#^[0-9]{4}/[0-9]{2}/[0-9a-f-]{36}\.[a-z0-9]{1,5}$#', (string) $doc['storage_key'])) {
        return null;   // only the app's own storage keys
    }
    if (!is_dir(dirname($target))) {
        @mkdir(dirname($target), 0775, true);
    }

    return @file_put_contents($target, $content) !== false ? document_path($doc['storage_key']) : null;
}

/**
 * The file's content for a download: from this computer, or from Google
 * Drive (a Drive-only proof, or a local copy that went missing). Null when
 * it cannot be found.
 */
function drive_document_content(array $doc): ?string
{
    require_once APP_ROOT . '/pages/documents/document_functions.php';
    if (drive_is_drive_key($doc['storage_key'])) {
        return empty($doc['owner_user_id']) ? null : drive_download($doc['owner_user_id'], substr($doc['storage_key'], strlen(DRIVE_KEY_PREFIX)));
    }
    $path = document_path($doc['storage_key']) ?? drive_restore_document($doc);
    if ($path !== null) {
        $content = @file_get_contents($path);
        return $content === false ? null : $content;
    }
    // Missing here and storage not writable: still serve it straight from Drive.
    $meta = drive_doc_meta($doc);

    return !empty($meta['drive']['file_id']) && !empty($doc['owner_user_id']) ? drive_download($doc['owner_user_id'], $meta['drive']['file_id']) : null;
}

/** "In Google Drive" link for the owner (empty when not saved there). */
function drive_link_html(array $doc): string
{
    $meta = drive_doc_meta($doc);
    if (!empty($meta['drive']['link'])) {
        return ' <a class="text-muted" href="' . e($meta['drive']['link']) . '" target="_blank" rel="noopener">' . icon('folder', 'icon icon--sm') . ' In Google Drive</a>';
    }

    return !empty($meta['drive_error']) ? ' <span class="text-muted" title="' . e($meta['drive_error']['message'] ?? '') . '">· not yet in Google Drive</span>' : '';
}
