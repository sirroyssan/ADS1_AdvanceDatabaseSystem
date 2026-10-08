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
    if ($data['statement_id'] && $data['credit_card_id'] && !belongs_to('credit_card_statements', $data['statement_id'], 'credit_card_id', $data['credit_card_id'])) {
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
