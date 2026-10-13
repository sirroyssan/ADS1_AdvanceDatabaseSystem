<?php
/**
 * Transactions - actions (POST): status change, remove item, attach/detach document,
 * and the penalties of a purchase assigned to a borrower (check now, apply, waive/delete).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/transaction_functions.php';
require_once APP_ROOT . '/pages/obligations/obligation_functions.php';   // penalty form fields
require_once APP_ROOT . '/pages/penalty_rules/penalty_rule_functions.php';

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
            card_usage_apply([$txn['credit_card_id']], fn () => db_update('transactions', ['status' => $status, 'updated_by' => $currentUser['id']], $txn['id']));
            log_activity('status_change', 'transaction', $txn['id'], $txn['transaction_number'] . ' changed to ' . $status, ['status' => $txn['status']], ['status' => $status]);
            borrowed_notify_update($txn, $txn['id']);
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

        case 'run_penalties':
        case 'add_penalty':
        case 'terminate_penalties':
            // Penalties of a purchase the borrower repays to you (tracked on the transaction itself).
            $purchase = $txn['borrower_id'] ? (borrowed_owner_purchases(null, $txn['id'])[0] ?? null) : null;
            if (!$purchase || $purchase['ledger']['mode'] !== 'transaction') {
                flash('error', 'Penalties apply to a card purchase assigned to a borrower.');
                break;
            }
            $action = input_string($_POST, 'action');
            if ($action === 'run_penalties') {
                $created = borrowed_penalty_run(db_row('SELECT * FROM transactions WHERE id = :id', [':id' => $txn['id']]));
                if ($created) {
                    flash('success', $created . ' new penalt' . ($created === 1 ? 'y was' : 'ies were') . ' charged.');
                } elseif (!borrowed_rules_for_card($txn['credit_card_id'])) {
                    $bank = (string) db_value('SELECT issuer FROM credit_cards WHERE id = :c', [':c' => $txn['credit_card_id']]);
                    flash('info', 'No penalty was charged: there is no active penalty rule for ' . $bank . '. In Penalty Rules, set the rule\'s Bank to ' . $bank . ' and make it active.');
                } elseif ($purchase['ledger']['status'] !== 'overdue') {
                    flash('info', 'No penalty was charged: nothing on this purchase is past its repay-by date.');
                } else {
                    flash('info', 'No new penalty is due: every overdue month was already charged (months before a rule took effect or was last changed are never back-filled).');
                }
                break;
            }
            if ($action === 'add_penalty') {
                [$data, $errors] = validate_fields(penalty_fields(), $_POST);
                $rule = $data['penalty_rule_id'] ? penalty_rule_find($data['penalty_rule_id']) : null;
                if (!$errors && !$rule) {
                    $errors['penalty_rule_id'] = 'Choose an active penalty rule.';
                }
                if ($errors) {
                    flash('error', 'Penalty not applied: ' . reset($errors));
                    break;
                }
                $amount = $data['amount'] ?? number_format(penalty_rule_amount($rule, (float) $data['calculation_base']), 2, '.', '');
                db_transaction(static function () use ($txn, $rule, $data, $amount) {
                    db_insert('applied_penalties', [
                        'id' => new_uuid(), 'obligation_id' => null, 'transaction_id' => $txn['id'], 'penalty_rule_id' => $rule['id'],
                        'applied_date' => $data['applied_date'], 'overdue_days' => $data['overdue_days'] ?? 0,
                        'calculation_base' => $data['calculation_base'], 'amount' => $amount, 'paid_amount' => '0.00',
                        'remaining_amount' => $amount, 'notes' => $data['notes'],
                    ]);
                    borrowed_sync_penalty_payments($txn['id']);   // confirmed repayments pay penalties first
                });
                log_activity('apply_penalty', 'transaction', $txn['id'], 'Applied penalty "' . $rule['name'] . '" of ' . format_money($amount) . ' on ' . $txn['transaction_number']);
                notify_borrower($txn['borrower_id'], 'penalty_added', 'Late charge: ' . $txn['transaction_number'],
                    'A late charge of ' . format_money($amount) . ' (' . $rule['name'] . ') was added to ' . $txn['transaction_number'] . '.', 'borrowed_purchase', $txn['id']);
                flash('success', 'Penalty of ' . format_money($amount) . ' was applied.');
                break;
            }
            // Waive or delete several at once (owners/administrators, reason required).
            $ids = array_values(array_unique(array_filter((array) ($_POST['item_ids'] ?? []), static fn ($v) => is_string($v) && is_valid_uuid($v))));
            $mode = input_string($_POST, 'mode');
            $reason = input_string($_POST, 'reason');
            if (!$ids) {
                flash('error', 'Tick at least one penalty first.');
                break;
            }
            if (mb_strlen(trim($reason)) < PENALTY_REASON_MIN) {
                flash('error', 'Enter a reason of at least ' . PENALTY_REASON_MIN . ' characters (e.g. "Cash payment received offline but not yet recorded").');
                break;
            }
            $done = 0;
            $skipped = [];
            foreach ($ids as $penaltyId) {
                $error = penalty_terminate($penaltyId, $txn['id'], $mode, $reason, $currentUser, 'transaction');
                $error === null ? $done++ : $skipped[$error] = ($skipped[$error] ?? 0) + 1;
            }
            if ($done) {
                notify_borrower($txn['borrower_id'], 'penalty_' . ($mode === 'waive' ? 'waived' : 'removed'), 'Late charges ' . ($mode === 'waive' ? 'waived' : 'removed') . ': ' . $txn['transaction_number'],
                    $done . ' late charge(s) on ' . $txn['transaction_number'] . ' were ' . ($mode === 'waive' ? 'waived' : 'removed') . '. Reason: ' . trim($reason), 'borrowed_purchase', $txn['id']);
                flash('success', $done . ' penalt' . ($done === 1 ? 'y was' : 'ies were') . ' ' . ($mode === 'waive' ? 'waived' : 'deleted') . '. The reason was recorded.');
            }
            foreach ($skipped as $why => $count) {
                flash(str_contains($why, 'nothing left to waive') ? 'info' : 'error', $count . ' skipped: ' . $why);
            }
            break;

        default:
            flash('error', 'Unknown action.');
    }
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect($back);
