<?php
/**
 * Cash Advances module (table: cash_advances) - cash withdrawn on a card.
 * No delete: status 'cancelled' voids a record while keeping history.
 */

defined('APP_ROOT') or exit('No direct access.');

function cash_advance_fields(?array $current = null): array
{
    [$statementOptions, $statementData] = statement_options();

    return [
        'credit_card_id'   => ['label' => 'Credit card', 'type' => 'select', 'options' => credit_card_options($current['credit_card_id'] ?? null, false), 'required' => true, 'empty_label' => 'Select card'],
        'advance_date'     => ['label' => 'Advance date', 'type' => 'date', 'required' => true, 'initial' => date('Y-m-d')],
        'reference_number' => ['label' => 'Reference number', 'type' => 'text', 'max' => 150],
        'channel'          => ['label' => 'Channel', 'type' => 'text', 'max' => 100, 'help' => 'e.g. ATM, over the counter.'],
        'status'           => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_CASH_ADVANCE_STATUS), 'required' => true, 'initial' => 'active'],
        'amount'           => ['label' => 'Amount withdrawn', 'type' => 'decimal', 'required' => true, 'min' => 0, 'section' => 'Amounts'],
        'fee'              => ['label' => 'Fee', 'type' => 'decimal', 'min' => 0, 'default' => '0.00'],
        'interest'         => ['label' => 'Interest', 'type' => 'decimal', 'min' => 0, 'default' => '0.00'],
        'total_amount'     => ['label' => 'Total amount', 'type' => 'decimal', 'min' => 0, 'help' => 'Leave blank for amount + fee + interest.',
                               'attrs' => ['data-sum' => 'f_amount,f_fee,f_interest']],
        'statement_id'     => ['label' => 'Statement', 'type' => 'select', 'options' => $statementOptions, 'option_data' => $statementData,
                               'attrs' => ['data-depends-on' => 'f_credit_card_id'], 'empty_label' => '— Not linked to a statement —', 'section' => 'Other'],
        'notes'            => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 3],
    ];
}

function cash_advance_validate(array $fields, array $input, ?string $id = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);

    if ($data['total_amount'] === null && !isset($errors['amount'], $errors['fee'], $errors['interest'])) {
        $data['total_amount'] = number_format((float) $data['amount'] + (float) $data['fee'] + (float) $data['interest'], 2, '.', '');
    }
    $cardId = $data['credit_card_id'] ?? ($id ? db_value('SELECT credit_card_id FROM cash_advances WHERE id = :id', [':id' => $id]) : null);
    if ($data['statement_id'] && $cardId && !belongs_to('credit_card_statements', $data['statement_id'], 'credit_card_id', $cardId)) {
        $errors['statement_id'] = 'The statement must belong to the selected card.';
    }

    return [$data, $errors];
}

function cash_advance_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        'SELECT a.*, c.card_nickname, c.last_four, s.statement_date
           FROM cash_advances a
           JOIN credit_cards c ON c.id = a.credit_card_id
           LEFT JOIN credit_card_statements s ON s.id = a.statement_id
          WHERE a.id = :id',
        [':id' => $id]
    );
}

/* ------------------------------------------------------------------
 * Giving (part of) a cash advance to a borrower.
 *
 * The schema has no borrower column on cash_advances, so each share is
 * stored with existing tables:
 *   transactions        payment_type 'cash_advance', borrower_id, same card and
 *                       the advance's reference_number (the link; card usage
 *                       counts the withdrawal once, from cash_advances)
 *   installment_plans + installment_schedules   the monthly repayment
 *   borrower_obligations  amount given + fee + monthly interest
 * Interest is add-on monthly interest: amount × rate % × months.
 * ------------------------------------------------------------------ */

function cash_advance_shares(array $advance): array
{
    if (!$advance['reference_number']) {
        return [];
    }

    return db_all(
        "SELECT t.id AS transaction_id, t.transaction_number, t.transaction_date, t.amount, t.status AS transaction_status,
                b.id AS borrower_id, b.first_name, b.last_name, b.borrower_number,
                o.id AS obligation_id, o.obligation_number, o.total_amount_due, o.outstanding_amount, o.status AS obligation_status,
                p.number_of_installments, p.installment_amount
           FROM transactions t
           JOIN borrowers b ON b.id = t.borrower_id
           LEFT JOIN borrower_obligations o ON o.transaction_id = t.id AND o.status <> 'cancelled'
           LEFT JOIN installment_plans p ON p.transaction_id = t.id
          WHERE t.payment_type = 'cash_advance' AND t.credit_card_id = :c AND t.reference_number = :r AND t.status <> 'cancelled'
          ORDER BY t.transaction_date, t.created_at",
        [':c' => $advance['credit_card_id'], ':r' => $advance['reference_number']]
    );
}

function cash_advance_given_total(array $advance): float
{
    return array_sum(array_map(static fn ($s) => (float) $s['amount'], cash_advance_shares($advance)));
}

function cash_advance_give_fields(array $advance, float $remaining): array
{
    return [
        'borrower_id'   => ['label' => 'Borrower', 'type' => 'select', 'options' => borrower_options(), 'required' => true, 'empty_label' => 'Select borrower'],
        'given_date'    => ['label' => 'Date given', 'type' => 'date', 'required' => true],
        'amount'        => ['label' => 'Amount given', 'type' => 'decimal', 'required' => true, 'min_exclusive' => 0,
                            'help' => format_money($remaining) . ' of this withdrawal is not yet given to anyone.'],
        'fee'           => ['label' => 'Fee charged to the borrower', 'type' => 'decimal', 'min' => 0, 'default' => '0.00',
                            'help' => 'One-time fee, e.g. their share of the bank\'s cash advance fee.'],
        'monthly_rate'  => ['label' => 'Interest per month (%)', 'type' => 'decimal', 'scale' => 4, 'digits' => 8, 'min' => 0, 'default' => '0',
                            'help' => 'Add-on interest: amount given × rate × number of months.'],
        'months'        => ['label' => 'Number of monthly payments', 'type' => 'int', 'min' => 1, 'max' => 60, 'required' => true],
        'first_due_date' => ['label' => 'First payment due', 'type' => 'date', 'required' => true],
        'notes'         => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 2],
    ];
}

/**
 * Totals the borrower will owe for a share.
 */
function cash_advance_share_totals(float $amount, float $fee, float $rate, int $months): array
{
    $interest = round($amount * $rate / 100 * $months, 2);
    $total = round($amount + $fee + $interest, 2);

    return ['interest' => $interest, 'total' => $total, 'monthly' => $months > 0 ? round($total / $months, 2) : $total];
}

/**
 * Create the borrower's share: transaction + monthly plan + obligation.
 * Returns [obligation id, obligation number] or throws DomainException.
 */
function cash_advance_give(array $advance, array $data, array $user): array
{
    require_once APP_ROOT . '/pages/installment_plans/plan_functions.php';

    return card_usage_apply([$advance['credit_card_id']], function () use ($advance, $data, $user) {
        $fresh = db_row('SELECT * FROM cash_advances WHERE id = :id FOR UPDATE', [':id' => $advance['id']]);
        if ($fresh['status'] === 'cancelled') {
            throw new DomainException('A cancelled cash advance cannot be given to a borrower.');
        }
        // The reference number is the link between the withdrawal and the borrower's share.
        if (!$fresh['reference_number']) {
            $fresh['reference_number'] = generate_reference('CA', 'cash_advances', 'reference_number');
            db_update('cash_advances', ['reference_number' => $fresh['reference_number']], $fresh['id']);
        }
        $remaining = (float) $fresh['amount'] - cash_advance_given_total($fresh);
        if ((float) $data['amount'] > $remaining + 0.004) {
            throw new DomainException('Only ' . format_money(max(0, $remaining)) . ' of this withdrawal is not yet given to a borrower.');
        }

        $amount = (float) $data['amount'];
        $fee = (float) ($data['fee'] ?? 0);
        $months = (int) $data['months'];
        $t = cash_advance_share_totals($amount, $fee, (float) ($data['monthly_rate'] ?? 0), $months);

        $txnId = new_uuid();
        $txnNumber = generate_reference('TXN', 'transactions', 'transaction_number');
        db_insert('transactions', [
            'id' => $txnId, 'transaction_number' => $txnNumber, 'credit_card_id' => $fresh['credit_card_id'],
            'borrower_id' => $data['borrower_id'], 'transaction_date' => $data['given_date'], 'posting_date' => $fresh['advance_date'],
            'transaction_type' => 'card_user', 'payment_type' => 'cash_advance', 'status' => 'active',
            'amount' => number_format($amount, 2, '.', ''), 'currency' => 'PHP', 'reference_number' => $fresh['reference_number'],
            'notes' => 'Cash given from cash advance ' . $fresh['reference_number'] . ($data['notes'] ? "\n" . $data['notes'] : ''),
            'created_by' => $user['id'], 'updated_by' => $user['id'],
        ]);

        $planId = new_uuid();
        $plan = [
            'transaction_id' => $txnId, 'total_principal' => number_format($amount, 2, '.', ''), 'number_of_installments' => $months,
            'installment_amount' => number_format($t['monthly'], 2, '.', ''), 'interest_amount' => number_format($t['interest'], 2, '.', ''),
            'fees_amount' => number_format($fee, 2, '.', ''), 'total_repayment' => number_format($t['total'], 2, '.', ''),
            'frequency' => 'monthly', 'start_date' => $data['given_date'], 'first_due_date' => $data['first_due_date'], 'status' => 'active',
        ];
        db_insert('installment_plans', ['id' => $planId] + $plan);
        plan_generate_schedule($planId, $plan);
        $lastDue = db_value('SELECT end_date FROM installment_plans WHERE id = :id', [':id' => $planId]);

        $obligationId = new_uuid();
        $number = generate_reference('OBL', 'borrower_obligations', 'obligation_number');
        db_insert('borrower_obligations', [
            'id' => $obligationId, 'obligation_number' => $number, 'borrower_id' => $data['borrower_id'], 'transaction_id' => $txnId,
            'description' => 'Cash advance ' . $fresh['reference_number'] . ' — ' . $months . ' monthly payment(s) of ' . format_money($t['monthly']),
            'original_amount' => number_format($amount, 2, '.', ''), 'agreed_interest' => number_format($t['interest'], 2, '.', ''),
            'agreed_fees' => number_format($fee, 2, '.', ''), 'total_amount_due' => number_format($t['total'], 2, '.', ''),
            'total_paid' => '0.00', 'outstanding_amount' => number_format($t['total'], 2, '.', ''), 'status' => 'active',
            'obligation_date' => $data['given_date'], 'due_date' => $lastDue,
            'notes' => 'Interest ' . rtrim(rtrim(number_format((float) ($data['monthly_rate'] ?? 0), 4), '0'), '.') . '% per month × ' . $months . ' month(s).',
            'created_by' => $user['id'], 'updated_by' => $user['id'],
        ]);

        return [$obligationId, $number, $t];
    });
}
