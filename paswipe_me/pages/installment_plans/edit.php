<?php
/**
 * Installment Plans - edit. Before any payment the terms can change and the
 * schedule is regenerated; afterwards only the status can change.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/plan_functions.php';

$currentUser = require_admin();

$plan = find_or_redirect(plan_find(request_id()), 'pages/installment_plans/index.php', 'Installment plan');
$locked = plan_is_locked($plan['id']);

$fields = plan_fields(false, $plan);
if ($locked) {
    foreach ($fields as $name => &$f) {
        if (!in_array($name, ['status', 'first_due_date', 'frequency'], true)) {   // dates can still move
            $f['readonly'] = true;
        }
    }
    unset($f);
}
$data = form_data_from_row($fields, $plan);
$errors = [];

if (is_post()) {
    require_csrf('pages/installment_plans/edit.php?id=' . $plan['id']);

    if ($locked) {
        [$data, $errors] = validate_fields($fields, $_POST, $plan['id']);
    } else {
        [$data, $errors] = plan_validate($fields, $_POST, $plan['id']);
    }

    if (!$errors) {
        try {
            $planCards = [$plan['credit_card_id'], isset($data['transaction_id']) ? db_value('SELECT credit_card_id FROM transactions WHERE id = :id', [':id' => $data['transaction_id']]) : null];
            $moved = null;
            card_usage_apply($planCards, function () use ($plan, &$data, $locked, &$moved) {
                if ($locked && (($data['first_due_date'] ?? $plan['first_due_date']) !== $plan['first_due_date'] || ($data['frequency'] ?? $plan['frequency']) !== $plan['frequency'])) {
                    // Payments exist: keep every amount, move the due dates only.
                    $newEnd = schedule_shift_dates('installment_schedules', 'installment_plan_id', $plan['id'], $data['first_due_date'], $data['frequency'] ?? $plan['frequency']);
                    $data['end_date'] = $newEnd;
                    $moved = [$plan['end_date'], $newEnd];
                }
                db_update('installment_plans', $data, $plan['id']);
                if (!$locked) {
                    plan_generate_schedule($plan['id'], $data);
                }
                if ($moved) {
                    schedule_move_obligations(array_column(db_all(
                        "SELECT id FROM borrower_obligations WHERE transaction_id = :t AND status <> 'cancelled'", [':t' => $plan['transaction_id']]), 'id'), $moved[0], $moved[1]);
                }
            });
            if ($moved) {
                flash('info', 'The installment due dates moved with the new first due date (now ending ' . format_date($moved[1]) . '). Amounts and payments were kept.');
            }
            log_activity('update', 'installment_plan', $plan['id'], $locked ? 'Changed plan status' : 'Updated plan terms and regenerated schedule',
                array_intersect_key($plan, $data), $data);
            flash('success', $locked ? 'Plan status was updated.' : 'Plan updated and schedule regenerated.');
            redirect('pages/installment_plans/view.php', ['id' => $plan['id']]);
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
            ? 'Payments are already recorded on this schedule: the amounts stay, but you can move the due dates (first due date, frequency) and change the status.'
            : 'Saving regenerates the payment schedule from the new terms.' ?></p>
    </div>
</section>

<?= render_record_form(url('pages/installment_plans/edit.php', ['id' => $plan['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/installment_plans/view.php', ['id' => $plan['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
