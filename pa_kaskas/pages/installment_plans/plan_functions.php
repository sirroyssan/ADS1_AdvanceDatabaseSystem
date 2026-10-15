<?php
/**
 * Installment Plans module (tables: installment_plans, installment_schedules).
 *
 * One plan per transaction (transaction_id is UNIQUE). Saving a plan
 * generates its schedule automatically. Once installments are paid the amounts
 * can still be corrected (plan_correct_amounts): the rows are updated in
 * place and what was already paid is kept.
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
    // A cash advance given to a borrower is converted to installments on its own page.
    return db_options(
        "SELECT t.id, CONCAT(t.transaction_number, ' — ', DATE_FORMAT(t.transaction_date, '%b %e, %Y'), ' — ₱', FORMAT(t.amount, 2)) AS label
           FROM transactions t
          WHERE t.status NOT IN ('cancelled', 'refunded') AND " . owner_scope('transactions', 't') . "
            AND NOT EXISTS (SELECT 1 FROM installment_plans p WHERE p.transaction_id = t.id)
            AND NOT " . cash_share_sql('t') . "
          ORDER BY t.transaction_date DESC"
    );
}

function plan_fields(bool $isNew, ?array $current = null): array
{
    // New plans choose their interest and fee; older plans saved with typed amounts may keep them.
    $requireTerms = $isNew || !empty($current['interest_method']);
    $fields = [
        'transaction_id'         => ['label' => 'Transaction', 'type' => 'select', 'options' => plan_transaction_options(), 'required' => true, 'empty_label' => 'Select transaction'],
        'total_principal'        => ['label' => 'Total principal', 'type' => 'decimal', 'required' => true, 'min' => 0, 'section' => 'Amounts'],
        'number_of_installments' => ['label' => 'Number of installments', 'type' => 'int', 'required' => true, 'min' => 1, 'max' => 360],
        // Terms: the interest and fee are calculated from these (installment_compute()).
        'interest_method'        => ['label' => 'Interest', 'type' => 'select', 'options' => INSTALLMENT_METHODS, 'empty_label' => '— Choose the interest —',
                                     'required' => $requireTerms, 'section' => 'Interest and fees',
                                     'help' => 'Choose this first. A rate or a fixed amount box appears for the method chosen; the interest amount is then calculated.'],
        'interest_rate'          => ['label' => 'Interest rate (%)', 'type' => 'decimal', 'scale' => 4, 'digits' => 8, 'min' => 0, 'required_mark' => true,
                                     'attrs' => ['data-show-if' => 'f_interest_method=add_on_monthly|add_on_total|reducing_balance'],
                                     'help' => 'Per month for add-on per month and reducing balance (e.g. 1.25); for the whole term for add-on total.'],
        'interest_amount'        => ['label' => 'Interest amount', 'type' => 'decimal', 'min' => 0, 'default' => '0.00',
                                     'attrs' => ['data-plan-terms' => '1'],
                                     'help' => 'Calculated from the interest and rate. Type it only for "Fixed interest amount".'],
        'fee_type'               => ['label' => 'Processing fee', 'type' => 'select', 'options' => INSTALLMENT_FEE_TYPES, 'empty_label' => '— Choose the processing fee —', 'required' => $requireTerms],
        'fee_value'              => ['label' => 'Fee (₱ or %)', 'type' => 'decimal', 'scale' => 4, 'digits' => 15, 'min' => 0, 'required_mark' => true,
                                     'attrs' => ['data-show-if' => 'f_fee_type=fixed|percent']],
        'fee_billing'            => ['label' => 'Fee billed', 'type' => 'select', 'options' => INSTALLMENT_FEE_BILLING, 'initial' => 'spread',
                                     'attrs' => ['data-show-if' => 'f_fee_type=fixed|percent']],
        'fees_amount'            => ['label' => 'Fees amount', 'type' => 'decimal', 'min' => 0, 'default' => '0.00',
                                     'help' => 'Calculated from the processing fee when one is chosen.'],
        'total_repayment'        => ['label' => 'Total repayment', 'type' => 'decimal', 'min' => 0, 'help' => 'Leave blank for principal + interest + fees.',
                                     'attrs' => ['data-sum' => 'f_total_principal,f_interest_amount,f_fees_amount']],
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

/** Did the interest method, rate or processing fee change from the saved plan? */
function plan_terms_changed(array $data, array $current): bool
{
    foreach (['interest_method', 'fee_type', 'fee_billing'] as $k) {
        if (array_key_exists($k, $data) && (string) ($data[$k] ?? '') !== (string) ($current[$k] ?? '')) {
            return true;
        }
    }
    foreach (['interest_rate', 'fee_value'] as $k) {
        if (array_key_exists($k, $data) && abs((float) ($data[$k] ?? 0) - (float) ($current[$k] ?? 0)) >= 0.00005) {
            return true;
        }
    }

    return false;
}

/**
 * Validate the plan and keep its amounts consistent: principal + interest +
 * fees = total repayment = installment amount × number of installments.
 * The installment amount is what the bank bills each month, so when only it
 * was changed (e.g. a wrong amount was typed), the total follows it and the
 * difference from the principal is the add-on interest.
 */
function plan_validate(array $fields, array $input, ?string $id = null, ?array $current = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);

    if (isset($data['start_date'], $data['first_due_date']) && $data['first_due_date'] < $data['start_date']) {
        $errors['first_due_date'] = 'First due date cannot be before the start date.';
    }
    if (array_intersect_key($errors, array_flip(['total_principal', 'interest_amount', 'fees_amount', 'number_of_installments', 'total_repayment', 'installment_amount']))) {
        return [$data, $errors];
    }

    // Interest and fee from the chosen terms (a new plan, changed terms, or a new principal or
    // term): the amounts are calculated, so a rate is never ignored.
    if (!empty($data['interest_method']) && ($current === null || plan_terms_changed($data, $current)
            || abs((float) $data['total_principal'] - (float) $current['total_principal']) >= 0.005
            || (int) $data['number_of_installments'] !== (int) $current['number_of_installments'])) {
        $t = [
            'principal' => $data['total_principal'], 'months' => $data['number_of_installments'],
            'interest_method' => $data['interest_method'], 'interest_rate' => $data['interest_rate'] ?? null,
            'interest_amount' => $data['interest_method'] === 'fixed_amount' ? $data['interest_amount'] : null,
            'fee_type' => $data['fee_type'] ?: 'none', 'fee_value' => $data['fee_value'] ?? null, 'fee_billing' => $data['fee_billing'] ?: 'spread',
        ];
        foreach (installment_terms_errors($t) as $field => $message) {
            $errors[$field === 'principal' ? 'total_principal' : $field] = $message;
        }
        if ($errors) {
            return [$data, $errors];
        }
        $calc = installment_compute($t, $data['first_due_date'] ?? date('Y-m-d'), $data['frequency'] ?? 'monthly');
        $data['interest_rate'] = in_array($t['interest_method'], ['add_on_monthly', 'add_on_total', 'reducing_balance'], true) ? $t['interest_rate'] : null;
        $data['fee_type'] = $t['fee_type'];
        $data['fee_value'] = $t['fee_type'] === 'none' ? null : $t['fee_value'];
        $data['fee_billing'] = $t['fee_billing'];
        $data['interest_amount'] = $calc['interest'];
        $data['fees_amount'] = $calc['fees'];
        $data['total_repayment'] = $calc['total'];
        $data['installment_amount'] = $calc['monthly'];

        return [$data, $errors];
    }

    $n = max(1, (int) $data['number_of_installments']);
    $money = static fn (float $v) => number_format(round($v, 2), 2, '.', '');
    $computed = (float) $data['total_principal'] + (float) $data['interest_amount'] + (float) $data['fees_amount'];
    $monthly = $data['installment_amount'] === null ? null : (float) $data['installment_amount'];
    $fits = static fn (float $total) => $monthly === null || abs($monthly * $n - $total) < 0.01 * $n;

    if ($data['total_repayment'] === null) {
        $total = $monthly !== null && !$fits($computed) ? $monthly * $n : $computed;
    } else {
        $total = (float) $data['total_repayment'];
        $monthlyChanged = $current !== null && $monthly !== null && abs($monthly - (float) $current['installment_amount']) >= 0.005;
        $totalChanged = $current === null || abs($total - (float) $current['total_repayment']) >= 0.005;
        if (!$fits($total) && $monthlyChanged && !$totalChanged) {
            $total = $monthly * $n;      // only the monthly amount was corrected
        }
    }
    if (!$fits($total)) {
        $monthly = null;                 // follows the total below
    }

    // The schedule is built from principal, interest and fees: make them add up to the total.
    if (abs($total - $computed) >= 0.005) {
        $interest = $total - (float) $data['total_principal'] - (float) $data['fees_amount'];
        if ($interest >= 0) {
            $data['interest_amount'] = $money($interest);
        } elseif ($total - (float) $data['fees_amount'] >= 0) {
            $data['total_principal'] = $money($total - (float) $data['fees_amount']);
            $data['interest_amount'] = '0.00';
        } else {
            $errors['total_repayment'] = 'The total repayment cannot be less than the fees.';
        }
    }
    $data['total_repayment'] = $money($total);
    $data['installment_amount'] = $money($monthly ?? $total / $n);

    return [$data, $errors];
}

/**
 * Correct the amounts of a plan that already has payments, without losing
 * them: every installment row is updated in place (rows referenced by
 * payments, selections or penalties are never deleted), rows are added or
 * removed at the end when the number of installments changes, and what was
 * paid is spread again oldest first (borrower obligations are re-synced by
 * the caller). Returns an error message, or null. Run inside a transaction.
 */
function plan_correct_amounts(array $plan, array $data): ?string
{
    $rows = db_all('SELECT * FROM installment_schedules WHERE installment_plan_id = :p ORDER BY installment_number FOR UPDATE', [':p' => $plan['id']]);
    $paid = round(array_sum(array_map(static fn ($r) => (float) $r['amount_paid'], $rows)), 2);
    if ((float) $data['total_repayment'] < $paid - 0.004) {
        return 'Already paid on this plan: ' . format_money($paid) . '. The total repayment cannot be less than that.';
    }

    $count = (int) $data['number_of_installments'];
    $built = installment_plan_rows(['first_due_date' => $data['first_due_date'] ?? $plan['first_due_date'], 'frequency' => $data['frequency'] ?? $plan['frequency']] + $data + $plan);

    // Rows beyond the new count go only when nothing points to them.
    foreach (array_slice($rows, $count) as $row) {
        $used = (int) db_value(
            'SELECT (SELECT COUNT(*) FROM borrower_payment_allocations WHERE installment_schedule_id = :a)
                  + (SELECT COUNT(*) FROM borrower_payment_selections WHERE installment_schedule_id = :b)
                  + (SELECT COUNT(*) FROM applied_penalties WHERE installment_schedule_id = :c)
                  + (SELECT COUNT(*) FROM borrower_payment_purchase_parts WHERE installment_schedule_id = :d)',
            [':a' => $row['id'], ':b' => $row['id'], ':c' => $row['id'], ':d' => $row['id']]
        );
        if ($used > 0) {
            return 'Installment #' . $row['installment_number'] . ' has payments or penalties recorded on it, so the plan cannot have fewer than '
                . $row['installment_number'] . ' installments.';
        }
    }

    $left = $paid;
    foreach ($built as $i => $new) {
        $row = $rows[$i] ?? null;
        $due = (float) $new['amount_due'];
        $waived = $row && in_array($row['status'], ['waived', 'cancelled'], true);
        $put = $waived ? 0.0 : min($due, $left);
        $left = round($left - $put, 2);
        $values = [
            'principal_amount' => $new['principal_amount'],
            'interest_amount'  => $new['interest_amount'],
            'fee_amount'       => $new['fee_amount'],
            'amount_due'       => $new['amount_due'],
        ];
        if (!$waived) {
            $values += [
                'amount_paid'      => number_format($put, 2, '.', ''),
                'remaining_amount' => number_format(max(0, $due - $put), 2, '.', ''),
                'status'           => schedule_status_after_payment($due, $put, $row['due_date'] ?? $new['due_date']),
                'paid_at'          => $put >= $due - 0.004 ? ($row['paid_at'] ?? date('Y-m-d H:i:s')) : null,
            ];
        }
        if ($row) {
            db_update('installment_schedules', $values, $row['id']);
        } else {
            db_insert('installment_schedules', ['id' => new_uuid(), 'installment_plan_id' => $plan['id'],
                'installment_number' => $new['installment_number'], 'due_date' => $new['due_date']] + $values);
        }
    }
    foreach (array_slice($rows, $count) as $row) {
        db_exec('DELETE FROM installment_schedules WHERE id = :id', [':id' => $row['id']]);
    }
    db_update('installment_plans', ['end_date' => db_value('SELECT MAX(due_date) FROM installment_schedules WHERE installment_plan_id = :p', [':p' => $plan['id']])], $plan['id']);

    return null;
}

/**
 * Replace the schedule rows from the plan's amounts and set end_date.
 */
function plan_generate_schedule(string $planId, array $plan): void
{
    db_exec('DELETE FROM installment_schedules WHERE installment_plan_id = :id', [':id' => $planId]);

    // The plan's own terms (interest method, fees), or an even split for plans entered by hand.
    $rows = installment_plan_rows($plan);

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
        'SELECT p.*, t.transaction_number, t.transaction_date, t.amount AS transaction_amount, t.borrower_id, t.credit_card_id,
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
