<?php
/**
 * Agreements module (tables: agreements, agreement_signatories, agreement_documents).
 *
 * The agreements table stores the terms, not the text: the printable text is
 * produced from the selected template with {{placeholders}} filled in.
 * Generated/signed copies are uploaded files (documents) linked to the agreement.
 * Delete only drafts; otherwise cancel.
 */

defined('APP_ROOT') or exit('No direct access.');

const AGREEMENT_LOCKED_STATUSES = ['completed', 'cancelled'];

function agreement_fields(?array $current = null): array
{
    [$txnOptions, $txnData] = transaction_options($current['transaction_id'] ?? null);
    [$oblOptions, $oblData] = obligation_options($current['obligation_id'] ?? null);

    return [
        'agreement_number'      => ['label' => 'Agreement number', 'type' => 'text', 'max' => 50, 'required' => $current !== null,
                                    'unique' => ['agreements', 'agreement_number', 'This agreement number is already used.'],
                                    'help' => $current === null ? 'Leave blank to generate one automatically.' : ''],
        'borrower_id'           => ['label' => 'Borrower', 'type' => 'select', 'options' => borrower_options($current['borrower_id'] ?? null), 'required' => true, 'empty_label' => 'Select borrower'],
        'template_id'           => ['label' => 'Template', 'type' => 'select', 'options' => agreement_template_options($current['template_id'] ?? null), 'empty_label' => '— No template —'],
        'agreement_date'        => ['label' => 'Agreement date', 'type' => 'date', 'required' => true, 'initial' => date('Y-m-d')],
        'status'                => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_AGREEMENT_STATUS), 'required' => true, 'initial' => 'draft'],
        'transaction_id'        => ['label' => 'Transaction', 'type' => 'select', 'options' => $txnOptions, 'option_data' => $txnData,
                                    'attrs' => ['data-depends-on' => 'f_borrower_id'], 'empty_label' => '— None —', 'section' => 'Covers'],
        'obligation_id'         => ['label' => 'Obligation', 'type' => 'select', 'options' => $oblOptions, 'option_data' => $oblData,
                                    'attrs' => ['data-depends-on' => 'f_borrower_id'], 'empty_label' => '— None —'],
        'original_amount'       => ['label' => 'Original amount', 'type' => 'decimal', 'min' => 0, 'section' => 'Terms'],
        'installment_amount'    => ['label' => 'Installment amount', 'type' => 'decimal', 'min' => 0],
        'installment_count'     => ['label' => 'Number of installments', 'type' => 'int', 'min' => 1, 'max' => 360],
        'agreed_charges'        => ['label' => 'Agreed charges', 'type' => 'decimal', 'min' => 0, 'default' => '0.00'],
        'payment_terms'         => ['label' => 'Payment terms', 'type' => 'textarea', 'rows' => 4],
        'generated_document_id' => ['label' => 'Generated copy (file)', 'type' => 'select', 'options' => document_options($current['generated_document_id'] ?? null), 'empty_label' => '— None —', 'section' => 'Files'],
        'signed_document_id'    => ['label' => 'Signed copy (file)', 'type' => 'select', 'options' => document_options($current['signed_document_id'] ?? null), 'empty_label' => '— None —'],
    ];
}

function agreement_validate(array $fields, array $input, ?string $id = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);

    foreach (['transaction_id' => 'transactions', 'obligation_id' => 'borrower_obligations'] as $field => $table) {
        if ($data[$field] && $data['borrower_id'] && !belongs_to($table, $data[$field], 'borrower_id', $data['borrower_id'])) {
            $errors[$field] = 'This record must belong to the selected borrower.';
        }
    }

    return [$data, $errors];
}

function agreement_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        "SELECT a.*, b.first_name, b.middle_name, b.last_name, b.suffix, b.borrower_number,
                t.name AS template_name, t.version AS template_version, t.template_body,
                tx.transaction_number, o.obligation_number,
                gd.original_filename AS generated_filename, sd.original_filename AS signed_filename,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email) AS created_by_name
           FROM agreements a
           JOIN borrowers b ON b.id = a.borrower_id
           LEFT JOIN agreement_templates t ON t.id = a.template_id
           LEFT JOIN transactions tx ON tx.id = a.transaction_id
           LEFT JOIN borrower_obligations o ON o.id = a.obligation_id
           LEFT JOIN documents gd ON gd.id = a.generated_document_id
           LEFT JOIN documents sd ON sd.id = a.signed_document_id
           LEFT JOIN users u ON u.id = a.created_by
          WHERE a.id = :id AND " . owner_scope('agreements', 'a'),
        [':id' => $id]
    );
}

/**
 * Agreement text from its template with the placeholders filled in.
 */
function agreement_text(array $a): ?string
{
    if (!$a['template_body']) {
        return null;
    }

    // The lender is the owner of the agreement's borrower.
    $owner = db_value(
        "SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email)
           FROM users u JOIN borrowers b ON b.owner_user_id = u.id WHERE b.id = :b",
        [':b' => $a['borrower_id']]
    );

    return fill_template($a['template_body'], [
        'borrower_name'      => trim($a['first_name'] . ' ' . ($a['middle_name'] ? $a['middle_name'] . ' ' : '') . $a['last_name'] . ($a['suffix'] ? ' ' . $a['suffix'] : '')),
        'borrower_number'    => $a['borrower_number'],
        'agreement_number'   => $a['agreement_number'],
        'date'               => format_date($a['agreement_date']),
        'original_amount'    => format_money($a['original_amount']),
        'installment_amount' => format_money($a['installment_amount']),
        'installment_count'  => (string) $a['installment_count'],
        'agreed_charges'     => format_money($a['agreed_charges']),
        'payment_terms'      => (string) $a['payment_terms'],
        'owner_name'         => $owner ?: '',
    ]);
}

/**
 * Users who can sign as "a system user": the signed-in user only (never a list
 * of every account).
 */
function signatory_user_options(): array
{
    return db_options(
        "SELECT id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''), username, email) FROM users WHERE id = :id",
        [':id' => owner_id()]
    );
}

function signatory_fields(): array
{
    return [
        'signer'   => ['label' => 'Signer', 'type' => 'select', 'options' => ['borrower' => 'The borrower', 'user' => 'A system user', 'other' => 'Someone else (witness, co-maker)'], 'required' => true, 'initial' => 'borrower'],
        'user_id'  => ['label' => 'User (if a system user)', 'type' => 'select', 'options' => signatory_user_options(), 'empty_label' => '— Not a user —'],
        'name'     => ['label' => 'Name', 'type' => 'text', 'max' => 255, 'help' => 'Filled in automatically for the borrower or a user when left blank.'],
        'role'     => ['label' => 'Role', 'type' => 'text', 'max' => 100, 'required' => true, 'placeholder' => 'e.g. borrower, lender, witness'],
        'email'    => ['label' => 'Email', 'type' => 'email', 'max' => 255],
        'mobile'   => ['label' => 'Mobile', 'type' => 'tel', 'max' => 30],
    ];
}
