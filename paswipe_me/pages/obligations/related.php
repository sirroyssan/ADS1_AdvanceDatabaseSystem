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
            if ($status === 'overdue' && (float) $obligation['overdue_amount'] == 0.0) {
                $update['overdue_amount'] = $obligation['outstanding_amount'];
            }
            if (in_array($status, ['cancelled', 'written_off'], true)) {
                $update['completed_at'] = date('Y-m-d H:i:s');
            }
            db_update('borrower_obligations', $update, $obligation['id']);
            log_activity('status_change', 'obligation', $obligation['id'], $obligation['obligation_number'] . ' changed to ' . $status, ['status' => $obligation['status']], ['status' => $status]);
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
            flash('success', 'Penalty of ' . format_money($amount) . ' was applied.');
            break;

        case 'terminate_penalty':
            // Waive or delete: owners/administrators only (require_admin above + check inside), reason required.
            $mode = input_string($_POST, 'mode');
            $error = penalty_terminate(input_string($_POST, 'item_id'), $obligation['id'], $mode, input_string($_POST, 'reason'), $currentUser);
            if ($error !== null) {
                flash('error', $error);
                redirect('pages/obligations/view.php?id=' . $obligation['id'] . '&penalty=' . rawurlencode(input_string($_POST, 'item_id')) . '#terminate-penalty');
            }
            flash('success', $mode === 'waive' ? 'Penalty was waived. The reason was recorded.' : 'Penalty was deleted. The reason was recorded in the activity log.');
            break;

        case 'run_penalties':
            $created = db_transaction(fn () => penalty_run_obligation(db_row('SELECT * FROM borrower_obligations WHERE id = :id FOR UPDATE', [':id' => $obligation['id']])));
            flash('success', $created ? $created . ' new penalt' . ($created === 1 ? 'y was' : 'ies were') . ' charged.' : 'No new penalty is due. Penalties are already up to date.');
            break;

        default:
            flash('error', 'Unknown action.');
    }
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect($back);
