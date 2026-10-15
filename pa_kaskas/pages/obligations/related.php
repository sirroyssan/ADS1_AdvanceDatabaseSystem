<?php
/**
 * Obligations - actions (POST): status change, apply penalty, mark penalty paid.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/obligation_functions.php';
require_once __DIR__ . '/../penalty_rules/penalty_rule_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/obligations/index.php');
}

$obligation = find_or_redirect(obligation_find(request_id()), 'pages/obligations/index.php', 'Obligation');
$back = 'pages/obligations/view.php?id=' . $obligation['id'];
require_csrf($back);

try {
    switch (input_string($_POST, 'action')) {
        case 'status':
            $status = input_string($_POST, 'status');
            $paid = (float) $obligation['total_paid'];
            if (!in_array($status, OBLIGATION_MANUAL_STATUSES, true) || $obligation['status'] === 'paid') {
                flash('error', 'That status change is not allowed.');
                break;
            }
            if (in_array($status, ['cancelled', 'pending'], true) && $paid > 0) {
                flash('error', 'An obligation with payments cannot be ' . ($status === 'cancelled' ? 'cancelled' : 'set to pending') . '. Write it off instead.');
                break;
            }
            $update = ['status' => $status, 'updated_by' => $currentUser['id']];
            if (in_array($status, ['cancelled', 'written_off'], true)) {
                $update['completed_at'] = date('Y-m-d H:i:s');
            }
            db_update('borrower_obligations', $update, $obligation['id']);
            log_activity('status_change', 'obligation', $obligation['id'], $obligation['obligation_number'] . ' changed to ' . $status, ['status' => $obligation['status']], ['status' => $status]);
            notify_borrower_changes($obligation['borrower_id'], $obligation['obligation_number'], $obligation, ['status' => $status], OBLIGATION_NOTICE_FIELDS, 'obligation', $obligation['id']);
            flash('success', 'Obligation status changed to ' . humanize($status) . '.');
            break;

        case 'add_penalty':
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
            db_insert('applied_penalties', [
                'id' => new_uuid(), 'obligation_id' => $obligation['id'], 'penalty_rule_id' => $rule['id'],
                'applied_date' => $data['applied_date'], 'overdue_days' => $data['overdue_days'] ?? 0,
                'calculation_base' => $data['calculation_base'], 'amount' => $amount, 'paid_amount' => '0.00',
                'remaining_amount' => $amount, 'notes' => $data['notes'],
            ]);
            log_activity('apply_penalty', 'obligation', $obligation['id'], 'Applied penalty "' . $rule['name'] . '" of ' . format_money($amount));
            notify_borrower($obligation['borrower_id'], 'penalty_added', 'Late charge: ' . $obligation['obligation_number'],
                'A late charge of ' . format_money($amount) . ' (' . $rule['name'] . ') was added to ' . $obligation['obligation_number'] . '.', 'obligation', $obligation['id']);
            flash('success', 'Penalty of ' . format_money($amount) . ' was applied.');
            break;

        case 'terminate_penalties':
            // Several at once: same rules as one (owners/administrators, reason required).
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
                $error = penalty_terminate($penaltyId, $obligation['id'], $mode, $reason, $currentUser);
                $error === null ? $done++ : $skipped[$error] = ($skipped[$error] ?? 0) + 1;
            }
            if ($done) {
                notify_borrower($obligation['borrower_id'], 'penalty_' . ($mode === 'waive' ? 'waived' : 'removed'), 'Late charges ' . ($mode === 'waive' ? 'waived' : 'removed') . ': ' . $obligation['obligation_number'],
                    $done . ' late charge(s) on ' . $obligation['obligation_number'] . ' were ' . ($mode === 'waive' ? 'waived' : 'removed') . '. Reason: ' . trim($reason), 'obligation', $obligation['id']);
                flash('success', $done . ' penalt' . ($done === 1 ? 'y was' : 'ies were') . ' ' . ($mode === 'waive' ? 'waived' : 'deleted') . '. The reason was recorded.');
            }
            foreach ($skipped as $why => $count) {
                // Already-waived rows are harmless when "select all" is used.
                flash(str_contains($why, 'nothing left to waive') ? 'info' : 'error', $count . ' skipped: ' . $why);
            }
            break;

        case 'terminate_penalty':
            // Waive or delete: owners/administrators only (require_admin above + check inside), reason required.
            $mode = input_string($_POST, 'mode');
            $error = penalty_terminate(input_string($_POST, 'item_id'), $obligation['id'], $mode, input_string($_POST, 'reason'), $currentUser);
            if ($error !== null) {
                flash('error', $error);
                redirect('pages/obligations/view.php?id=' . $obligation['id'] . '&penalty=' . rawurlencode(input_string($_POST, 'item_id')) . '#terminate-penalty');
            }
            notify_borrower($obligation['borrower_id'], 'penalty_' . ($mode === 'waive' ? 'waived' : 'removed'), 'Late charge ' . ($mode === 'waive' ? 'waived' : 'removed') . ': ' . $obligation['obligation_number'],
                'A late charge on ' . $obligation['obligation_number'] . ' was ' . ($mode === 'waive' ? 'waived' : 'removed') . '. Reason: ' . trim(input_string($_POST, 'reason')), 'obligation', $obligation['id']);
            flash('success', $mode === 'waive' ? 'Penalty was waived. The reason was recorded.' : 'Penalty was deleted. The reason was recorded in the activity log.');
            break;

        case 'run_penalties':
            $created = db_transaction(fn () => penalty_run_obligation(db_row('SELECT * FROM borrower_obligations WHERE id = :id FOR UPDATE', [':id' => $obligation['id']])));
            if ($created) {
                flash('success', $created . ' new penalt' . ($created === 1 ? 'y was' : 'ies were') . ' charged.');
            } else {
                flash('info', penalty_nothing_reason(db_row('SELECT * FROM borrower_obligations WHERE id = :id', [':id' => $obligation['id']])));
            }
            break;

        default:
            flash('error', 'Unknown action.');
    }
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect($back);
