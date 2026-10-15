<?php
/**
 * Installment Plans - edit. Before any payment the terms can change and the
 * schedule is regenerated. Afterwards the amounts can still be corrected (the
 * installment rows are updated in place, payments are kept) and the due dates
 * moved; only the transaction and start date are fixed.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/plan_functions.php';

$currentUser = require_admin();

$plan = find_or_redirect(plan_find(request_id()), 'pages/installment_plans/index.php', 'Installment plan');
$locked = plan_is_locked($plan['id']);

$fields = plan_fields(false, $plan);
if ($locked) {
    foreach ($fields as $name => &$f) {
        if ($name === 'start_date') {   // amounts, due dates and status can still change
            $f['readonly'] = true;
        }
    }
    unset($f);
}
$data = form_data_from_row($fields, $plan);
$errors = [];

if (is_post()) {
    require_csrf('pages/installment_plans/edit.php?id=' . $plan['id']);

    [$data, $errors] = plan_validate($fields, $_POST, $plan['id'], $plan);
    unset($data['transaction_id']);
    $amountFields = ['total_principal', 'interest_amount', 'fees_amount', 'total_repayment', 'number_of_installments', 'installment_amount'];
    $amountsChanged = false;
    foreach ($amountFields as $name) {
        $amountsChanged = $amountsChanged || abs((float) ($data[$name] ?? 0) - (float) $plan[$name]) >= 0.005;
    }
    // A plan made from terms (interest method, fees) keeps them: a new principal or term is
    // recalculated with them; interest or fees typed by hand become the plan's fixed amounts.
    if (!$errors && $amountsChanged && !empty($plan['interest_method'])) {
        $byHand = abs((float) $data['interest_amount'] - (float) $plan['interest_amount']) >= 0.005
            || abs((float) $data['fees_amount'] - (float) $plan['fees_amount']) >= 0.005;
        if ($byHand) {
            $data += ['interest_method' => (float) $data['interest_amount'] > 0 ? 'fixed_amount' : 'none', 'fee_type' => (float) $data['fees_amount'] > 0 ? 'fixed' : 'none',
                      'fee_value' => $data['fees_amount'], 'fee_billing' => $plan['fee_billing'] ?? 'spread'];
        }
        $calc = installment_compute([
            'principal' => $data['total_principal'], 'months' => $data['number_of_installments'],
            'interest_method' => $data['interest_method'] ?? $plan['interest_method'], 'interest_rate' => $plan['interest_rate'],
            'interest_amount' => $data['interest_amount'], 'fee_type' => $data['fee_type'] ?? $plan['fee_type'],
            'fee_value' => $data['fee_value'] ?? $plan['fee_value'], 'fee_billing' => $data['fee_billing'] ?? $plan['fee_billing'],
        ], $data['first_due_date'] ?? $plan['first_due_date']);
        $data['interest_amount'] = $calc['interest'];
        $data['fees_amount'] = $calc['fees'];
        $data['total_repayment'] = $calc['total'];
        $data['installment_amount'] = $calc['monthly'];
    }

    if (!$errors) {
        try {
            $planCards = [$plan['credit_card_id']];
            $moved = null;
            card_usage_apply($planCards, function () use ($plan, &$data, $locked, $amountsChanged, &$moved) {
                if ($locked && $amountsChanged) {
                    // Payments exist: correct the rows in place and keep what was paid.
                    $problem = plan_correct_amounts($plan, $data);
                    if ($problem !== null) {
                        throw new DomainException($problem);
                    }
                }
                if ($locked && (($data['first_due_date'] ?? $plan['first_due_date']) !== $plan['first_due_date'] || ($data['frequency'] ?? $plan['frequency']) !== $plan['frequency'])) {
                    // Payments exist: keep every amount, move the due dates only.
                    $newEnd = schedule_shift_dates('installment_schedules', 'installment_plan_id', $plan['id'], $data['first_due_date'], $data['frequency'] ?? $plan['frequency']);
                    $data['end_date'] = $newEnd;
                    $moved = [$plan['end_date'], $newEnd];
                }
                db_update('installment_plans', $data, $plan['id']);
                if (!$locked) {
                    plan_generate_schedule($plan['id'], $data + $plan);
                }
                if ($locked && $amountsChanged) {
                    // Borrower payments flow into the corrected installments again.
                    foreach (db_all("SELECT id FROM borrower_obligations WHERE transaction_id = :t AND status <> 'cancelled'", [':t' => $plan['transaction_id']]) as $o) {
                        schedule_sync_obligation($o['id']);
                    }
                }
                if ($locked) {
                    borrowed_sync_penalty_payments($plan['transaction_id']);   // a lent purchase: repayments flow into the rows again
                }
                if ($moved) {
                    schedule_move_obligations(array_column(db_all(
                        "SELECT id FROM borrower_obligations WHERE transaction_id = :t AND status <> 'cancelled'", [':t' => $plan['transaction_id']]), 'id'), $moved[0], $moved[1]);
                }
            });
            borrowed_notify_plan($plan, $plan['id']);
            if ($moved) {
                flash('info', 'The installment due dates moved with the new first due date (now ending ' . format_date($moved[1]) . '). Amounts and payments were kept.');
            }
            try {
                statement_prepare_card($plan['credit_card_id']);   // upcoming statements show the corrected amounts
            } catch (Throwable $e) {
                error_log('[' . APP_NAME . '] Statement preparation after plan edit: ' . $e->getMessage());
            }
            log_activity('update', 'installment_plan', $plan['id'],
                !$locked ? 'Updated plan terms and regenerated schedule' : ($amountsChanged ? 'Corrected plan amounts (payments kept)' : 'Updated plan'),
                array_intersect_key($plan, $data), $data);
            if ($locked && $amountsChanged) {
                flash('success', 'Amounts corrected: ' . (int) $data['number_of_installments'] . ' × ' . format_money($data['installment_amount'])
                    . ' = ' . format_money($data['total_repayment']) . '. Payments already made were kept on the installments.');
                if (db_value("SELECT COUNT(*) FROM borrower_obligations WHERE transaction_id = :t AND status <> 'cancelled'", [':t' => $plan['transaction_id']]) > 0) {
                    flash('info', 'A borrower obligation is linked to this purchase. Its amount was not changed - edit the obligation too if it was based on the wrong amount.');
                }
            } else {
                flash('success', $locked ? 'Plan was updated.' : 'Plan updated and schedule regenerated.');
            }
            redirect('pages/installment_plans/view.php', ['id' => $plan['id']]);
        } catch (DomainException $e) {
            $errors['_form'] = $e->getMessage();
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Installment Plan';
$activeNav = 'installments';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Installment Plans' => url('pages/installment_plans/index.php'),
    $plan['transaction_number'] => url('pages/installment_plans/view.php', ['id' => $plan['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit installment plan</h2>
        <p class="page-header__subtitle"><?= $locked
            ? 'Payments are already recorded on this schedule. You can still correct the amounts (each installment is updated and what was paid is kept), move the due dates and change the status. Changing only the installment amount updates the total repayment.'
            : 'Saving regenerates the payment schedule from the new terms.' ?></p>
    </div>
</section>

<?= render_record_form(url('pages/installment_plans/edit.php', ['id' => $plan['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/installment_plans/view.php', ['id' => $plan['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
