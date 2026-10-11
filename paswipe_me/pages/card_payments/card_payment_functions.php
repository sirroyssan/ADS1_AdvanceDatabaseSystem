<?php
/**
 * Card Payments module (table: credit_card_payments) - payments the owner
 * makes to the bank. Separate from borrower payments (schema note #4).
 *
 * No delete: this is a financial history record and the table has no
 * status/void column (see README: database limitations). Mistakes are
 * corrected with Edit, which is recorded in the activity log.
 */

defined('APP_ROOT') or exit('No direct access.');

function card_payment_fields(?array $current = null): array
{
    [$statementOptions, $statementData] = statement_options();
    [$methodOptions, $methodData] = payment_method_options(true, $current['payment_method_id'] ?? null);

    return [
        'credit_card_id'        => ['label' => 'Credit card', 'type' => 'select', 'options' => credit_card_options($current['credit_card_id'] ?? null, false), 'required' => true, 'empty_label' => 'Select card'],
        'statement_id'          => ['label' => 'Statement', 'type' => 'select', 'options' => $statementOptions, 'option_data' => $statementData,
                                    'attrs' => ['data-depends-on' => 'f_credit_card_id'], 'empty_label' => '— Not linked to a statement —'],
        'payment_date'          => ['label' => 'Payment date', 'type' => 'date', 'required' => true, 'initial' => date('Y-m-d')],
        'amount'                => ['label' => 'Amount', 'type' => 'decimal', 'required' => true, 'min_exclusive' => 0],
        'payment_type'          => ['label' => 'Payment type', 'type' => 'text', 'max' => 100, 'help' => 'e.g. full balance, minimum, partial.'],
        'payment_method_id'     => ['label' => 'Paid from (owner account)', 'type' => 'select', 'options' => $methodOptions, 'empty_label' => '— Not specified —'],
        'payment_channel'       => ['label' => 'Channel', 'type' => 'text', 'max' => 100, 'help' => 'e.g. online banking, over the counter, GCash bills pay.'],
        'source_account_masked' => ['label' => 'Source account (masked)', 'type' => 'text', 'max' => 100],
        'reference_number'      => ['label' => 'Reference number', 'type' => 'text', 'max' => 150],
        'receipt_document_id'   => ['label' => 'Receipt file', 'type' => 'select', 'options' => document_options($current['receipt_document_id'] ?? null), 'empty_label' => '— No file —'],
        'notes'                 => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 3],
    ];
}

function card_payment_validate(array $fields, array $input, ?string $id = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);

    if ($data['statement_id'] && $data['credit_card_id'] && !belongs_to('credit_card_statements', $data['statement_id'], 'credit_card_id', $data['credit_card_id'])) {
        $errors['statement_id'] = 'The statement must belong to the selected card.';
    }

    return [$data, $errors];
}

function card_payment_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        'SELECT p.*, c.card_nickname, c.last_four, s.statement_date, pm.display_name AS method_name, d.original_filename
           FROM credit_card_payments p
           JOIN credit_cards c ON c.id = p.credit_card_id
           LEFT JOIN credit_card_statements s ON s.id = p.statement_id
           LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
           LEFT JOIN documents d ON d.id = p.receipt_document_id
          WHERE p.id = :id AND ' . owner_scope('credit_card_payments', 'p'),
        [':id' => $id]
    );
}
