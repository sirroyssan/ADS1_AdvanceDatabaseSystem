<?php
/**
 * Obligations module (tables: borrower_obligations, applied_penalties).
 *
 * total_amount_due = original_amount + agreed_interest + agreed_fees
 * outstanding_amount = total_amount_due - total_paid
 * total_paid only changes through approved borrower payment allocations.
 *
 * No delete: obligations are financial history (payments, penalties, agreements,
 * demand letters point to them). Cancel (no payments yet) or write off instead.
 */

defined('APP_ROOT') or exit('No direct access.');

// Statuses an administrator may set by hand ('paid' is set automatically).
const OBLIGATION_MANUAL_STATUSES = ['pending', 'active', 'partially_paid', 'overdue', 'delinquent', 'cancelled', 'written_off'];

function obligation_fields(bool $isNew, ?array $current = null): array
{
    [$txnOptions, $txnData] = transaction_options($current['transaction_id'] ?? null);

    $fields = [
        'obligation_number' => ['label' => 'Obligation number', 'type' => 'text', 'max' => 50,
                                'unique' => ['borrower_obligations', 'obligation_number', 'This obligation number is already used.'],
                                'help' => $isNew ? 'Leave blank to generate one automatically.' : ''],
        'borrower_id'       => ['label' => 'Borrower', 'type' => 'select', 'options' => borrower_options($current['borrower_id'] ?? null), 'required' => true, 'empty_label' => 'Select borrower'],
        'transaction_id'    => ['label' => 'Transaction', 'type' => 'select', 'options' => $txnOptions, 'option_data' => $txnData,
                                'attrs' => ['data-depends-on' => 'f_borrower_id'], 'empty_label' => '— Not linked to a transaction —',
                                'help' => 'Only the selected borrower\'s transactions are listed.'],
        'description'       => ['label' => 'Description', 'type' => 'text', 'max' => 500, 'full' => true],
        'obligation_date'   => ['label' => 'Obligation date', 'type' => 'date', 'required' => true, 'initial' => date('Y-m-d')],
        'due_date'          => ['label' => 'Due date', 'type' => 'date'],
        'original_amount'   => ['label' => 'Original amount', 'type' => 'decimal', 'required' => true, 'min' => 0, 'section' => 'Amounts'],
        'agreed_interest'   => ['label' => 'Agreed interest', 'type' => 'decimal', 'min' => 0, 'default' => '0.00'],
        'agreed_fees'       => ['label' => 'Agreed fees', 'type' => 'decimal', 'min' => 0, 'default' => '0.00'],
        'overdue_amount'    => ['label' => 'Overdue amount', 'type' => 'decimal', 'min' => 0, 'default' => '0.00', 'readonly' => true,
                                'help' => 'Calculated automatically: what was due by today minus what the borrower has paid.'],
        'notes'             => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 3],
    ];

    if ($isNew) {
        $fields['status'] = ['label' => 'Status', 'type' => 'select', 'options' => enum_options(['pending', 'active']), 'required' => true, 'initial' => 'active'];
    } else {
        $fields['obligation_number']['required'] = true;
        $lock = obligation_borrower_lock($current);
        if ($lock !== null) {
            $fields['borrower_id']['readonly'] = true;
            $fields['borrower_id']['help'] = 'The borrower cannot be changed: ' . $lock;
        } else {
            $fields['borrower_id']['help'] = 'Picked the wrong person? Choose the right borrower here; nothing has been paid on this yet.';
        }
    }

    return $fields;
}

/**
 * Why an obligation's borrower can no longer be changed, or null when it can
 * (a wrong person picked by mistake): nothing paid or chosen in a payment,
 * not tied to a transaction's borrower, no agreement or notice made for it.
 */
function obligation_borrower_lock(array $o): ?string
{
    if ((float) $o['total_paid'] > 0.004
        || (int) db_value('SELECT COUNT(*) FROM borrower_payment_allocations WHERE obligation_id = :o', [':o' => $o['id']]) > 0) {
        return 'payments were already applied to it.';
    }
    if ((int) db_value("SELECT COUNT(*) FROM borrower_payment_selections s JOIN borrower_payments p ON p.id = s.borrower_payment_id
                         WHERE s.obligation_id = :o AND p.status NOT IN ('rejected', 'cancelled')", [':o' => $o['id']]) > 0) {
        return 'a payment the borrower submitted for it is waiting for review.';
    }
    if ($o['transaction_id']) {
        return 'it belongs to a card transaction; change the borrower on the transaction.';
    }
    if ((int) db_value('SELECT COUNT(*) FROM agreements WHERE obligation_id = :o', [':o' => $o['id']])
        + (int) db_value('SELECT COUNT(*) FROM demand_letters WHERE obligation_id = :o', [':o' => $o['id']]) > 0) {
        return 'an agreement or notice was already made for this borrower.';
    }

    return null;
}

/**
 * Validate and compute totals. $totalPaid is 0 for new obligations.
 */
function obligation_validate(array $fields, array $input, ?string $id, float $totalPaid, ?string $borrowerId = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);
    $borrowerId = $data['borrower_id'] ?? $borrowerId;

    if ($data['due_date'] && $data['obligation_date'] && $data['due_date'] < $data['obligation_date']) {
        $errors['due_date'] = 'Due date cannot be before the obligation date.';
    }
    if ($data['transaction_id'] && $borrowerId && !belongs_to('transactions', $data['transaction_id'], 'borrower_id', $borrowerId)) {
        $errors['transaction_id'] = 'The transaction must belong to the selected borrower.';
    }

    if (!array_intersect_key($errors, array_flip(['original_amount', 'agreed_interest', 'agreed_fees', 'overdue_amount']))) {
        $total = (float) $data['original_amount'] + (float) $data['agreed_interest'] + (float) $data['agreed_fees'];
        if ($total + 0.004 < $totalPaid) {
            $errors['original_amount'] = 'The total due cannot be less than what was already paid (' . format_money($totalPaid) . ').';
        }
        $outstanding = max(0, $total - $totalPaid);
        $data['total_amount_due'] = number_format($total, 2, '.', '');
        $data['outstanding_amount'] = number_format($outstanding, 2, '.', '');
    }

    return [$data, $errors];
}

function obligation_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        "SELECT o.*, b.first_name, b.last_name, b.borrower_number, t.transaction_number,
                a.cash_loan_id, cl.loan_reference
           FROM borrower_obligations o
           JOIN borrowers b ON b.id = o.borrower_id
           LEFT JOIN transactions t ON t.id = o.transaction_id
           LEFT JOIN cash_loan_allocations a ON a.id = o.cash_loan_allocation_id
           LEFT JOIN cash_loans cl ON cl.id = a.cash_loan_id
          WHERE o.id = :id AND " . owner_scope('borrower_obligations', 'o'),
        [':id' => $id]
    );
}

function penalty_fields(): array
{
    return [
        'penalty_rule_id'   => ['label' => 'Penalty rule', 'type' => 'select', 'options' => penalty_rule_options(), 'required' => true, 'empty_label' => 'Select rule'],
        'applied_date'      => ['label' => 'Applied date', 'type' => 'date', 'required' => true, 'initial' => date('Y-m-d')],
        'overdue_days'      => ['label' => 'Overdue days', 'type' => 'int', 'min' => 0, 'max' => 36500, 'default' => 0],
        'calculation_base'  => ['label' => 'Calculation base', 'type' => 'decimal', 'required' => true, 'min' => 0],
        'amount'            => ['label' => 'Penalty amount', 'type' => 'decimal', 'min' => 0, 'help' => 'Leave blank to compute from the rule.'],
        'notes'             => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 2],
    ];
}
