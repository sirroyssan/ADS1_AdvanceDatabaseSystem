<?php
/**
 * Approve or reject an authorization request (POST, the card owner only).
 * The owner is checked here on the server: signed in with an owner/admin role,
 * the request is on their own books, and they are not the requester.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/includes/authorizations.php';

$currentUser = require_admin();
if (!is_post()) {
    redirect('pages/authorizations/index.php');
}
$req = find_or_redirect(auth_find(request_id()), 'pages/authorizations/index.php', 'Request');
$back = 'pages/authorizations/view.php?id=' . $req['id'];
require_csrf($back);

if ($req['status'] !== 'pending') {
    flash('info', 'This request was already decided.');
} elseif ($req['requested_by'] === $currentUser['id']) {
    flash('error', 'You cannot decide your own request.');
} elseif (input_string($_POST, 'decision') === 'reject') {
    $note = trim(input_string($_POST, 'note'));
    if (mb_strlen($note) < 3) {
        flash('error', 'Write a short note for the staff member when rejecting.');
    } else {
        auth_reject($req, $currentUser, mb_substr($note, 0, 1000));
        flash('success', 'Request ' . $req['request_number'] . ' was rejected. Nothing was changed.');
    }
} elseif (input_string($_POST, 'decision') === 'approve') {
    $error = auth_approve($req['id'], $currentUser, 'session', mb_substr(trim(input_string($_POST, 'note')), 0, 1000) ?: null);
    $error === null
        ? flash('success', 'Approved and applied: ' . db_value('SELECT result FROM authorization_requests WHERE id = :id', [':id' => $req['id']]))
        : flash('error', 'The change could not be applied, nothing was changed: ' . $error);
}

redirect($back);
