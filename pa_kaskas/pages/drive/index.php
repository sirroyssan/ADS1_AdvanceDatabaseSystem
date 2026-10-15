<?php
/**
 * Google Drive for proofs of payment (owner): set up the Google sign-in once,
 * connect your Gmail, see what is saved, send pending proofs, disconnect.
 * Secrets are kept in storage/google_drive/ (closed to the web) and never shown again.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/includes/google_drive.php';

$currentUser = require_admin();
$me = $currentUser['id'];

if (is_post()) {
    require_csrf('pages/drive/index.php');
    switch (input_string($_POST, 'action')) {
        case 'client':
            $id = trim(input_string($_POST, 'client_id'));
            $secret = trim(input_string($_POST, 'client_secret'));
            $old = drive_client();
            if (!preg_match('/^[0-9A-Za-z._-]+\.apps\.googleusercontent\.com$/', $id)) {
                flash('error', 'The Client ID looks like 1234567890-abc...apps.googleusercontent.com - copy it again from Google Cloud.');
                break;
            }
            if ($secret === '' && !$old) {
                flash('error', 'Enter the Client secret.');
                break;
            }
            if (!drive_write('client.json', ['client_id' => $id, 'client_secret' => $secret !== '' ? $secret : $old['client_secret'], 'saved_at' => date('c')])) {
                flash('error', drive_storage_message());
                break;
            }
            log_activity('update', 'google_drive', null, 'Google Drive sign-in (OAuth client) saved');
            flash('success', 'Saved. Now click Connect Google Drive.');
            break;

        case 'connect':
            if (!drive_client()) {
                flash('error', 'Save the Client ID and Client secret first.');
                break;
            }
            $_SESSION['drive_oauth_state'] = bin2hex(random_bytes(16));
            header('Location: ' . drive_auth_url($_SESSION['drive_oauth_state']));
            exit;

        case 'sync':
            // A batch at a time so the page never times out; press again for the rest.
            @set_time_limit(300);
            $done = $failed = 0;
            foreach (array_slice(drive_pending_documents($me), 0, 25) as $doc) {
                $r = drive_backup_document($doc['id']);
                $r === 'uploaded' ? $done++ : ($r === 'failed' ? $failed++ : null);
                if ($r === 'failed') {
                    break;   // no connection: stop instead of waiting on every file
                }
            }
            $left = count(drive_pending_documents($me));
            log_activity('sync', 'google_drive', null, 'Sent ' . $done . ' proof(s) of payment to Google Drive');
            flash($failed ? 'error' : 'success', $done . ' proof(s) saved to Google Drive.' . ($failed ? ' Stopped: ' . drive_last_error() : '') . ($left ? ' ' . $left . ' still to send.' : ''));
            break;

        case 'disconnect':
            drive_account_delete($me);
            log_activity('update', 'google_drive', null, 'Google Drive disconnected');
            flash('success', 'Google Drive is disconnected. Files already in Drive stay there; new proofs are kept on this computer only. To remove the app\'s access completely, also remove it at myaccount.google.com → Security → Third-party access.');
            break;
    }
    redirect('pages/drive/index.php');
}

$client = drive_client();
$account = drive_account($me);
$pending = $account ? count(drive_pending_documents($me)) : 0;
$saved = $account ? drive_saved_count($me) : 0;
$curlOk = function_exists('curl_init');
$storageOk = drive_storage_writable();

$pageTitle = 'Google Drive';
$activeNav = 'google_drive';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Google Drive' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Google Drive</h2>
        <p class="page-header__subtitle">Once connected, proofs of payment are saved straight to your Google Drive (folder "<?= e(DRIVE_FOLDER_NAME) ?>") instead of this computer. The computer keeps a proof only when Google cannot be reached; it is sent to Drive later.</p>
    </div>
</section>

<?php if (!$curlOk): ?>
    <div class="alert alert--error"><?= icon('alert') ?><span>The PHP <strong>curl</strong> extension is off, so the app cannot reach Google. In XAMPP it is normally on; check php.ini (extension=curl) and restart Apache.</span></div>
<?php endif; ?>

<?php if (!$storageOk): ?>
    <div class="alert alert--error"><?= icon('alert') ?><span><?= e(drive_storage_message()) ?></span></div>
<?php endif; ?>

<section class="panel">
    <header class="panel__header"><h3><?= icon('folder') ?> Your connection</h3></header>
    <?php if ($account): ?>
        <?= render_details([
            'Google account' => e($account['email'] ?? 'Connected'),
            'Folder'         => !empty($account['folder_link'])
                ? '<a href="' . e($account['folder_link']) . '" target="_blank" rel="noopener">' . e(DRIVE_FOLDER_NAME) . '</a>' : e(DRIVE_FOLDER_NAME),
            'Connected'      => e(format_datetime($account['connected_at'] ?? '')),
            'Proofs in Drive' => e($saved),
            'Not yet in Drive' => $pending ? '<strong>' . e($pending) . '</strong> (no connection at the time, or uploaded before Drive was connected)' : '0',
        ]) ?>
        <div class="form-actions no-print">
            <?= post_button(url('pages/drive/index.php'), ['action' => 'disconnect'], 'Disconnect', 'x', 'btn btn--outline',
                'Disconnect Google Drive? Files already in Drive stay there; new proofs are kept on this computer only.', false, 'Disconnect Google Drive?') ?>
            <?php if ($pending): ?>
                <?= post_button(url('pages/drive/index.php'), ['action' => 'sync'], 'Send ' . min(25, $pending) . ' pending proof(s) now', 'repeat', 'btn btn--primary') ?>
            <?php endif; ?>
        </div>
        <p class="field-help">The app can only see the files it saved itself (permission "drive.file"), never the rest of your Drive. Each new proof is uploaded when the payment is saved; if Google cannot be reached the payment is still saved and the proof waits here.</p>
    <?php elseif ($client): ?>
        <p>Not connected yet.</p>
        <div class="form-actions no-print">
            <?= post_button(url('pages/drive/index.php'), ['action' => 'connect'], 'Connect Google Drive', 'folder', 'btn btn--primary') ?>
        </div>
        <p class="field-help">You will sign in with your Gmail on Google's page and allow "See, edit, create and delete only the specific Google Drive files you use with this app". Then you come back here.</p>
    <?php else: ?>
        <p class="empty-inline">Set up the Google sign-in below first (once).</p>
    <?php endif; ?>
</section>

<section class="panel">
    <header class="panel__header"><h3><?= icon('shield') ?> Google sign-in setup (once)</h3></header>
    <ol class="setup-steps">
        <li>Open <a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">console.cloud.google.com</a> with your Gmail and create a project, e.g. <em>PA-KASKAS</em>.</li>
        <li><strong>APIs &amp; Services → Library</strong>: search <em>Google Drive API</em> and click <strong>Enable</strong>.</li>
        <li><strong>APIs &amp; Services → OAuth consent screen</strong> (Google Auth Platform): User type <strong>External</strong>, app name <em>PA-KASKAS</em>, your Gmail as support and developer email. Under <strong>Audience</strong>, click <strong>Publish app</strong> (In production) - otherwise Google signs you out of Drive every 7 days. Google may show "unverified app" when you connect; that is fine for your own account (Advanced → Go to PA-KASKAS).</li>
        <li><strong>APIs &amp; Services → Credentials → Create credentials → OAuth client ID</strong>: type <strong>Web application</strong>. Under <strong>Authorized redirect URIs</strong> add exactly:<br>
            <code class="copy-code"><?= e(drive_redirect_uri()) ?></code></li>
        <li>Copy the <strong>Client ID</strong> and <strong>Client secret</strong> into the form below and save, then click <strong>Connect Google Drive</strong> above.</li>
    </ol>
    <p class="field-help">Connect from this computer (the address above). Borrowers on their phones do not need anything: their proofs go to your Drive.</p>
    <form method="post" action="<?= e(url('pages/drive/index.php')) ?>" class="form-card" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="client">
        <div class="form-grid">
            <div class="form-group">
                <label for="client_id">Client ID</label>
                <input type="text" id="client_id" name="client_id" value="<?= e($client['client_id'] ?? '') ?>" placeholder="1234567890-abc123.apps.googleusercontent.com" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="client_secret">Client secret</label>
                <input type="password" id="client_secret" name="client_secret" value="" placeholder="<?= $client ? 'Saved - leave blank to keep it' : 'GOCSPX-…' ?>" autocomplete="new-password">
                <p class="field-help">Kept on this computer only (storage/google_drive), never shown again.</p>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= icon('check') ?> <span class="btn__label">Save</span></button>
        </div>
    </form>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
