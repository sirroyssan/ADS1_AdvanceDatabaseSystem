<?php
/**
 * Agreement Templates module (table: agreement_templates).
 * Referenced by agreements.template_id; soft delete via is_active.
 */

defined('APP_ROOT') or exit('No direct access.');

const TEMPLATE_PLACEHOLDERS = '{{borrower_name}}, {{borrower_number}}, {{agreement_number}}, {{date}}, {{original_amount}}, {{installment_amount}}, {{installment_count}}, {{agreed_charges}}, {{payment_terms}}, {{owner_name}}';

function template_fields(): array
{
    return [
        'name'          => ['label' => 'Template name', 'type' => 'text', 'max' => 255, 'required' => true],
        'version'       => ['label' => 'Version', 'type' => 'text', 'max' => 50, 'required' => true, 'initial' => '1.0'],
        'description'   => ['label' => 'Description', 'type' => 'textarea', 'rows' => 2],
        'template_body' => ['label' => 'Template body', 'type' => 'textarea', 'rows' => 14, 'required' => true,
                            'help' => 'Placeholders filled in when an agreement is generated: ' . TEMPLATE_PLACEHOLDERS . '.'],
        'is_active'     => ['label' => 'Status', 'type' => 'bool', 'switch_label' => 'Active (available for new agreements)', 'default' => 1],
    ];
}

function template_find(string $id): ?array
{
    return is_valid_uuid($id) ? db_row('SELECT * FROM agreement_templates t WHERE id = :id AND ' . owner_scope('agreement_templates', 't'), [':id' => $id]) : null;
}

function template_usage(string $id): array
{
    return record_usage(['agreements' => ['agreements', 'template_id']], $id);
}

/**
 * Same name + version may not be saved twice by the same owner (application rule; the schema has no UNIQUE here).
 */
function template_validate(array $fields, array $input, ?string $id = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);

    if (!$errors && (int) db_value(
        'SELECT COUNT(*) FROM agreement_templates t WHERE name = :n AND version = :v AND id <> :id AND ' . owner_scope('agreement_templates', 't'),
        [':n' => $data['name'], ':v' => $data['version'], ':id' => $id ?? '']
    ) > 0) {
        $errors['version'] = 'This template name already has a version ' . $data['version'] . '.';
    }

    return [$data, $errors];
}
