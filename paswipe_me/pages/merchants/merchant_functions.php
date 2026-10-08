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

function merchant_usage(string $id): array
{
    return record_usage(['transactions' => ['transactions', 'merchant_id']], $id);
}

function merchant_label(array $m): string
{
    return $m['merchant_name'] . ($m['branch'] ? ' — ' . $m['branch'] : '');
}
