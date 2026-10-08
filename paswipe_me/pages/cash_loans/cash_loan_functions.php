<?php
/**
 * Cash Loans module (tables: cash_loans, cash_loan_schedules, cash_loan_allocations).
 *
 * A bank cash loan on a card can be split ("allocated") to borrowers.
 * An allocation to a borrower can be turned into a borrower obligation;
 * the two rows are then linked both ways (cash_loan_allocations.obligation_id
 * and borrower_obligations.cash_loan_allocation_id).
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
          WHERE l.id = :id',
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

function allocation_fields(): array
{
    return [
        'borrower_id'      => ['label' => 'Borrower', 'type' => 'select', 'options' => borrower_options(), 'empty_label' => '— Owner / not a borrower —'],
        'allocation_type'  => ['label' => 'Allocation type', 'type' => 'text', 'max' => 50, 'required' => true, 'initial' => 'borrower', 'help' => 'e.g. borrower, personal, fees.'],
        'allocated_amount' => ['label' => 'Amount', 'type' => 'decimal', 'required' => true, 'min' => 0],
        'description'      => ['label' => 'Description', 'type' => 'text', 'max' => 500],
    ];
}
