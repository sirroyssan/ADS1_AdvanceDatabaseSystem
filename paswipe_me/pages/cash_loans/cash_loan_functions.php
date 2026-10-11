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
        'total_repayment'      => $money('Total repayment'),
        'term_months'          => ['label' => 'Term (months)', 'type' => 'int', 'min' => 1, 'max' => 360, 'section' => 'Repayment terms'],
        'monthly_payment'      => ['label' => 'Monthly payment', 'type' => 'decimal', 'min' => 0, 'attrs' => ['data-divide' => 'f_total_repayment,f_term_months']],
        'payment_frequency'    => ['label' => 'Payment frequency', 'type' => 'select', 'options' => enum_options(ENUM_FREQUENCY), 'empty_label' => '— Not set —'],
        'start_date'           => ['label' => 'Start date', 'type' => 'date'],
        'first_due_date'       => ['label' => 'First due date', 'type' => 'date'],
        'end_date'             => ['label' => 'End date', 'type' => 'date'],
        'notes'                => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 3],
    ];
}

function cash_loan_validate(array $fields, array $input, ?string $id = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);

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
                               'help' => 'You stay responsible to the bank for the whole loan. "Owes me" can become an obligation that follows the loan\'s monthly schedule.'],
        'allocated_amount' => ['label' => 'Amount', 'type' => 'decimal', 'required' => true, 'min' => 0],
        'description'      => ['label' => 'Description', 'type' => 'text', 'max' => 500],
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
