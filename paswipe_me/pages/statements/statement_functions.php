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
