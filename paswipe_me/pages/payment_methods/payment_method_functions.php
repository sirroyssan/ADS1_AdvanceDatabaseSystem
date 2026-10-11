<?php
/**
 * Payment Methods module (table: payment_methods).
 * A method belongs to the owner (owner_user_id) or to a borrower (borrower_id).
 * Referenced by borrower_payments and credit_card_payments; soft delete via is_active.
 */

defined('APP_ROOT') or exit('No direct access.');

function payment_method_fields(?array $current = null): array
{
    return [
        'method_type'           => ['label' => 'Method type', 'type' => 'select', 'options' => enum_options(ENUM_PAYMENT_METHOD), 'required' => true, 'empty_label' => 'Select type'],
        'display_name'          => ['label' => 'Display name', 'type' => 'text', 'max' => 150, 'required' => true, 'help' => 'e.g. "Roy GCash" or "BDO Savings".'],
        'account_name'          => ['label' => 'Account name', 'type' => 'text', 'max' => 255],
        'masked_account_number' => ['label' => 'Masked account number', 'type' => 'text', 'max' => 100, 'help' => 'Never store a full account number, e.g. 0917•••4567.'],
        'last_four'             => ['label' => 'Last four digits', 'type' => 'text', 'max' => 4, 'pattern' => '/^[0-9]{4}$/', 'pattern_message' => 'Enter exactly 4 digits.'],
        'owner_user_id'         => ['label' => 'Owner account', 'type' => 'select', 'options' => payment_method_owner_options(), 'empty_label' => '— Not an owner account —', 'section' => 'Belongs to', 'help' => 'Choose either an owner account or a borrower.'],
        'borrower_id'           => ['label' => 'Borrower', 'type' => 'select', 'options' => borrower_options($current['borrower_id'] ?? null), 'empty_label' => '— Not a borrower account —'],
        'is_active'             => ['label' => 'Status', 'type' => 'bool', 'switch_label' => 'Active (can be used for new payments)', 'default' => 1, 'section' => 'Status'],
        'notes'                 => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 3],
    ];
}

/**
 * The only owner account a method can belong to: the signed-in user's own.
 */
function payment_method_owner_options(): array
{
    $user = current_user();

    return [owner_id() => $user ? user_display_name($user) : 'You'];
}

/**
 * Extra rule: a method cannot belong to an owner and a borrower at once.
 * A method without a borrower is always the signed-in user's own.
 */
function payment_method_validate(array $fields, array $input, ?string $id = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);

    if ($data['owner_user_id'] !== null && $data['borrower_id'] !== null) {
        $errors['borrower_id'] = 'Choose either an owner account or a borrower, not both.';
    }
    if (!isset($errors['borrower_id']) && !isset($errors['owner_user_id'])) {
        $data['owner_user_id'] = $data['borrower_id'] === null ? owner_id() : null;
    }

    return [$data, $errors];
}

function payment_method_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        "SELECT pm.*, b.first_name, b.last_name, b.borrower_number,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, u.email) AS owner_name
           FROM payment_methods pm
           LEFT JOIN borrowers b ON b.id = pm.borrower_id
           LEFT JOIN users u ON u.id = pm.owner_user_id
          WHERE pm.id = :id AND " . owner_scope('payment_methods', 'pm'),
        [':id' => $id]
    );
}

/**
 * Payments that use a method. $all counts every user's (the delete check, so
 * nothing is left pointing at a deleted method); otherwise only the user's own.
 */
function payment_method_usage(string $id, bool $all = false): array
{
    if (!$all) {
        $usage = [
            'borrower payments' => (int) db_value('SELECT COUNT(*) FROM borrower_payments bp WHERE bp.payment_method_id = :id AND ' . owner_scope('borrower_payments', 'bp'), [':id' => $id]),
            'card payments'     => (int) db_value('SELECT COUNT(*) FROM credit_card_payments cp WHERE cp.payment_method_id = :id AND ' . owner_scope('credit_card_payments', 'cp'), [':id' => $id]),
        ];

        return $usage + ['total' => array_sum($usage)];
    }

    return record_usage([
        'borrower payments' => ['borrower_payments', 'payment_method_id'],
        'card payments'     => ['credit_card_payments', 'payment_method_id'],
    ], $id);
}
