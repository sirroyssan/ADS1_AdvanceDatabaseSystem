<?php
/**
 * Installment Plans - delete a plan that has no payments, allocations or penalties.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/plan_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/installment_plans/index.php');
}

$plan = find_or_redirect(plan_find(request_id()), 'pages/installment_plans/index.php', 'Installment plan');
require_csrf('pages/installment_plans/view.php?id=' . $plan['id']);

try {
    db_transaction(function () use ($plan) {
        db_value('SELECT id FROM installment_plans WHERE id = :id FOR UPDATE', [':id' => $plan['id']]);
        if (plan_is_locked($plan['id'])) {
            throw new DomainException('This plan has recorded payments, so it cannot be deleted. Set its status to Cancelled instead.');
        }
        db_exec('DELETE FROM installment_schedules WHERE installment_plan_id = :id', [':id' => $plan['id']]);
        db_exec('DELETE FROM installment_plans WHERE id = :id', [':id' => $plan['id']]);
    });

    log_activity('delete', 'installment_plan', $plan['id'], 'Deleted installment plan of ' . $plan['transaction_number'], $plan);
    flash('success', 'Installment plan was deleted.');
    redirect('pages/transactions/view.php', ['id' => $plan['transaction_id']]);
} catch (DomainException $e) {
    flash('error', $e->getMessage());
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect('pages/installment_plans/view.php', ['id' => $plan['id']]);
