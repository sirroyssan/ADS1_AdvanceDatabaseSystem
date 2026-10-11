<?php
/**
 * Borrowers module (table: borrowers) with its child tables:
 *   borrower_addresses, borrower_contacts  (cascade on delete, managed on the view page)
 *   borrower_user_accounts                 (1:1 link to a users row with the borrower role)
 * Soft delete: status = 'inactive'. Hard delete only when nothing financial refers to it.
 */

defined('APP_ROOT') or exit('No direct access.');

function borrower_fields(?array $current = null): array
{
    return [
        'borrower_number' => ['label' => 'Borrower number', 'type' => 'text', 'max' => 50,
                              'unique' => ['borrowers', 'borrower_number', 'This borrower number is already used.'],
                              'help' => 'Leave blank to generate one automatically.'],
        'status'          => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_BORROWER_STATUS), 'required' => true, 'initial' => 'good_standing'],
        'first_name'      => ['label' => 'First name', 'type' => 'text', 'max' => 100, 'required' => true, 'section' => 'Name'],
        'middle_name'     => ['label' => 'Middle name', 'type' => 'text', 'max' => 100],
        'last_name'       => ['label' => 'Last name', 'type' => 'text', 'max' => 100, 'required' => true],
        'suffix'          => ['label' => 'Suffix', 'type' => 'text', 'max' => 30, 'placeholder' => 'Jr., Sr., III'],
        'nickname'        => ['label' => 'Nickname', 'type' => 'text', 'max' => 100],
        'relationship'    => ['label' => 'Relationship to owner', 'type' => 'select', 'options' => relationship_options($current['relationship'] ?? null),
                              'empty_label' => '— Not set (treated as not related) —',
                              'help' => 'Family members may submit payments in the portal without proof; everyone else must attach proof of payment.'],
        'primary_mobile'  => ['label' => 'Primary mobile', 'type' => 'tel', 'max' => 30, 'pattern' => '/^[0-9+()\-\s]{7,30}$/', 'pattern_message' => 'Enter a valid mobile number.', 'section' => 'Contact'],
        'primary_email'   => ['label' => 'Primary email', 'type' => 'email', 'max' => 255],
        'notes'           => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 3],
    ];
}

function borrower_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        "SELECT b.*,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', cu.first_name, cu.last_name)), ''), cu.email) AS created_by_name,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', uu.first_name, uu.last_name)), ''), uu.email) AS updated_by_name
           FROM borrowers b
           LEFT JOIN users cu ON cu.id = b.created_by
           LEFT JOIN users uu ON uu.id = b.updated_by
          WHERE b.id = :id AND " . owner_scope('borrowers', 'b'),
        [':id' => $id]
    );
}

function borrower_full_name(array $b): string
{
    return trim($b['first_name'] . ' ' . ($b['middle_name'] ? $b['middle_name'] . ' ' : '') . $b['last_name'] . ($b['suffix'] ? ' ' . $b['suffix'] : ''));
}

/**
 * Financial and legal records that must survive (addresses/contacts cascade).
 */
function borrower_usage(string $id): array
{
    return record_usage([
        'transactions'          => ['transactions', 'borrower_id'],
        'obligations'           => ['borrower_obligations', 'borrower_id'],
        'payments'              => ['borrower_payments', 'borrower_id'],
        'payment methods'       => ['payment_methods', 'borrower_id'],
        'agreements'            => ['agreements', 'borrower_id'],
        'agreement signatures'  => ['agreement_signatories', 'borrower_id'],
        'demand letters'        => ['demand_letters', 'borrower_id'],
        'cash loan allocations' => ['cash_loan_allocations', 'borrower_id'],
        'user accounts'         => ['borrower_user_accounts', 'borrower_id'],
    ], $id);
}

function address_fields(): array
{
    return [
        'address_type'      => ['label' => 'Type', 'type' => 'text', 'max' => 30, 'required' => true, 'initial' => 'primary', 'help' => 'e.g. primary, work, provincial.'],
        'house_unit'        => ['label' => 'House / Unit', 'type' => 'text', 'max' => 150],
        'street'            => ['label' => 'Street', 'type' => 'text', 'max' => 255],
        'barangay'          => ['label' => 'Barangay', 'type' => 'text', 'max' => 150],
        'municipality_city' => ['label' => 'Municipality / City', 'type' => 'text', 'max' => 150],
        'province'          => ['label' => 'Province', 'type' => 'text', 'max' => 150],
        'postal_code'       => ['label' => 'Postal code', 'type' => 'text', 'max' => 20],
        'is_primary'        => ['label' => 'Primary', 'type' => 'bool', 'switch_label' => 'Primary address'],
    ];
}

function contact_fields(): array
{
    return [
        'contact_type' => ['label' => 'Contact type', 'type' => 'text', 'max' => 50, 'required' => true, 'help' => 'e.g. emergency, reference, employer.'],
        'contact_name' => ['label' => 'Name', 'type' => 'text', 'max' => 255],
        'relationship' => ['label' => 'Relationship', 'type' => 'text', 'max' => 100],
        'mobile'       => ['label' => 'Mobile', 'type' => 'tel', 'max' => 30, 'pattern' => '/^[0-9+()\-\s]{7,30}$/', 'pattern_message' => 'Enter a valid mobile number.'],
        'email'        => ['label' => 'Email', 'type' => 'email', 'max' => 255],
        'notes'        => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 2],
    ];
}

function format_address(array $a): string
{
    return implode(', ', array_filter([$a['house_unit'], $a['street'], $a['barangay'], $a['municipality_city'], $a['province'], $a['postal_code']]));
}

/**
 * The sign-in account with this exact email that may be linked to a borrower:
 * Borrower role, not linked yet. Accounts are looked up by email only - never
 * listed - so no one sees other users' borrowers or their emails.
 */
function linkable_borrower_user(string $email): ?string
{
    $id = db_value(
        "SELECT u.id FROM users u
           JOIN user_roles r ON r.user_id = u.id AND r.role = 'borrower'
          WHERE LOWER(u.email) = :e
            AND NOT EXISTS (SELECT 1 FROM borrower_user_accounts a WHERE a.user_id = u.id)
            AND NOT EXISTS (SELECT 1 FROM user_roles x WHERE x.user_id = u.id AND x.role IN ('owner', 'admin'))",
        [':e' => mb_strtolower(trim($email))]
    );

    return $id ? (string) $id : null;
}
