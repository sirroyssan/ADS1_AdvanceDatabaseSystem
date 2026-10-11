<?php
/**
 * Agreements - actions (POST): add/delete signatory, mark signed/declined,
 * attach/detach document (agreement_documents). When every signatory has
 * signed, a generated/active agreement becomes "signed".
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/agreement_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/agreements/index.php');
}

$agreement = find_or_redirect(agreement_find(request_id()), 'pages/agreements/index.php', 'Agreement');
$back = 'pages/agreements/view.php?id=' . $agreement['id'];
require_csrf($back);

if (in_array($agreement['status'], AGREEMENT_LOCKED_STATUSES, true)) {
    flash('error', 'A ' . $agreement['status'] . ' agreement can no longer be changed.');
    redirect($back);
}

$itemId = input_string($_POST, 'item_id');

try {
    switch (input_string($_POST, 'action')) {
        case 'add_signatory':
            [$data, $errors] = validate_fields(signatory_fields(), $_POST);
            $row = ['user_id' => null, 'borrower_id' => null];
            if (!$errors && $data['signer'] === 'borrower') {
                $row['borrower_id'] = $agreement['borrower_id'];
                $data['name'] ??= trim($agreement['first_name'] . ' ' . $agreement['last_name']);
            } elseif (!$errors && $data['signer'] === 'user') {
                if (!$data['user_id']) {
                    $errors['user_id'] = 'Choose the user.';
                } else {
                    $row['user_id'] = $data['user_id'];
                    $data['name'] ??= signatory_user_options()[$data['user_id']] ?? null;
                }
            }
            if (!$errors && !$data['name']) {
                $errors['name'] = 'Name is required.';
            }
            if ($errors) {
                flash('error', 'Signatory not added: ' . reset($errors));
                break;
            }
            db_insert('agreement_signatories', ['id' => new_uuid(), 'agreement_id' => $agreement['id']] + $row + [
                'name' => $data['name'], 'role' => $data['role'], 'email' => $data['email'], 'mobile' => $data['mobile'], 'status' => 'pending',
            ]);
            log_activity('add_signatory', 'agreement', $agreement['id'], 'Added signatory ' . $data['name']);
            flash('success', $data['name'] . ' was added as a signatory.');
            break;

        case 'sign':
        case 'decline':
            $signed = input_string($_POST, 'action') === 'sign';
            $count = db_exec(
                "UPDATE agreement_signatories SET status = :s, signed_at = :at, signature_reference = :ref
                  WHERE id = :i AND agreement_id = :a AND status = 'pending'",
                [':s' => $signed ? 'signed' : 'declined', ':at' => $signed ? date('Y-m-d H:i:s') : null,
                 ':ref' => $signed ? (mb_substr(input_string($_POST, 'signature_reference'), 0, 255) ?: null) : null,
                 ':i' => $itemId, ':a' => $agreement['id']]
            );
            if (!$count) {
                flash('error', 'Signatory not found or already updated.');
                break;
            }
            log_activity($signed ? 'signed' : 'declined', 'agreement', $agreement['id'], 'A signatory ' . ($signed ? 'signed' : 'declined'));
            flash('success', $signed ? 'Signature recorded.' : 'Signatory marked as declined.');

            $pending = (int) db_value("SELECT COUNT(*) FROM agreement_signatories WHERE agreement_id = :a AND status <> 'signed'", [':a' => $agreement['id']]);
            if ($signed && $pending === 0 && in_array($agreement['status'], ['draft', 'generated', 'active'], true)) {
                db_update('agreements', ['status' => 'signed'], $agreement['id']);
                flash('success', 'Everyone has signed — the agreement is now Signed.');
            }
            break;

        case 'delete_signatory':
            $count = db_exec("DELETE FROM agreement_signatories WHERE id = :i AND agreement_id = :a AND status = 'pending'", [':i' => $itemId, ':a' => $agreement['id']]);
            flash($count ? 'success' : 'error', $count ? 'Signatory was removed.' : 'Only pending signatories can be removed.');
            break;

        case 'attach_document':
            $documentId = input_string($_POST, 'document_id');
            $type = mb_substr(input_string($_POST, 'attachment_type'), 0, 50);
            if (!array_key_exists($documentId, document_options()) || $type === '') {
                flash('error', 'Choose an uploaded document and enter its type.');
                break;
            }
            if ((int) db_value('SELECT COUNT(*) FROM agreement_documents WHERE agreement_id = :a AND document_id = :d', [':a' => $agreement['id'], ':d' => $documentId]) > 0) {
                flash('error', 'This document is already attached.');
                break;
            }
            $version = 1 + (int) db_value('SELECT COALESCE(MAX(version), 0) FROM agreement_documents WHERE agreement_id = :a AND document_type = :t', [':a' => $agreement['id'], ':t' => $type]);
            db_insert('agreement_documents', [
                'id' => new_uuid(), 'agreement_id' => $agreement['id'], 'document_id' => $documentId,
                'document_type' => $type, 'version' => $version, 'uploaded_by' => $currentUser['id'],
            ]);
            log_activity('attach_document', 'agreement', $agreement['id'], 'Attached ' . $type . ' v' . $version);
            flash('success', 'Document was attached as ' . $type . ' version ' . $version . '.');
            break;

        case 'detach_document':
            $count = db_exec('DELETE FROM agreement_documents WHERE id = :i AND agreement_id = :a', [':i' => $itemId, ':a' => $agreement['id']]);
            flash($count ? 'success' : 'error', $count ? 'Attachment was removed.' : 'Attachment not found.');
            break;

        default:
            flash('error', 'Unknown action.');
    }
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect($back);
