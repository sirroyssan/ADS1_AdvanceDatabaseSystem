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
            $amount = input_string($_POST, 'amount');
            $asCardPayment = input_string($_POST, 'card_payment') === '1';
            // Only payments to the bank lower the card's used credit; "Also a card payment" records one.
            $error = card_usage_apply([$plan['credit_card_id']], function () use ($plan, $scheduleId, $amount, $asCardPayment) {
                $error = apply_schedule_payment('installment_schedules', 'installment_plan_id', $plan['id'], $scheduleId, $amount);
                if ($error === null && $asCardPayment) {
                    $number = db_value('SELECT installment_number FROM installment_schedules WHERE id = :id', [':id' => $scheduleId]);
                    record_installment_card_payment($plan['credit_card_id'], str_replace([',', ' ', '₱'], '', $amount),
                        $plan['transaction_number'] . ' #' . $number, 'Installment plan payment', 'Installment ' . $number . ' of ' . $plan['transaction_number']);
                }
                return $error;
            });
            if ($error !== null) {
                flash('error', $error);
                break;
            }
            log_activity('schedule_payment', 'installment_plan', $plan['id'], 'Recorded ' . $amount . ' on an installment' . ($asCardPayment ? ' (also a card payment)' : ''));
            flash('success', 'Installment payment was recorded.' . ($asCardPayment ? ' It was also saved as a card payment, so the card\'s used credit went down.' : ''));
            break;

        case 'schedule_status':
            if (input_string($_POST, 'status') !== 'waived') {
                flash('error', 'Unsupported status.');
                break;
            }
            // A waived installment is a credit: the bank no longer charges it.
            $count = card_usage_apply([$plan['credit_card_id']], fn () => db_exec(
                "UPDATE installment_schedules SET status = 'waived' WHERE id = :i AND installment_plan_id = :p AND status NOT IN ('paid', 'waived', 'cancelled')",
                [':i' => $scheduleId, ':p' => $plan['id']]
            ));
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
