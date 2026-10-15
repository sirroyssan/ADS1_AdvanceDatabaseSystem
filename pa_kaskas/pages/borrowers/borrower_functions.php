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
        'receipts'              => ['payment_receipts', 'borrower_id'],
    ], $id);
}

/**
 * SQL condition: another of the owner's borrowers looks like the same person -
 * same first and last name, the same mobile (last 10 digits) or the same email.
 * $alias is the borrowers alias of the outer query.
 */
function borrower_duplicate_sql(string $alias = 'b'): string
{
    $a = preg_replace('/[^a-z0-9_]/i', '', $alias);

    return "EXISTS (SELECT 1 FROM borrowers dup WHERE dup.id <> $a.id AND " . owner_scope('borrowers', 'dup') . " AND (
                (LOWER(TRIM(dup.first_name)) = LOWER(TRIM($a.first_name)) AND LOWER(TRIM(dup.last_name)) = LOWER(TRIM($a.last_name)))
             OR (LENGTH(REGEXP_REPLACE(COALESCE($a.primary_mobile, ''), '[^0-9]', '')) >= 7
                 AND RIGHT(REGEXP_REPLACE(COALESCE(dup.primary_mobile, ''), '[^0-9]', ''), 10) = RIGHT(REGEXP_REPLACE($a.primary_mobile, '[^0-9]', ''), 10))
             OR (TRIM(COALESCE($a.primary_email, '')) <> '' AND LOWER(TRIM(dup.primary_email)) = LOWER(TRIM($a.primary_email)))))";
}

/**
 * The owner's borrowers that look like the same person as $data (a form or a
 * borrowers row), excluding $exceptId. Each row says what matched.
 */
function borrower_duplicates(array $data, ?string $exceptId = null): array
{
    $first = mb_strtolower(trim((string) ($data['first_name'] ?? '')));
    $last = mb_strtolower(trim((string) ($data['last_name'] ?? '')));
    $mobile = substr(preg_replace('/\D/', '', (string) ($data['primary_mobile'] ?? '')), -10);
    $email = mb_strtolower(trim((string) ($data['primary_email'] ?? '')));
    if (strlen($mobile) < 7) {
        $mobile = '';
    }

    $rows = db_all(
        "SELECT b.id, b.borrower_number, b.first_name, b.middle_name, b.last_name, b.suffix, b.primary_mobile, b.primary_email, b.status,
                (LOWER(TRIM(b.first_name)) = :f1 AND LOWER(TRIM(b.last_name)) = :l1) AS same_name,
                (:m1 <> '' AND RIGHT(REGEXP_REPLACE(COALESCE(b.primary_mobile, ''), '[^0-9]', ''), 10) = :m2) AS same_mobile,
                (:e1 <> '' AND LOWER(TRIM(b.primary_email)) = :e2) AS same_email
           FROM borrowers b
          WHERE b.id <> :x AND " . owner_scope('borrowers', 'b') . "
            AND ((LOWER(TRIM(b.first_name)) = :f2 AND LOWER(TRIM(b.last_name)) = :l2)
              OR (:m3 <> '' AND RIGHT(REGEXP_REPLACE(COALESCE(b.primary_mobile, ''), '[^0-9]', ''), 10) = :m4)
              OR (:e3 <> '' AND LOWER(TRIM(b.primary_email)) = :e4))
          ORDER BY b.created_at",
        [':f1' => $first, ':l1' => $last, ':f2' => $first, ':l2' => $last, ':m1' => $mobile, ':m2' => $mobile, ':m3' => $mobile, ':m4' => $mobile,
         ':e1' => $email, ':e2' => $email, ':e3' => $email, ':e4' => $email, ':x' => (string) $exceptId]
    );
    foreach ($rows as &$r) {
        $r['matched'] = implode(', ', array_keys(array_filter(['same name' => $r['same_name'], 'same mobile' => $r['same_mobile'], 'same email' => $r['same_email']])));
    }

    return $rows;
}

/** "BRW-… Alfred Agasa (same name)" for messages. */
function borrower_duplicate_text(array $rows): string
{
    return implode('; ', array_map(fn ($r) => $r['borrower_number'] . ' ' . borrower_full_name($r) . ' (' . $r['matched'] . ')', $rows));
}

/** Tables whose borrower_id moves to the kept borrower when two records are merged. */
const BORROWER_MERGE_TABLES = [
    'transactions', 'borrower_obligations', 'borrower_payments', 'payment_methods', 'agreements', 'agreement_signatories',
    'demand_letters', 'cash_loan_allocations', 'payment_receipts', 'borrower_addresses', 'borrower_contacts',
    'borrower_user_accounts', 'backup_borrowed_borrower_obligations', 'backup_borrowed_borrower_payments',
];

/**
 * Merge a duplicate borrower into the record that is kept: every record of
 * $from (purchases, obligations, payments, agreements, receipts, addresses,
 * contacts, portal account...) is moved to $into - nothing financial is
 * erased - and the now empty duplicate is deleted. The activity log keeps the
 * deleted record, the reason and how many records moved.
 */
function borrower_merge(array $from, array $into, string $reason, array $user): array
{
    if ($from['id'] === $into['id']) {
        throw new DomainException('Choose a different borrower to keep.');
    }
    if (!owns('borrowers', $from['id']) || !owns('borrowers', $into['id'])) {
        throw new DomainException('Both borrowers must be yours.');
    }

    $moved = db_transaction(function () use ($from, $into) {
        db_all('SELECT id FROM borrowers WHERE id IN (:a, :b) FOR UPDATE', [':a' => $from['id'], ':b' => $into['id']]);
        if ((int) db_value('SELECT COUNT(*) FROM borrower_user_accounts WHERE borrower_id = :a', [':a' => $from['id']]) > 0
            && (int) db_value('SELECT COUNT(*) FROM borrower_user_accounts WHERE borrower_id = :b', [':b' => $into['id']]) > 0) {
            throw new DomainException('Both borrowers have a portal sign-in account. Unlink the account of ' . $from['borrower_number'] . ' on its page first, then merge.');
        }

        $existing = array_flip(array_column(db_all(
            "SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'borrower_id'"
        ), 'TABLE_NAME'));
        // The kept borrower keeps its primary address; the moved ones become extra addresses.
        if ((int) db_value('SELECT COUNT(*) FROM borrower_addresses WHERE borrower_id = :b AND is_primary = 1', [':b' => $into['id']]) > 0) {
            db_exec('UPDATE borrower_addresses SET is_primary = 0 WHERE borrower_id = :a', [':a' => $from['id']]);
        }
        $moved = [];
        foreach (BORROWER_MERGE_TABLES as $table) {
            if (!isset($existing[$table])) {
                continue;
            }
            $count = db_exec('UPDATE ' . db_identifier($table) . ' SET borrower_id = :into WHERE borrower_id = :from', [':into' => $into['id'], ':from' => $from['id']]);
            if ($count > 0) {
                $moved[$table] = $count;
            }
        }
        // Keep contact details the kept record is missing.
        $fill = [];
        foreach (['middle_name', 'suffix', 'nickname', 'relationship', 'primary_mobile', 'primary_email'] as $col) {
            if (trim((string) ($into[$col] ?? '')) === '' && trim((string) ($from[$col] ?? '')) !== '') {
                $fill[$col] = $from[$col];
            }
        }
        if ($fill) {
            db_update('borrowers', $fill, $into['id']);
        }
        db_exec('DELETE FROM borrowers WHERE id = :id', [':id' => $from['id']]);

        return $moved;
    });

    $snapshot = array_diff_key($from, array_flip(['created_by_name', 'updated_by_name']));
    log_activity('merge', 'borrower', $into['id'], 'Merged duplicate borrower ' . $from['borrower_number'] . ' ' . borrower_full_name($from)
        . ' into ' . $into['borrower_number'] . ': ' . $reason, $snapshot, ['kept' => $into['borrower_number'], 'reason' => $reason, 'moved' => $moved]);
    log_activity('delete', 'borrower', $from['id'], 'Deleted duplicate borrower ' . $from['borrower_number'] . ' after merging it into ' . $into['borrower_number'], $snapshot);

    return $moved;
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
 * a Borrower-role account not linked yet, or a registered user (owner /
 * administrator account, not yourself) not linked to another of your
 * borrowers - registered users can borrow from several card owners.
 * Accounts are looked up by email only - never listed - so no one sees other
 * users' borrowers or their emails.
 */
function linkable_borrower_user(string $email): ?string
{
    $id = db_value(
        "SELECT u.id FROM users u
          WHERE LOWER(u.email) = :e AND u.status = 'active' AND u.id <> :me
            AND (
                (EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role = 'borrower')
                 AND NOT EXISTS (SELECT 1 FROM user_roles x WHERE x.user_id = u.id AND x.role IN ('owner', 'admin'))
                 AND NOT EXISTS (SELECT 1 FROM borrower_user_accounts a WHERE a.user_id = u.id))
             OR (u.id IN " . borrowed_registered_users_sql() . "
                 AND NOT EXISTS (SELECT 1 FROM borrower_user_accounts a WHERE a.user_id = u.id AND a.borrower_id IN " . owned_borrowers_sql() . "))
            )",
        [':e' => mb_strtolower(trim($email)), ':me' => owner_id()]
    );

    return $id ? (string) $id : null;
}
