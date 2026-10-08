<?php
/**
 * Demand Letters module (table: demand_letters).
 *
 * Workflow: draft -> approved -> sent (sent_at) -> acknowledged (acknowledged_at)
 *           -> resolved; any open letter can be cancelled.
 * Content is editable while draft or approved. Only drafts can be deleted.
 */

defined('APP_ROOT') or exit('No direct access.');

const LETTER_EDITABLE_STATUSES = ['draft', 'approved'];
const LETTER_TRANSITIONS = [
    'draft'        => ['approved', 'cancelled'],
    'approved'     => ['sent', 'draft', 'cancelled'],
    'sent'         => ['acknowledged', 'resolved', 'cancelled'],
    'acknowledged' => ['resolved', 'cancelled'],
];

function letter_fields(?array $current = null): array
{
    [$oblOptions, $oblData] = obligation_options($current['obligation_id'] ?? null);
    $agreements = db_all("SELECT id, borrower_id, agreement_number FROM agreements WHERE status <> 'cancelled' OR id = :keep ORDER BY agreement_date DESC", [':keep' => $current['agreement_id'] ?? '']);

    return [
        'letter_number'      => ['label' => 'Letter number', 'type' => 'text', 'max' => 50, 'required' => $current !== null,
                                 'unique' => ['demand_letters', 'letter_number', 'This letter number is already used.'],
                                 'help' => $current === null ? 'Leave blank to generate one automatically.' : ''],
        'borrower_id'        => ['label' => 'Borrower', 'type' => 'select', 'options' => borrower_options($current['borrower_id'] ?? null, false), 'required' => true, 'empty_label' => 'Select borrower'],
        'obligation_id'      => ['label' => 'Obligation', 'type' => 'select', 'options' => $oblOptions, 'option_data' => $oblData,
                                 'attrs' => ['data-depends-on' => 'f_borrower_id'], 'empty_label' => '— None —'],
        'agreement_id'       => ['label' => 'Agreement', 'type' => 'select', 'options' => array_column($agreements, 'agreement_number', 'id'),
                                 'option_data' => array_map(static fn ($a) => ['parent' => $a['borrower_id']], array_column($agreements, null, 'id')),
                                 'attrs' => ['data-depends-on' => 'f_borrower_id'], 'empty_label' => '— None —'],
        'template_id'        => ['label' => 'Template', 'type' => 'select', 'options' => letter_template_options($current['template_id'] ?? null), 'empty_label' => '— None —'],
        'generated_date'     => ['label' => 'Letter date', 'type' => 'date', 'required' => true, 'initial' => date('Y-m-d')],
        'original_amount'    => ['label' => 'Original amount', 'type' => 'decimal', 'min' => 0, 'section' => 'Balance stated in the letter'],
        'total_paid'         => ['label' => 'Total paid', 'type' => 'decimal', 'min' => 0],
        'remaining_balance'  => ['label' => 'Remaining balance', 'type' => 'decimal', 'min' => 0],
        'documented_charges' => ['label' => 'Documented charges', 'type' => 'decimal', 'min' => 0, 'default' => '0.00'],
        'content'            => ['label' => 'Letter content', 'type' => 'textarea', 'rows' => 16, 'required' => true, 'section' => 'Letter'],
        'document_id'        => ['label' => 'Signed/sent copy (file)', 'type' => 'select', 'options' => document_options($current['document_id'] ?? null), 'empty_label' => '— None —'],
    ];
}

function letter_validate(array $fields, array $input, ?string $id = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);

    foreach (['obligation_id' => 'borrower_obligations', 'agreement_id' => 'agreements'] as $field => $table) {
        if ($data[$field] && $data['borrower_id'] && !belongs_to($table, $data[$field], 'borrower_id', $data['borrower_id'])) {
            $errors[$field] = 'This record must belong to the selected borrower.';
        }
    }

    return [$data, $errors];
}

function letter_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        "SELECT l.*, b.first_name, b.last_name, b.borrower_number, o.obligation_number, a.agreement_number,
                t.name AS template_name, d.original_filename,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email) AS created_by_name
           FROM demand_letters l
           JOIN borrowers b ON b.id = l.borrower_id
           LEFT JOIN borrower_obligations o ON o.id = l.obligation_id
           LEFT JOIN agreements a ON a.id = l.agreement_id
           LEFT JOIN demand_letter_templates t ON t.id = l.template_id
           LEFT JOIN documents d ON d.id = l.document_id
           LEFT JOIN users u ON u.id = l.created_by
          WHERE l.id = :id",
        [':id' => $id]
    );
}

/**
 * Prefill a new letter from an obligation's balance and a template.
 */
function letter_prefill(?string $obligationId, ?string $templateId, ?string $borrowerId): array
{
    $data = [];
    $values = ['date' => format_date(date('Y-m-d')), 'letter_number' => '(assigned on save)'];

    if ($obligationId && is_valid_uuid($obligationId)) {
        $o = db_row(
            'SELECT o.*, b.first_name, b.last_name, b.borrower_number,
                    (SELECT COALESCE(SUM(remaining_amount), 0) FROM applied_penalties p WHERE p.obligation_id = o.id) AS penalties
               FROM borrower_obligations o JOIN borrowers b ON b.id = o.borrower_id WHERE o.id = :id',
            [':id' => $obligationId]
        );
        if ($o) {
            $data = [
                'borrower_id' => $o['borrower_id'], 'obligation_id' => $o['id'], 'original_amount' => $o['total_amount_due'],
                'total_paid' => $o['total_paid'], 'remaining_balance' => $o['outstanding_amount'], 'documented_charges' => $o['penalties'],
            ];
            $values += [
                'borrower_name' => $o['first_name'] . ' ' . $o['last_name'], 'borrower_number' => $o['borrower_number'],
                'obligation_number' => $o['obligation_number'], 'original_amount' => format_money($o['total_amount_due']),
                'total_paid' => format_money($o['total_paid']), 'remaining_balance' => format_money($o['outstanding_amount']),
                'documented_charges' => format_money($o['penalties']),
            ];
        }
    } elseif ($borrowerId && is_valid_uuid($borrowerId)) {
        $b = db_row('SELECT first_name, last_name, borrower_number FROM borrowers WHERE id = :id', [':id' => $borrowerId]);
        if ($b) {
            $data['borrower_id'] = $borrowerId;
            $values += ['borrower_name' => $b['first_name'] . ' ' . $b['last_name'], 'borrower_number' => $b['borrower_number']];
        }
    }

    if ($templateId && is_valid_uuid($templateId)) {
        $body = db_value('SELECT template_body FROM demand_letter_templates WHERE id = :id AND is_active = 1', [':id' => $templateId]);
        if ($body !== false) {
            $owner = db_value("SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email) FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'owner' LIMIT 1");
            $data['template_id'] = $templateId;
            $data['content'] = fill_template($body, $values + ['owner_name' => $owner ?: '']);
        }
    }

    return $data;
}
