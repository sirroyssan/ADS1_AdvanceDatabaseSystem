<?php
/**
 * Transactions - actions (POST): status change, remove item, attach/detach document.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/transaction_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/transactions/index.php');
}

$txn = find_or_redirect(transaction_find(request_id()), 'pages/transactions/index.php', 'Transaction');
$back = 'pages/transactions/view.php?id=' . $txn['id'];
require_csrf($back);

$itemId = input_string($_POST, 'item_id');

try {
    switch (input_string($_POST, 'action')) {
        case 'status':
            $status = input_string($_POST, 'status');
            if (!in_array($status, ENUM_TXN_STATUS, true) || in_array($txn['status'], TXN_LOCKED_STATUSES, true)) {
                flash('error', 'That status change is not allowed.');
                break;
            }
            if ($status === 'cancelled' && (int) db_value("SELECT COUNT(*) FROM borrower_obligations WHERE transaction_id = :id AND status <> 'cancelled'", [':id' => $txn['id']]) > 0) {
                flash('error', 'Cancel the borrower obligations of this transaction first.');
                break;
            }
            db_update('transactions', ['status' => $status, 'updated_by' => $currentUser['id']], $txn['id']);
            log_activity('status_change', 'transaction', $txn['id'], $txn['transaction_number'] . ' changed to ' . $status, ['status' => $txn['status']], ['status' => $status]);
            flash('success', 'Transaction status changed to ' . humanize($status) . '.');
            break;

        case 'delete_item':
            if (in_array($txn['status'], TXN_ITEM_LOCKED_STATUSES, true)) {
                flash('error', 'Items of a ' . $txn['status'] . ' transaction can no longer be changed.');
                break;
            }
            $count = db_exec('DELETE FROM transaction_items WHERE id = :i AND transaction_id = :t', [':i' => $itemId, ':t' => $txn['id']]);
            if ($count) {
                log_activity('delete_item', 'transaction', $txn['id'], 'Removed an item from ' . $txn['transaction_number']);
            }
            flash($count ? 'success' : 'error', $count ? 'Item was removed.' : 'Item not found.');
            break;

        case 'attach_document':
            $documentId = input_string($_POST, 'document_id');
            $type = mb_substr(input_string($_POST, 'attachment_type'), 0, 100);
            if (!array_key_exists($documentId, document_options())) {
                flash('error', 'Choose an uploaded document.');
                break;
            }
            if ((int) db_value('SELECT COUNT(*) FROM transaction_attachments WHERE transaction_id = :t AND document_id = :d', [':t' => $txn['id'], ':d' => $documentId]) > 0) {
                flash('error', 'This document is already attached.');
                break;
            }
            db_insert('transaction_attachments', ['id' => new_uuid(), 'transaction_id' => $txn['id'], 'document_id' => $documentId, 'attachment_type' => $type === '' ? null : $type]);
            log_activity('attach_document', 'transaction', $txn['id'], 'Attached a document to ' . $txn['transaction_number']);
            flash('success', 'Document was attached.');
            break;

        case 'detach_document':
            $count = db_exec('DELETE FROM transaction_attachments WHERE id = :i AND transaction_id = :t', [':i' => $itemId, ':t' => $txn['id']]);
            if ($count) {
                log_activity('detach_document', 'transaction', $txn['id'], 'Removed an attachment from ' . $txn['transaction_number']);
            }
            flash($count ? 'success' : 'error', $count ? 'Attachment was removed.' : 'Attachment not found.');
            break;

        default:
            flash('error', 'Unknown action.');
    }
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect($back);
