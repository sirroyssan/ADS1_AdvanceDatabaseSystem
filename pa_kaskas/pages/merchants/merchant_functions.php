<?php
/**
 * Merchants module (table: merchants).
 * merchants.category_id -> categories.id; referenced by transactions.merchant_id.
 * The table has no status column, so a merchant used by transactions
 * cannot be deactivated - it can only be kept (see README: database limitations).
 */

defined('APP_ROOT') or exit('No direct access.');

function merchant_fields(?string $currentCategory = null): array
{
    return [
        'merchant_name'     => ['label' => 'Merchant name', 'type' => 'text', 'max' => 255, 'required' => true],
        'branch'            => ['label' => 'Branch', 'type' => 'text', 'max' => 255],
        'category_id'       => ['label' => 'Category', 'type' => 'select', 'options' => category_options($currentCategory), 'empty_label' => '— No category —'],
        'merchant_type'     => ['label' => 'Merchant type', 'type' => 'text', 'max' => 30, 'help' => 'For example: physical store, online shop.'],
        'platform'          => ['label' => 'Platform', 'type' => 'text', 'max' => 100, 'help' => 'Online platform, e.g. Lazada or Shopee.'],
        'website'           => ['label' => 'Website', 'type' => 'url', 'max' => 500, 'placeholder' => 'https://'],
        'contact'           => ['label' => 'Contact', 'type' => 'text', 'max' => 255],
        'address'           => ['label' => 'Address', 'type' => 'textarea', 'rows' => 2, 'section' => 'Location'],
        'barangay'          => ['label' => 'Barangay', 'type' => 'text', 'max' => 150],
        'municipality_city' => ['label' => 'Municipality / City', 'type' => 'text', 'max' => 150],
        'province'          => ['label' => 'Province', 'type' => 'text', 'max' => 150],
        'notes'             => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 3, 'section' => 'Notes'],
    ];
}

function merchant_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        'SELECT m.*, c.name AS category_name
           FROM merchants m LEFT JOIN categories c ON c.id = m.category_id
          WHERE m.id = :id',
        [':id' => $id]
    );
}

/**
 * The signed-in user's transactions with this merchant (merchants are shared,
 * so only the user's own records are counted). 'others' counts everybody
 * else's transactions without showing them: they only block a delete.
 */
function merchant_usage(string $id): array
{
    $own = (int) db_value('SELECT COUNT(*) FROM transactions t WHERE t.merchant_id = :id AND ' . owner_scope('transactions', 't'), [':id' => $id]);
    $all = (int) db_value('SELECT COUNT(*) FROM transactions WHERE merchant_id = :id', [':id' => $id]);

    return ['transactions' => $own, 'others' => $all - $own, 'total' => $all];
}

/**
 * "3 transactions" for the user's own records; other users' records are
 * only mentioned, never counted.
 */
function merchant_usage_text(array $usage): string
{
    if ($usage['transactions'] > 0) {
        return usage_text(['transactions' => $usage['transactions']]) . ($usage['others'] > 0 ? ' and other records' : '');
    }

    return 'other records';
}

function merchant_label(array $m): string
{
    return $m['merchant_name'] . ($m['branch'] ? ' — ' . $m['branch'] : '');
}
