<?php
/**
 * Google Drive - Google sends the owner back here after "Connect Google Drive".
 * The state must match the one this session started with (no forged callbacks).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/includes/google_drive.php';

$currentUser = require_admin();

$state = input_string($_GET, 'state');
$expected = $_SESSION['drive_oauth_state'] ?? '';
unset($_SESSION['drive_oauth_state']);

if ($expected === '' || !hash_equals($expected, $state)) {
    flash('error', 'The Google sign-in could not be checked. Click Connect Google Drive again.');
} elseif (input_string($_GET, 'error') !== '') {
    flash('error', 'Google Drive was not connected: ' . input_string($_GET, 'error') . '.');
} elseif (($code = input_string($_GET, 'code')) === '') {
    flash('error', 'Google did not send a sign-in code. Try again.');
} elseif ($error = drive_connect($currentUser['id'], $code)) {
    flash('error', $error);
} else {
    $account = drive_account($currentUser['id']);
    log_activity('update', 'google_drive', null, 'Google Drive connected' . (!empty($account['email']) ? ' (' . $account['email'] . ')' : ''));
    flash('success', 'Google Drive is connected' . (!empty($account['email']) ? ' as ' . $account['email'] : '') . '. New proofs of payment are saved in the "' . DRIVE_FOLDER_NAME . '" folder.');
}
redirect('pages/drive/index.php');
