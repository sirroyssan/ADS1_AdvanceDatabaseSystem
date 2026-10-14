<?php
/**
 * Cash Loans module (tables: cash_loans, cash_loan_schedules, cash_loan_allocations).
 *
 * A bank cash loan on a card can be split ("allocated"). The card owner is the
 * account holder: the bank's borrower, responsible for every installment to the
 * bank. An allocation only says who carries which part of it:
 *   reimburse    - a person repays this part to the card owner (a debt; it can
 *                  become a borrower obligation, which repays its share of each
 *                  loan installment). Older "borrower" rows mean the same.
 *   own_share    - the card owner's own part (no debt).
 *   contribution - a person helps with the payments without owing it (no debt).
 * The obligation and the allocation are linked both ways
 * (cash_loan_allocations.obligation_id and borrower_obligations.cash_loan_allocation_id).
 * No hard delete once allocations exist; use status cancelled/rejected.
 */

defined('APP_ROOT') or exit('No direct access.');

function cash_loan_fields(bool $isNew, ?array $current = null): array
{
    $money = static fn (string $label, array $extra = []) => ['label' => $label, 'type' => 'decimal', 'min' => 0, 'default' => '0.00'] + $extra;

    return [
        'loan_reference'       => ['label' => 'Loan reference', 'type' => 'text', 'max' => 100, 'required' => !$isNew,
                                   'unique' => ['cash_loans', 'loan_reference', 'This loan reference is already used.'],
                                   'help' => $isNew ? 'Leave blank to generate one automatically.' : ''],
        'credit_card_id'       => ['label' => 'Credit card', 'type' => 'select', 'options' => credit_card_options($current['credit_card_id'] ?? null), 'required' => true, 'empty_label' => 'Select card'],
        'offer_number'         => ['label' => 'Offer number', 'type' => 'text', 'max' => 100],
        'status'               => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_CASH_LOAN_STATUS), 'required' => true, 'initial' => 'offered'],
        'requested_amount'     => $money('Requested amount', ['section' => 'Amounts']),
        'offered_amount'       => $money('Offered amount'),
        'approved_amount'      => $money('Approved amount'),
        'accepted_amount'      => $money('Accepted amount'),
        'actual_cash_received' => $money('Actual cash received'),
        'processing_fee'       => $money('Processing fee'),
        'other_fees'           => $money('Other fees'),
        'interest_rate'        => ['label' => 'Interest rate (%)', 'type' => 'decimal', 'scale' => 4, 'digits' => 8, 'min' => 0, 'placeholder' => '0.0000'],
        'total_repayment'      => $money('Total repayment', ['attrs' => ['data-addon' => 'f_accepted_amount,f_approved_amount,f_offered_amount|f_interest_rate|f_term_months'],
                                   'help' => 'Calculated as you type: principal (accepted, else approved, else offered amount) + principal × interest rate % × term in months. Fees are not added. Type the bank\'s figure here if it differs.']),
        'term_months'          => ['label' => 'Term (months)', 'type' => 'int', 'min' => 1, 'max' => 360, 'section' => 'Repayment terms'],
        'monthly_payment'      => ['label' => 'Monthly payment', 'type' => 'decimal', 'min' => 0, 'attrs' => ['data-divide' => 'f_total_repayment,f_term_months']],
        'payment_frequency'    => ['label' => 'Payment frequency', 'type' => 'select', 'options' => enum_options(ENUM_FREQUENCY), 'empty_label' => '— Not set —'],
        'start_date'           => ['label' => 'Start date', 'type' => 'date'],
        'first_due_date'       => ['label' => 'First due date', 'type' => 'date'],
        'end_date'             => ['label' => 'End date (last installment)', 'type' => 'date',
                                   'attrs' => ['data-end-from' => 'f_first_due_date,f_term_months,f_payment_frequency'],
                                   'help' => 'Calculated from the first due date and the term. Enter it yourself only when the loan has no term.'],
        'notes'                => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 3],
    ];
}

function cash_loan_validate(array $fields, array $input, ?string $id = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);

    // Total repayment left empty: principal + add-on interest (rate % per month x term), as the form shows it.
    if (!$errors && (float) ($data['total_repayment'] ?? 0) <= 0 && (int) $data['term_months'] > 0 && ($principal = cash_loan_principal($data)) > 0) {
        $data['total_repayment'] = number_format(round($principal + $principal * (float) $data['interest_rate'] / 100 * (int) $data['term_months'], 2), 2, '.', '');
    }

    // The last installment follows from the first due date and the term.
    if (!isset($errors['term_months']) && !isset($errors['first_due_date']) && $data['term_months'] && $data['first_due_date']) {
        $data['end_date'] = schedule_due_date($data['first_due_date'], (int) $data['term_months'] - 1, $data['payment_frequency'] ?: 'monthly');
    }

    if ($data['start_date'] && $data['first_due_date'] && $data['first_due_date'] < $data['start_date']) {
        $errors['first_due_date'] = 'First due date cannot be before the start date.';
    }
    if ($data['first_due_date'] && $data['end_date'] && $data['end_date'] < $data['first_due_date']) {
        $errors['end_date'] = 'End date cannot be before the first due date.';
    }

    return [$data, $errors];
}

function cash_loan_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        'SELECT l.*, c.card_nickname, c.last_four,
                (SELECT COALESCE(SUM(a.allocated_amount), 0) FROM cash_loan_allocations a WHERE a.cash_loan_id = l.id) AS allocated
           FROM cash_loans l JOIN credit_cards c ON c.id = l.credit_card_id
          WHERE l.id = :id AND ' . owner_scope('cash_loans', 'l'),
        [':id' => $id]
    );
}

/**
 * Cash that can be split to borrowers: actual cash received, else the accepted amount.
 */
function cash_loan_distributable(array $loan): float
{
    return (float) $loan['actual_cash_received'] > 0 ? (float) $loan['actual_cash_received'] : (float) $loan['accepted_amount'];
}

const CASH_LOAN_ALLOCATION_TYPES = [
    'reimburse'    => 'Owes me — repays this part to me',
    'own_share'    => 'My own share',
    'contribution' => 'Contribution — helps pay, does not owe it',
];

/** Allocation types that are a debt to the card owner (older rows used "borrower"). */
const CASH_LOAN_DEBT_TYPES = ['reimburse', 'borrower'];

function allocation_type_label(string $type): string
{
    return match ($type) {
        'reimburse', 'borrower' => 'Owes the card owner',
        'own_share' => 'Card owner\'s own share',
        'contribution' => 'Contribution (not owed)',
        default => humanize($type),
    };
}

function allocation_fields(): array
{
    return [
        'borrower_id'      => ['label' => 'Person', 'type' => 'select', 'options' => borrower_options(), 'empty_label' => '— Me (card owner) —'],
        'allocation_type'  => ['label' => 'Responsibility', 'type' => 'select', 'options' => CASH_LOAN_ALLOCATION_TYPES, 'required' => true, 'initial' => 'reimburse',
                               'help' => 'You stay responsible to the bank for the whole loan. "Owes me" creates the person\'s obligation at once (active), repaid over the loan\'s term and due on its last installment date.'],
        'allocated_amount' => ['label' => 'Amount', 'type' => 'decimal', 'required' => true, 'min' => 0],
        'description'      => ['label' => 'Description', 'type' => 'text', 'max' => 500, 'full' => true],
    ];
}

/** Allocation rules: a debt needs the person who owes it; the own share is the card owner's. */
function allocation_validate(array $data, array $errors): array
{
    if (in_array($data['allocation_type'] ?? '', CASH_LOAN_DEBT_TYPES, true) && !$data['borrower_id'] && !isset($errors['borrower_id'])) {
        $errors['borrower_id'] = 'Choose the person who repays this part to you.';
    }
    if (($data['allocation_type'] ?? '') === 'own_share' && $data['borrower_id']) {
        $errors['borrower_id'] = 'Your own share has no other person. Leave the person empty, or choose "Owes me".';
    }
    if (($data['allocated_amount'] ?? null) !== null && (float) $data['allocated_amount'] <= 0 && !isset($errors['allocated_amount'])) {
        $errors['allocated_amount'] = 'The amount must be more than zero.';
    }

    return $errors;
}

/**
 * Monthly figures of an allocation: its principal per month (allocated / term,
 * the last month takes the rounding) and its proportional share of the bank's
 * monthly installment (allocated / loan principal x installment). Null when the
 * loan has no term yet.
 */
function allocation_monthly(array $loan, float $allocated, ?float $bankInstallment): ?array
{
    $term = (int) $loan['term_months'];
    if ($term < 1) {
        return null;
    }
    $parts = installment_split((int) round($allocated * 100), $term);
    $principal = cash_loan_principal($loan) ?: cash_loan_distributable($loan);

    return [
        'principal' => $parts[0] / 100, 'last' => end($parts) / 100,
        'bank_share' => $bankInstallment !== null && $principal > 0 ? round($bankInstallment * $allocated / $principal, 2) : null,
    ];
}

/**
 * Flash text telling how much of the card's credit a saved loan uses.
 */
function cash_loan_usage_message(array $loan): string
{
    $charge = cash_loan_card_charge($loan);
    if (!$charge['counts']) {
        return 'Status "' . humanize($loan['status']) . '" does not use the card\'s credit. Change it to Accepted once you take the loan.';
    }

    return 'It uses ' . format_money($charge['total']) . ' of the card\'s credit.';
}

/**
 * Keep a cash loan allocation and its obligation in step when the obligation's
 * original amount (the person's share) is corrected: the allocation takes the
 * new amount (never more than the loan's cash not allocated to others) and an
 * automatic "share of ₱..." description is rewritten. Returns [description,
 * error|null]; call inside the transaction that saves the obligation.
 */
function cash_loan_allocation_follow(array $obligation, float $newAmount, string $description): array
{
    $allocation = db_row('SELECT * FROM cash_loan_allocations WHERE id = :a FOR UPDATE', [':a' => $obligation['cash_loan_allocation_id']]);
    if (!$allocation) {
        return [$description, null];
    }
    $loan = db_row('SELECT * FROM cash_loans WHERE id = :l FOR UPDATE', [':l' => $allocation['cash_loan_id']]);
    if (abs((float) $allocation['allocated_amount'] - $newAmount) >= 0.005) {
        $others = (float) db_value('SELECT COALESCE(SUM(allocated_amount), 0) FROM cash_loan_allocations WHERE cash_loan_id = :l AND id <> :a',
            [':l' => $loan['id'], ':a' => $allocation['id']]);
        $room = cash_loan_distributable($loan) - $others;
        if ($newAmount > $room + 0.004) {
            return [$description, 'Cash loan ' . $loan['loan_reference'] . ' has ' . format_money(cash_loan_distributable($loan)) . ', and '
                . format_money($others) . ' of it is allocated to others: this share can be at most ' . format_money(max(0, $room))
                . '. Reduce the other allocations on the cash loan first.'];
        }
        db_update('cash_loan_allocations', ['allocated_amount' => number_format($newAmount, 2, '.', '')], $allocation['id']);
        log_activity('update_allocation', 'cash_loan', $loan['id'], 'Allocation ' . format_money($allocation['allocated_amount']) . ' → ' . format_money($newAmount)
            . ' (obligation ' . $obligation['obligation_number'] . ')', ['allocated_amount' => $allocation['allocated_amount']], ['allocated_amount' => $newAmount]);
    }
    // The automatic description names the share; keep it true.
    $description = preg_replace('/share of ₱[\d,]+(\.\d{1,2})?/u', 'share of ' . format_money($newAmount), $description, 1) ?? $description;

    return [$description, null];
}

/**
 * Date of the loan's last installment: from its repayment schedule, else the
 * first due date plus the term (counted the way the schedule is built), else
 * the end date entered on the loan.
 */
function cash_loan_last_due(array $loan): ?string
{
    if (!empty($loan['id'])) {
        $last = db_value("SELECT MAX(due_date) FROM cash_loan_schedules WHERE cash_loan_id = :l AND status <> 'cancelled'", [':l' => $loan['id']]);
        if ($last) {
            return $last;
        }
    }
    if ((int) ($loan['term_months'] ?? 0) > 0 && !empty($loan['first_due_date'])) {
        return schedule_due_date($loan['first_due_date'], (int) $loan['term_months'] - 1, $loan['payment_frequency'] ?: 'monthly');
    }

    return $loan['end_date'] ?: null;
}

/**
 * Build the loan's repayment schedule from its own terms (term, first due
 * date, total repayment) when it has none yet. Returns the number of
 * installments created (0 when it already has one or the terms are not
 * complete). Call inside a transaction.
 */
function cash_loan_generate_schedule(array $loan): int
{
    if ((int) db_value('SELECT COUNT(*) FROM cash_loan_schedules WHERE cash_loan_id = :id', [':id' => $loan['id']]) > 0
        || !$loan['term_months'] || !$loan['first_due_date'] || (float) $loan['total_repayment'] <= 0) {
        return 0;
    }
    // Same principal as the card usage calculation.
    $loanPrincipal = cash_loan_principal($loan);
    $principal = $loanPrincipal > 0 ? min((float) $loan['total_repayment'], $loanPrincipal) : (float) $loan['total_repayment'];
    $charges = (float) $loan['total_repayment'] - $principal;
    $rows = build_schedule($principal, $charges, 0, (int) $loan['term_months'], $loan['first_due_date'], $loan['payment_frequency'] ?? 'monthly');
    foreach ($rows as $row) {
        db_insert('cash_loan_schedules', ['id' => new_uuid(), 'cash_loan_id' => $loan['id']] + $row);
    }
    if (!$loan['end_date']) {
        db_update('cash_loans', ['end_date' => end($rows)['due_date']], $loan['id']);
    }

    return count($rows);
}

/**
 * Turn an "owes me" allocation into the person's obligation, status active:
 * their share, repaid over the loan's term and due on the loan's last
 * installment date (the loan's schedule is built first when its terms are
 * complete). Returns [obligation id, number, installments] or null when the
 * allocation is not a debt of one of your borrowers or already has one.
 * Call inside a transaction.
 */
function cash_loan_allocation_obligation(array $loan, string $allocationId, string $userId): ?array
{
    $allocation = db_row('SELECT * FROM cash_loan_allocations WHERE id = :i AND cash_loan_id = :l FOR UPDATE', [':i' => $allocationId, ':l' => $loan['id']]);
    // Only a part someone repays to the card owner is a debt.
    if (!$allocation || !in_array($allocation['allocation_type'], CASH_LOAN_DEBT_TYPES, true)
        || !$allocation['borrower_id'] || !owns('borrowers', $allocation['borrower_id']) || $allocation['obligation_id']) {
        return null;
    }
    cash_loan_generate_schedule($loan);
    $due = cash_loan_last_due($loan);
    $installments = (int) db_value("SELECT COUNT(*) FROM cash_loan_schedules WHERE cash_loan_id = :l AND status <> 'cancelled'", [':l' => $loan['id']]) ?: (int) $loan['term_months'];

    $obligationId = new_uuid();
    $number = generate_reference('OBL', 'borrower_obligations', 'obligation_number');
    db_insert('borrower_obligations', [
        'id' => $obligationId, 'obligation_number' => $number, 'borrower_id' => $allocation['borrower_id'],
        'cash_loan_allocation_id' => $allocation['id'],
        'description' => 'Cash loan ' . $loan['loan_reference'] . ' — share of ' . format_money($allocation['allocated_amount'])
            . ($installments ? ' over ' . $installments . ' months' : '') . ($allocation['description'] ? ' — ' . $allocation['description'] : ''),
        'obligation_date' => $loan['start_date'] ?: date('Y-m-d'),
        'original_amount' => $allocation['allocated_amount'], 'total_amount_due' => $allocation['allocated_amount'],
        'total_paid' => '0.00', 'outstanding_amount' => $allocation['allocated_amount'], 'status' => 'active',
        'due_date' => $due,
        'created_by' => $userId, 'updated_by' => $userId,
    ]);
    db_update('cash_loan_allocations', ['obligation_id' => $obligationId], $allocation['id']);
    schedule_sync_obligation($obligationId);
    notify_borrower($allocation['borrower_id'], 'obligation_created', 'New item: ' . $number,
        format_money($allocation['allocated_amount']) . ' (your share of cash loan ' . $loan['loan_reference'] . ') was added to your balance'
        . ($due ? ', last payment due ' . format_date($due) : '') . '.', 'obligation', $obligationId);

    return [$obligationId, $number, $installments];
}

/**
 * Save an edited loan and keep its schedule and its borrowers' obligations in
 * step with the terms:
 *   - a new term or total repayment rebuilds a schedule nothing was paid on
 *     yet (refused once payments were made on it);
 *   - a new first due date or frequency moves the existing schedule;
 *   - a loan with obligations but no schedule gets one once its terms are
 *     complete;
 *   - obligations due on the loan's old last installment move to the new one,
 *     and their "over N months" text follows the term.
 * Returns [old last due, new last due] when the last due date changed.
 * Call inside a transaction.
 */
function cash_loan_terms_follow(array $loan, array &$data, string $userId): ?array
{
    $oldLast = cash_loan_last_due($loan);
    $rows = (int) db_value('SELECT COUNT(*) FROM cash_loan_schedules WHERE cash_loan_id = :l', [':l' => $loan['id']]);
    $frequency = $data['payment_frequency'] ?? null;
    $termChanged = (int) $data['term_months'] !== (int) $loan['term_months']
        || abs((float) $data['total_repayment'] - (float) $loan['total_repayment']) >= 0.005;
    $datesChanged = $data['first_due_date'] !== $loan['first_due_date'] || $frequency !== $loan['payment_frequency'];

    $rebuild = false;
    if ($rows > 0 && $termChanged) {
        $paid = (float) db_value('SELECT COALESCE(SUM(amount_paid), 0) FROM cash_loan_schedules WHERE cash_loan_id = :l', [':l' => $loan['id']]);
        if ($paid > 0.004) {
            throw new DomainException(format_money($paid) . ' was already paid on this loan\'s schedule, so its term and total repayment can no longer change.');
        }
        // A plan nothing was paid on: rebuilt below from the new terms.
        db_exec('DELETE FROM cash_loan_schedules WHERE cash_loan_id = :l', [':l' => $loan['id']]);
        $rows = 0;
        $rebuild = true;
    } elseif ($rows > 0 && $datesChanged && $data['first_due_date']) {
        $data['end_date'] = schedule_shift_dates('cash_loan_schedules', 'cash_loan_id', $loan['id'], $data['first_due_date'], $frequency ?? 'monthly');
    }
    db_update('cash_loans', $data + ['updated_by' => $userId], $loan['id']);

    $ids = array_column(db_all(
        "SELECT o.id FROM borrower_obligations o JOIN cash_loan_allocations a ON a.id = o.cash_loan_allocation_id
          WHERE a.cash_loan_id = :l AND o.status <> 'cancelled'", [':l' => $loan['id']]), 'id');
    $fresh = db_row('SELECT * FROM cash_loans WHERE id = :l', [':l' => $loan['id']]);
    if ($rows === 0 && ($ids || $rebuild)) {
        cash_loan_generate_schedule($fresh);
    }
    if ($termChanged && (int) $fresh['term_months'] > 0) {
        foreach ($ids as $oid) {
            $description = (string) db_value('SELECT description FROM borrower_obligations WHERE id = :id', [':id' => $oid]);
            $new = preg_replace('/ over \d+ months/', ' over ' . (int) $fresh['term_months'] . ' months', $description, 1);
            if ($new !== null && $new !== $description) {
                db_update('borrower_obligations', ['description' => $new], $oid);
            }
        }
    }
    $newLast = cash_loan_last_due($fresh);
    schedule_move_obligations($ids, $oldLast, $newLast);
    foreach ($ids as $oid) {
        obligation_rate_refresh($oid);   // an agreed % per month follows the new number of months
        schedule_sync_obligation($oid);
    }

    return $oldLast !== $newLast && $newLast !== null ? [$oldLast, $newLast] : null;
}

/** The loan's borrower obligations as they are now (id => row), to tell each borrower what a loan change did. */
function cash_loan_obligation_snapshot(string $loanId): array
{
    $rows = [];
    foreach (db_all(
        "SELECT o.* FROM borrower_obligations o JOIN cash_loan_allocations a ON a.id = o.cash_loan_allocation_id
          WHERE a.cash_loan_id = :l AND o.status <> 'cancelled'", [':l' => $loanId]) as $o) {
        $rows[$o['id']] = $o;
    }

    return $rows;
}

/** Notify each borrower whose obligation changed with the loan (due date, months, amount). */
function cash_loan_notify_followers(array $before, string $note = ''): void
{
    foreach ($before as $id => $old) {
        $new = db_row('SELECT * FROM borrower_obligations WHERE id = :id', [':id' => $id]);
        if ($new) {
            notify_borrower_changes($old['borrower_id'], $old['obligation_number'], $old, $new, OBLIGATION_NOTICE_FIELDS, 'obligation', $id, $note);
        }
    }
}
