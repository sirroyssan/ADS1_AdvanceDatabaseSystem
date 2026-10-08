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
        case 'record_payment':
            $error = apply_schedule_payment('installment_schedules', 'installment_plan_id', $plan['id'], $scheduleId, input_string($_POST, 'amount'));
            if ($error !== null) {
                flash('error', $error);
                break;
            }
            log_activity('schedule_payment', 'installment_plan', $plan['id'], 'Recorded ' . input_string($_POST, 'amount') . ' on an installment');
            flash('success', 'Installment payment was recorded.');
            break;

        case 'schedule_status':
            if (input_string($_POST, 'status') !== 'waived') {
                flash('error', 'Unsupported status.');
                break;
            }
            $count = db_exec(
                "UPDATE installment_schedules SET status = 'waived' WHERE id = :i AND installment_plan_id = :p AND status NOT IN ('paid', 'waived', 'cancelled')",
                [':i' => $scheduleId, ':p' => $plan['id']]
            );
            if ($count) {
                log_activity('schedule_waive', 'installment_plan', $plan['id'], 'Waived an installment');
            }
            flash($count ? 'success' : 'error', $count ? 'Installment was waived.' : 'Installment not found or already settled.');
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
