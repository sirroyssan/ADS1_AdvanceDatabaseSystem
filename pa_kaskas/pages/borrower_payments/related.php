<?php
/**
 * Borrower Payments - actions (POST): allocate,
 * attach_document, detach_document. Allocations cannot be removed
 * (financial history); a wrong allocation is corrected with an
 * adjustment on the obligation by an administrator.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/payment_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/borrower_payments/index.php');
}

$payment = find_or_redirect(payment_find(request_id()), 'pages/borrower_payments/index.php', 'Payment');
$back = 'pages/borrower_payments/view.php?id=' . $payment['id'];
require_csrf($back);

try {
    switch (input_string($_POST, 'action')) {
        case 'allocate':
            $amount = str_replace([',', ' ', '₱'], '', input_string($_POST, 'allocated_amount'));
            $scheduleId = input_string($_POST, 'installment_schedule_id');
            $type = mb_substr(input_string($_POST, 'allocation_type', 'installment'), 0, 50) ?: 'installment';
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
                flash('error', 'Enter a valid amount.');
                break;
            }
            $error = payment_allocate($payment, input_string($_POST, 'obligation_id'), $scheduleId === '' ? null : $scheduleId, (float) $amount, $type, $currentUser['id']);
            if ($error !== null) {
                flash('error', $error);
                break;
            }
            log_activity('allocate', 'borrower_payment', $payment['id'], 'Allocated ' . format_money($amount) . ' of ' . $payment['payment_number']);
            notify_borrower($payment['borrower_id'], 'payment_applied', 'Payment ' . $payment['payment_number'] . ' applied',
                format_money($amount) . ' of your payment was applied to your balance.', 'borrower_payment', $payment['id']);
            flash('success', format_money($amount) . ' was applied to the obligation.');
            break;

        case 'attach_document':
            $documentId = input_string($_POST, 'document_id');
            $type = mb_substr(input_string($_POST, 'attachment_type'), 0, 100);
            if (!array_key_exists($documentId, document_options()) || $type === '') {
                flash('error', 'Choose an uploaded document and enter the attachment type.');
                break;
            }
            if ((int) db_value('SELECT COUNT(*) FROM payment_attachments WHERE borrower_payment_id = :p AND document_id = :d', [':p' => $payment['id'], ':d' => $documentId]) > 0) {
                flash('error', 'This document is already attached.');
                break;
            }
            db_insert('payment_attachments', ['id' => new_uuid(), 'borrower_payment_id' => $payment['id'], 'document_id' => $documentId, 'attachment_type' => $type]);
            log_activity('attach_document', 'borrower_payment', $payment['id'], 'Attached a document to ' . $payment['payment_number']);
            drive_backup_document($documentId);   // a proof of payment also goes to Google Drive when connected
            flash('success', 'Document was attached.');
            break;

        case 'detach_document':
            if (in_array($payment['status'], PAYMENT_FINAL_STATUSES, true)) {
                flash('error', 'Attachments of a reviewed payment are kept as proof.');
                break;
            }
            $count = db_exec('DELETE FROM payment_attachments WHERE id = :i AND borrower_payment_id = :p', [':i' => input_string($_POST, 'item_id'), ':p' => $payment['id']]);
            flash($count ? 'success' : 'error', $count ? 'Attachment was removed.' : 'Attachment not found.');
            break;

        default:
            flash('error', 'Unknown action.');
    }
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect($back);
