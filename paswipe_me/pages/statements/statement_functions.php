<?php
/**
 * Statements module (table: credit_card_statements).
 * UNIQUE (credit_card_id, statement_date). Referenced by cash_advances,
 * credit_card_payments and bank_charges (statement_id).
 */

defined('APP_ROOT') or exit('No direct access.');

const STATEMENT_AMOUNT_FIELDS = ['previous_balance', 'purchases', 'payments', 'interest', 'finance_charges', 'late_fees', 'other_charges', 'credits', 'adjustments'];

function statement_fields(?array $current = null): array
{
    $money = static fn (string $label, array $extra = []) => ['label' => $label, 'type' => 'decimal', 'min' => 0, 'default' => '0.00'] + $extra;

    return [
        'credit_card_id'         => ['label' => 'Credit card', 'type' => 'select', 'options' => credit_card_options($current['credit_card_id'] ?? null, false), 'required' => true, 'empty_label' => 'Select card'],
        'statement_number'       => ['label' => 'Statement number', 'type' => 'text', 'max' => 100],
        'statement_date'         => ['label' => 'Statement date', 'type' => 'date', 'required' => true],
        'cutoff_date'            => ['label' => 'Cut-off date', 'type' => 'date'],
        'due_date'               => ['label' => 'Due date', 'type' => 'date', 'required' => true],
        'attachment_document_id' => ['label' => 'Statement file', 'type' => 'select', 'options' => document_options($current['attachment_document_id'] ?? null), 'empty_label' => '— No file —', 'help' => 'Upload the PDF first in Document Files.'],
        'previous_balance'       => $money('Previous balance', ['section' => 'Amounts']),
        'purchases'              => $money('Purchases'),
        'payments'               => $money('Payments'),
        'interest'               => $money('Interest'),
        'finance_charges'        => $money('Finance charges'),
        'late_fees'              => $money('Late fees'),
        'other_charges'          => $money('Other charges'),
        'credits'                => $money('Credits'),
        'adjustments'            => ['label' => 'Adjustments', 'type' => 'decimal', 'default' => '0.00', 'help' => 'May be negative.'],
        'total_amount_due'       => ['label' => 'Total amount due', 'type' => 'decimal', 'min' => 0, 'help' => 'Leave blank to compute: previous + purchases − payments + interest + charges + fees − credits + adjustments.'],
        'minimum_amount_due'     => $money('Minimum amount due'),
    ];
}

function statement_validate(array $fields, array $input, ?string $id = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);

    if ($data['statement_date'] && $data['due_date'] && $data['due_date'] < $data['statement_date']) {
        $errors['due_date'] = 'Due date must be on or after the statement date.';
    }
    if ($data['statement_date'] && $data['cutoff_date'] && $data['cutoff_date'] > $data['statement_date']) {
        $errors['cutoff_date'] = 'Cut-off date cannot be after the statement date.';
    }

    if ($data['total_amount_due'] === null && !array_intersect_key($errors, array_flip(STATEMENT_AMOUNT_FIELDS))) {
        $total = (float) $data['previous_balance'] + (float) $data['purchases'] - (float) $data['payments']
            + (float) $data['interest'] + (float) $data['finance_charges'] + (float) $data['late_fees']
            + (float) $data['other_charges'] - (float) $data['credits'] + (float) $data['adjustments'];
        $data['total_amount_due'] = number_format(max(0, $total), 2, '.', '');
    }
    if ($data['total_amount_due'] !== null && (float) $data['minimum_amount_due'] > (float) $data['total_amount_due']) {
        $errors['minimum_amount_due'] = 'Minimum amount due cannot be more than the total amount due.';
    }

    // A statement typed in by hand must not overlap the periods of generated statements.
    if (!isset($errors['credit_card_id']) && !isset($errors['statement_date']) && $data['credit_card_id'] && $data['statement_date']
        && ($covering = db_row(
            "SELECT cutoff_date FROM credit_card_statements WHERE credit_card_id = :c AND source = 'generated' AND id <> :id
                AND :d BETWEEN period_start_date AND cutoff_date",
            [':c' => $data['credit_card_id'], ':d' => $data['cutoff_date'] ?: $data['statement_date'], ':id' => $id ?? '']
        ))) {
        $errors['statement_date'] = 'This date is already covered by the generated statement cut off ' . format_date($covering['cutoff_date']) . '. Edit or reconcile that statement instead.';
    }

    if (!isset($errors['credit_card_id']) && !isset($errors['statement_date']) && $data['credit_card_id'] && $data['statement_date']
        && (int) db_value(
            'SELECT COUNT(*) FROM credit_card_statements WHERE credit_card_id = :c AND statement_date = :d AND id <> :id',
            [':c' => $data['credit_card_id'], ':d' => $data['statement_date'], ':id' => $id ?? '']
        ) > 0) {
        $errors['statement_date'] = 'This card already has a statement on this date.';
    }

    return [$data, $errors];
}

function statement_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        "SELECT s.*, c.card_nickname, c.issuer, c.last_four, d.original_filename
           FROM credit_card_statements s
           JOIN credit_cards c ON c.id = s.credit_card_id
           LEFT JOIN documents d ON d.id = s.attachment_document_id
          WHERE s.id = :id",
        [':id' => $id]
    );
}

function statement_usage(string $id): array
{
    return record_usage([
        'card payments' => ['credit_card_payments', 'statement_id'],
        'bank charges'  => ['bank_charges', 'statement_id'],
        'cash advances' => ['cash_advances', 'statement_id'],
    ], $id);
}

function statement_label(array $s): string
{
    return $s['card_nickname'] . ' •••• ' . $s['last_four'] . ' — ' . format_date($s['statement_date']);
}

/* ------------------------------------------------------------------
 * Generated statements (includes/statement_engine.php)
 * ------------------------------------------------------------------ */

/** True when the statement is the card's latest and nothing was reconciled on it yet. */
function statement_period_editable(array $statement): bool
{
    if ($statement['source'] !== 'generated') {
        return false;
    }
    $latest = db_value('SELECT id FROM credit_card_statements WHERE credit_card_id = :c ORDER BY statement_date DESC LIMIT 1', [':c' => $statement['credit_card_id']]);

    return $latest === $statement['id']
        && (int) db_value('SELECT COUNT(*) FROM statement_reconciliations WHERE statement_id = :s', [':s' => $statement['id']]) === 0;
}

/**
 * What can be changed on a generated statement. The charges and payments
 * come from the records; corrections go there, or into the adjustment.
 */
function generated_statement_fields(array $statement): array
{
    $fields = [
        'statement_number'       => ['label' => 'Statement number', 'type' => 'text', 'max' => 100, 'help' => 'As printed on the bank\'s SOA (optional).'],
        'statement_date'         => ['label' => 'Statement date', 'type' => 'date', 'required' => true, 'help' => 'Date the bank issued it; on or after the cutoff.'],
    ];
    if (statement_period_editable($statement)) {
        $fields['cutoff_date'] = ['label' => 'Cutoff date', 'type' => 'date', 'required' => true,
            'help' => 'Only the latest statement\'s cutoff can be moved (e.g. the bank closed it early). Records then move between this statement and the current cycle by their dates.'];
    }

    return $fields + [
        'due_date'               => ['label' => 'Payment due date', 'type' => 'date', 'required' => true,
                                     'help' => 'Change it when the bank set a different date; it is then kept as entered.'],
        'minimum_amount_due'     => ['label' => 'Minimum amount due', 'type' => 'decimal', 'min' => 0,
                                     'help' => 'Leave blank to use the card\'s minimum-due rule (or "needs confirmation" when the rule is not known).'],
        'adjustments'            => ['label' => 'Adjustment', 'type' => 'decimal', 'default' => '0.00', 'section' => 'Correction',
                                     'help' => 'Only for a difference that is not a missing record (add missing charges and payments as records instead). May be negative.'],
        'adjustment_reason'      => ['label' => 'Reason for the adjustment', 'type' => 'text', 'max' => 255],
        'attachment_document_id' => ['label' => 'Statement file', 'type' => 'select', 'options' => document_options($statement['attachment_document_id'] ?? null), 'empty_label' => '— No file —', 'help' => 'Upload the SOA PDF first in Document Files.'],
    ];
}

function generated_statement_validate(array $fields, array $input, array $statement): array
{
    [$data, $errors] = validate_fields($fields, $input, $statement['id']);
    $cutoff = $data['cutoff_date'] ?? $statement['cutoff_date'];

    if (isset($data['cutoff_date']) && $data['cutoff_date']) {
        if ($data['cutoff_date'] < $statement['period_start_date']) {
            $errors['cutoff_date'] = 'The cutoff cannot be before the period start (' . format_date($statement['period_start_date']) . ').';
        } elseif ($data['cutoff_date'] >= date('Y-m-d')) {
            $errors['cutoff_date'] = 'A statement can only be cut off before today.';
        }
    }
    if ($data['statement_date'] && $cutoff && $data['statement_date'] < $cutoff) {
        $errors['statement_date'] = 'The statement date cannot be before the cutoff date.';
    }
    if ($data['due_date'] && $data['statement_date'] && $data['due_date'] < $data['statement_date']) {
        $errors['due_date'] = 'The due date must be on or after the statement date.';
    }
    if ($data['statement_date'] && (int) db_value(
        'SELECT COUNT(*) FROM credit_card_statements WHERE credit_card_id = :c AND statement_date = :d AND id <> :id',
        [':c' => $statement['credit_card_id'], ':d' => $data['statement_date'], ':id' => $statement['id']]
    ) > 0) {
        $errors['statement_date'] = 'This card already has a statement on this date.';
    }
    $adjustmentChanged = number_format((float) $data['adjustments'], 2, '.', '') !== number_format((float) $statement['adjustments'], 2, '.', '');
    if ($adjustmentChanged && trim((string) $data['adjustment_reason']) === '') {
        $errors['adjustment_reason'] = 'Say why the statement is adjusted (kept in the history).';
    }
    if ((float) $data['adjustments'] == 0.0 && !$adjustmentChanged && $data['adjustment_reason'] === null) {
        $data['adjustment_reason'] = $statement['adjustment_reason'];
    }

    return [$data, $errors];
}

/** Edit page of the record behind a statement line (null when it has none). */
function statement_line_edit_url(array $line): ?string
{
    parse_str((string) parse_url($line['link'], PHP_URL_QUERY), $query);
    $id = $query['id'] ?? null;
    $module = match ($line['kind']) {
        'transaction'  => 'transactions',
        'bank_charge'  => 'bank_charges',
        'cash_advance' => 'cash_advances',
        'payment'      => 'card_payments',
        'installment'  => 'installment_plans',
        'cash_loan'    => 'cash_loans',
        default        => null,
    };

    return $module && $id ? url('pages/' . $module . '/edit.php', ['id' => $id]) : null;
}

/** "Add missing …" links for a statement: label => [url, icon]. */
function statement_missing_links(array $statement): array
{
    $date = $statement['cutoff_date'] ?: $statement['statement_date'];
    $base = ['credit_card_id' => $statement['credit_card_id'], 'reconcile' => $statement['id']];

    return [
        'Purchase or installment purchase' => [url('pages/transactions/create.php', $base + ['transaction_date' => $date]), 'layers'],
        'Annual fee, interest or other bank charge' => [url('pages/bank_charges/create.php', $base + ['charge_date' => $date]), 'receipt'],
        'Cash advance' => [url('pages/cash_advances/create.php', $base + ['advance_date' => $date]), 'banknote'],
        'Payment or credit to the card' => [url('pages/card_payments/create.php', $base + ['payment_date' => $date]), 'wallet'],
    ];
}
