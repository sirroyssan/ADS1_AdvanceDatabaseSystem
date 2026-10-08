<?php
/**
 * Bank Charges module (table: bank_charges) - fees and charges billed by the bank.
 * No delete: financial history with no status/void column. Corrections via Edit.
 * The table has created_at only (no updated_at).
 */

defined('APP_ROOT') or exit('No direct access.');

function bank_charge_fields(?array $current = null): array
{
    [$statementOptions, $statementData] = statement_options();

    return [
        'credit_card_id'   => ['label' => 'Credit card', 'type' => 'select', 'options' => credit_card_options($current['credit_card_id'] ?? null, false), 'required' => true, 'empty_label' => 'Select card'],
        'statement_id'     => ['label' => 'Statement', 'type' => 'select', 'options' => $statementOptions, 'option_data' => $statementData,
                               'attrs' => ['data-depends-on' => 'f_credit_card_id'], 'empty_label' => '— Not linked to a statement —'],
        'charge_date'      => ['label' => 'Charge date', 'type' => 'date', 'required' => true, 'initial' => date('Y-m-d')],
        'charge_type'      => ['label' => 'Charge type', 'type' => 'text', 'max' => 100, 'required' => true, 'help' => 'e.g. annual fee, late payment fee, finance charge.'],
        'amount'           => ['label' => 'Amount', 'type' => 'decimal', 'required' => true, 'min' => 0],
        'reference_number' => ['label' => 'Reference number', 'type' => 'text', 'max' => 150],
        'description'      => ['label' => 'Description', 'type' => 'textarea', 'rows' => 3],
    ];
}

function bank_charge_validate(array $fields, array $input, ?string $id = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);

    if ($data['statement_id'] && $data['credit_card_id'] && !belongs_to('credit_card_statements', $data['statement_id'], 'credit_card_id', $data['credit_card_id'])) {
        $errors['statement_id'] = 'The statement must belong to the selected card.';
    }

    return [$data, $errors];
}

function bank_charge_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        'SELECT b.*, c.card_nickname, c.last_four, s.statement_date
           FROM bank_charges b
           JOIN credit_cards c ON c.id = b.credit_card_id
           LEFT JOIN credit_card_statements s ON s.id = b.statement_id
          WHERE b.id = :id',
        [':id' => $id]
    );
}
