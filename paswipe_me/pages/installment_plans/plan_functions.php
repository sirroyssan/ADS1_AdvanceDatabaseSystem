<?php
/**
 * Installment Plans module (tables: installment_plans, installment_schedules).
 *
 * One plan per transaction (transaction_id is UNIQUE). Saving a plan
 * generates its schedule automatically. Amounts can only be changed while
 * no installment has been paid; after that only status/notes-like fields.
 * status is VARCHAR(30) with default 'active'; the app uses active,
 * completed and cancelled.
 */

defined('APP_ROOT') or exit('No direct access.');

const PLAN_STATUSES = ['active' => 'Active', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

/**
 * Transactions that can receive a plan (no plan yet, not cancelled).
 */
function plan_transaction_options(): array
{
    return db_options(
        "SELECT t.id, CONCAT(t.transaction_number, ' — ', DATE_FORMAT(t.transaction_date, '%b %e, %Y'), ' — ₱', FORMAT(t.amount, 2)) AS label
           FROM transactions t
          WHERE t.status NOT IN ('cancelled', 'refunded') AND " . owner_scope('transactions', 't') . "
            AND NOT EXISTS (SELECT 1 FROM installment_plans p WHERE p.transaction_id = t.id)
          ORDER BY t.transaction_date DESC"
    );
}

function plan_fields(bool $isNew, ?array $current = null): array
{
    $fields = [
        'transaction_id'         => ['label' => 'Transaction', 'type' => 'select', 'options' => plan_transaction_options(), 'required' => true, 'empty_label' => 'Select transaction'],
        'total_principal'        => ['label' => 'Total principal', 'type' => 'decimal', 'required' => true, 'min' => 0, 'section' => 'Amounts'],
        'interest_amount'        => ['label' => 'Interest amount', 'type' => 'decimal', 'min' => 0, 'default' => '0.00'],
        'fees_amount'            => ['label' => 'Fees amount', 'type' => 'decimal', 'min' => 0, 'default' => '0.00'],
        'total_repayment'        => ['label' => 'Total repayment', 'type' => 'decimal', 'min' => 0, 'help' => 'Leave blank for principal + interest + fees.',
                                     'attrs' => ['data-sum' => 'f_total_principal,f_interest_amount,f_fees_amount']],
        'number_of_installments' => ['label' => 'Number of installments', 'type' => 'int', 'required' => true, 'min' => 1, 'max' => 360],
        'installment_amount'     => ['label' => 'Installment amount', 'type' => 'decimal', 'min' => 0, 'help' => 'Leave blank for total repayment ÷ installments.',
                                     'attrs' => ['data-divide' => 'f_total_repayment,f_number_of_installments']],
        'frequency'              => ['label' => 'Frequency', 'type' => 'select', 'options' => enum_options(ENUM_FREQUENCY), 'required' => true, 'initial' => 'monthly', 'section' => 'Dates',
                                     'help' => '"Other" is scheduled monthly.'],
        'start_date'             => ['label' => 'Start date', 'type' => 'date', 'required' => true],
        'first_due_date'         => ['label' => 'First due date', 'type' => 'date', 'required' => true],
        'status'                 => ['label' => 'Status', 'type' => 'select', 'options' => PLAN_STATUSES + (isset($current['status']) && !isset(PLAN_STATUSES[$current['status']]) ? [$current['status'] => $current['status']] : []), 'required' => true, 'initial' => 'active'],
    ];

    if (!$isNew) {
        $fields['transaction_id']['readonly'] = true;
        $fields['transaction_id']['options'] = [$current['transaction_id'] => $current['transaction_number']];
    }

    return $fields;
}

function plan_validate(array $fields, array $input, ?string $id = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);

    if ($data['start_date'] && $data['first_due_date'] && $data['first_due_date'] < $data['start_date']) {
        $errors['first_due_date'] = 'First due date cannot be before the start date.';
    }
    if (!array_intersect_key($errors, array_flip(['total_principal', 'interest_amount', 'fees_amount', 'number_of_installments']))) {
        $computed = (float) $data['total_principal'] + (float) $data['interest_amount'] + (float) $data['fees_amount'];
        $data['total_repayment'] ??= number_format($computed, 2, '.', '');
        if ($data['installment_amount'] === null && (int) $data['number_of_installments'] > 0) {
            $data['installment_amount'] = number_format(round((float) $data['total_repayment'] / (int) $data['number_of_installments'], 2), 2, '.', '');
        }
    }

    return [$data, $errors];
}

/**
 * Replace the schedule rows from the plan's amounts and set end_date.
 */
function plan_generate_schedule(string $planId, array $plan): void
{
    db_exec('DELETE FROM installment_schedules WHERE installment_plan_id = :id', [':id' => $planId]);

    $rows = build_schedule(
        (float) $plan['total_principal'],
        (float) $plan['interest_amount'],
        (float) $plan['fees_amount'],
        (int) $plan['number_of_installments'],
        $plan['first_due_date'],
        $plan['frequency']
    );

    foreach ($rows as $row) {
        db_insert('installment_schedules', ['id' => new_uuid(), 'installment_plan_id' => $planId] + $row);
    }

    db_update('installment_plans', ['end_date' => end($rows)['due_date']], $planId);
}

function plan_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        'SELECT p.*, t.transaction_number, t.amount AS transaction_amount, t.borrower_id, t.credit_card_id,
                b.first_name, b.last_name, c.card_nickname, c.last_four,
                (SELECT COALESCE(SUM(amount_paid), 0) FROM installment_schedules s WHERE s.installment_plan_id = p.id) AS total_paid
           FROM installment_plans p
           JOIN transactions t ON t.id = p.transaction_id
           JOIN credit_cards c ON c.id = t.credit_card_id
           LEFT JOIN borrowers b ON b.id = t.borrower_id
          WHERE p.id = :id AND ' . owner_scope('installment_plans', 'p'),
        [':id' => $id]
    );
}

/**
 * Payments or penalties already tied to the schedule lock the amounts.
 */
function plan_is_locked(string $planId): bool
{
    return (int) db_value(
        'SELECT (SELECT COUNT(*) FROM installment_schedules WHERE installment_plan_id = :a AND amount_paid > 0)
              + (SELECT COUNT(*) FROM borrower_payment_allocations bpa JOIN installment_schedules s ON s.id = bpa.installment_schedule_id WHERE s.installment_plan_id = :b)
              + (SELECT COUNT(*) FROM applied_penalties ap JOIN installment_schedules s ON s.id = ap.installment_schedule_id WHERE s.installment_plan_id = :c)',
        [':a' => $planId, ':b' => $planId, ':c' => $planId]
    ) > 0;
}
