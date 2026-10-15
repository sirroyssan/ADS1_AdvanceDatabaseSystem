<?php
/**
 * Installment Plans - schedule actions (POST): record_payment, schedule_status (waive).
 * The plan is marked completed automatically when every row is paid or waived.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/plan_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/installment_plans/index.php');
}

$plan = find_or_redirect(plan_find(request_id()), 'pages/installment_plans/index.php', 'Installment plan');
$back = 'pages/installment_plans/view.php?id=' . $plan['id'];
require_csrf($back);

if ($plan['status'] !== 'active') {
    flash('error', 'Only active plans can be updated.');
    redirect($back);
}

$scheduleId = input_string($_POST, 'schedule_id');

try {
    switch (input_string($_POST, 'action')) {
        case 'shift_dates':
            // "Change due dates": move the existing schedule to a new monthly due day.
            [$data, $errors] = validate_fields(schedule_shift_fields(), $_POST);
            if ($errors) {
                flash('error', 'Due dates not changed: ' . reset($errors));
                break;
            }
            $moved = db_transaction(static function () use ($plan, $data) {
                $newEnd = schedule_shift_dates('installment_schedules', 'installment_plan_id', $plan['id'], $data['first_due_date'], $data['frequency']);
                if ($newEnd === null) {
                    return null;
                }
                db_update('installment_plans', ['first_due_date' => $data['first_due_date'], 'frequency' => $data['frequency'], 'end_date' => $newEnd], $plan['id']);
                schedule_move_obligations(array_column(db_all(
                    "SELECT id FROM borrower_obligations WHERE transaction_id = :t AND status <> 'cancelled'", [':t' => $plan['transaction_id']]), 'id'), $plan['end_date'], $newEnd);

                return $newEnd;
            });
            if ($moved === null) {
                flash('error', 'This plan has no schedule.');
                break;
            }
            log_activity('shift_dates', 'installment_plan', $plan['id'], 'Due dates moved: first ' . $data['first_due_date'] . ', last ' . $moved,
                ['first_due_date' => $plan['first_due_date'], 'end_date' => $plan['end_date']], ['first_due_date' => $data['first_due_date'], 'end_date' => $moved]);
            borrowed_notify_plan($plan, $plan['id']);
            flash('success', 'Due dates updated: first installment due ' . format_date($data['first_due_date']) . ', last ' . format_date($moved) . '. Amounts and payments were kept.');
            break;

        case 'record_payment':
        case 'schedule_status':
            // Installments are paid only through borrower payments (applied automatically).
            flash('error', 'Installments are updated automatically from approved borrower payments. Record the payment under Borrower Payments.');
            break;

        default:
            flash('error', 'Unknown action.');
    }

    // Complete the plan once nothing is left to pay.
    $open = (int) db_value("SELECT COUNT(*) FROM installment_schedules WHERE installment_plan_id = :id AND status NOT IN ('paid', 'waived', 'cancelled')", [':id' => $plan['id']]);
    if ($open === 0) {
        db_update('installment_plans', ['status' => 'completed'], $plan['id']);
        log_activity('status_change', 'installment_plan', $plan['id'], 'Plan completed automatically');
        flash('success', 'All installments are settled — the plan is now completed.');
    }
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect($back);
