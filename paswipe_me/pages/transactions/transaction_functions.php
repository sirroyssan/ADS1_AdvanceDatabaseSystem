<?php
/**
 * Transactions module (tables: transactions, transaction_items, transaction_attachments).
 *
 * Rules:
 *  - transaction_number is UNIQUE; generated as TXN-YYYYMMDD-#### when blank.
 *  - Cancelled/refunded transactions are read-only.
 *  - Items can be changed while the transaction is not completed/cancelled/refunded.
 *  - Delete is only for DRAFT transactions with no items, installment plan,
 *    obligations or agreements. Otherwise use status = cancelled.
 */

defined('APP_ROOT') or exit('No direct access.');

const TXN_LOCKED_STATUSES = ['cancelled', 'refunded'];
const TXN_ITEM_LOCKED_STATUSES = ['completed', 'cancelled', 'refunded'];

function transaction_fields(?array $current = null): array
{
    // Borrowers with a sign-in account see the transaction in their own account right after it is saved.
    $borrowers = borrower_options($current['borrower_id'] ?? null);
    foreach (db_all('SELECT a.borrower_id FROM borrower_user_accounts a JOIN borrowers b ON b.id = a.borrower_id
                      WHERE a.deactivated_at IS NULL AND ' . owner_scope('borrowers', 'b')) as $row) {
        if (isset($borrowers[$row['borrower_id']])) {
            $borrowers[$row['borrower_id']] .= ' · has an account';
        }
    }

    return [
        'transaction_number' => ['label' => 'Transaction number', 'type' => 'text', 'max' => 50,
                                 'unique' => ['transactions', 'transaction_number', 'This transaction number is already used.'],
                                 'help' => 'Leave blank to generate one automatically.'],
        'credit_card_id'     => ['label' => 'Credit card used', 'type' => 'select', 'options' => credit_card_options($current['credit_card_id'] ?? null), 'required' => true, 'empty_label' => 'Select card'],
        'transaction_date'   => ['label' => 'Transaction date', 'type' => 'date', 'required' => true, 'initial' => date('Y-m-d')],
        'posting_date'       => ['label' => 'Posting date', 'type' => 'date'],
        'amount'             => ['label' => 'Amount', 'type' => 'decimal', 'required' => true, 'min' => 0],
        'currency'           => ['label' => 'Currency', 'type' => 'text', 'max' => 3, 'required' => true, 'initial' => 'PHP', 'pattern' => '/^[A-Z]{3}$/', 'pattern_message' => 'Use a 3-letter currency code, e.g. PHP.'],
        'transaction_type'   => ['label' => 'Transaction type', 'type' => 'select', 'options' => enum_options(ENUM_TXN_TYPE), 'required' => true, 'empty_label' => 'Select type', 'section' => 'Classification'],
        'payment_type'       => ['label' => 'Payment type', 'type' => 'select', 'options' => enum_options(ENUM_TXN_PAYMENT_TYPE), 'required' => true, 'empty_label' => 'Select payment type'],
        'status'             => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_TXN_STATUS), 'required' => true, 'initial' => 'draft'],
        'borrower_id'        => ['label' => 'Borrower (card user)', 'type' => 'select', 'options' => $borrowers, 'empty_label' => '— Owner\'s own purchase —',
                                 'help' => 'The borrower repays this transaction by your card\'s payment due date. It shows on their borrower page and in their account.'],
        'borrower_email'     => ['label' => 'Or: registered user\'s email', 'type' => 'email', 'max' => 255,
                                 'help' => 'When a registered user who is not in your list used your card. They are added to your borrowers.'],
        'merchant_id'        => ['label' => 'Merchant', 'type' => 'select', 'options' => merchant_options(), 'empty_label' => '— Not specified —'],
        'category_id'        => ['label' => 'Category', 'type' => 'select', 'options' => category_options($current['category_id'] ?? null), 'empty_label' => '— No category —'],
        'reference_number'   => ['label' => 'Reference number', 'type' => 'text', 'max' => 150, 'section' => 'References'],
        'receipt_number'     => ['label' => 'Receipt number', 'type' => 'text', 'max' => 150],
        'location'           => ['label' => 'Location', 'type' => 'textarea', 'rows' => 2],
        'notes'              => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 3],
    ];
}

function transaction_validate(array $fields, array $input, ?string $id = null): array
{
    $input['currency'] = strtoupper(trim((string) ($input['currency'] ?? '')));
    [$data, $errors] = validate_fields($fields, $input, $id);

    if ($data['posting_date'] && $data['transaction_date'] && $data['posting_date'] < $data['transaction_date']) {
        $errors['posting_date'] = 'Posting date cannot be before the transaction date.';
    }
    // A registered user given by email is checked here and becomes the borrower on save.
    if (($data['borrower_email'] ?? null) !== null && !isset($errors['borrower_email'])) {
        $found = db_row(
            "SELECT u.id FROM users u WHERE LOWER(u.email) = :e AND u.status = 'active' AND u.id IN " . borrowed_registered_users_sql(),
            [':e' => mb_strtolower($data['borrower_email'])]
        );
        if (!$found) {
            $errors['borrower_email'] = 'No registered user with this email. Ask them to create an account first.';
        } elseif ($found['id'] === owner_id()) {
            $errors['borrower_email'] = 'That is your own account. Leave the borrower empty for your own purchases.';
        }
    }

    return [$data, $errors];
}

function transaction_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        "SELECT t.*, c.card_nickname, c.last_four, m.merchant_name, m.branch, cat.name AS category_name,
                b.first_name, b.last_name, b.borrower_number,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', cu.first_name, cu.last_name)), ''), cu.email) AS created_by_name
           FROM transactions t
           JOIN credit_cards c ON c.id = t.credit_card_id
           LEFT JOIN merchants m ON m.id = t.merchant_id
           LEFT JOIN categories cat ON cat.id = t.category_id
           LEFT JOIN borrowers b ON b.id = t.borrower_id
           LEFT JOIN users cu ON cu.id = t.created_by
          WHERE t.id = :id AND " . owner_scope('transactions', 't'),
        [':id' => $id]
    );
}

/**
 * Records that prevent a hard delete (attachments cascade and are not counted).
 */
/**
 * Turn the "registered user's email" into the borrower (linking them on first
 * use). Call inside the save, before writing the transaction.
 */
function transaction_resolve_borrower(array $data): array
{
    $email = $data['borrower_email'] ?? null;
    unset($data['borrower_email']);
    if ($email !== null) {
        [$borrowerId, $error] = borrowed_link_user($email);
        if ($error !== null) {
            throw new DomainException($error);
        }
        $data['borrower_id'] = $borrowerId;
    }

    return $data;
}

function transaction_usage(string $id): array
{
    return record_usage([
        'items'            => ['transaction_items', 'transaction_id'],
        'installment plan' => ['installment_plans', 'transaction_id'],
        'obligations'      => ['borrower_obligations', 'transaction_id'],
        'agreements'       => ['agreements', 'transaction_id'],
    ], $id);
}

function transaction_item_fields(): array
{
    return [
        'product_name'           => ['label' => 'Product name', 'type' => 'text', 'max' => 255, 'required' => true],
        'product_type'           => ['label' => 'Product type', 'type' => 'text', 'max' => 150],
        'brand'                  => ['label' => 'Brand', 'type' => 'text', 'max' => 150],
        'model'                  => ['label' => 'Model', 'type' => 'text', 'max' => 150],
        'quantity'               => ['label' => 'Quantity', 'type' => 'decimal', 'scale' => 3, 'digits' => 12, 'required' => true, 'min_exclusive' => 0, 'initial' => '1', 'section' => 'Price', 'placeholder' => '1'],
        'unit_price'             => ['label' => 'Unit price', 'type' => 'decimal', 'required' => true, 'min' => 0],
        'total_amount'           => ['label' => 'Line total', 'type' => 'decimal', 'min' => 0, 'help' => 'Leave blank for quantity × unit price.', 'attrs' => ['data-product' => 'f_quantity,f_unit_price']],
        'serial_number'          => ['label' => 'Serial number', 'type' => 'text', 'max' => 255, 'section' => 'Identification'],
        'imei'                   => ['label' => 'IMEI', 'type' => 'text', 'max' => 255],
        'product_code'           => ['label' => 'Product code', 'type' => 'text', 'max' => 255],
        'barcode'                => ['label' => 'Barcode', 'type' => 'text', 'max' => 255],
        'asset_reference_number' => ['label' => 'Asset reference number', 'type' => 'text', 'max' => 255],
        'color'                  => ['label' => 'Color', 'type' => 'text', 'max' => 100],
        'size'                   => ['label' => 'Size', 'type' => 'text', 'max' => 100],
        'variant'                => ['label' => 'Variant', 'type' => 'text', 'max' => 150],
        'condition'              => ['label' => 'Condition', 'type' => 'text', 'max' => 100, 'placeholder' => 'e.g. brand new'],
        'or_number'              => ['label' => 'OR number', 'type' => 'text', 'max' => 150, 'section' => 'Receipts and warranty'],
        'si_number'              => ['label' => 'SI number', 'type' => 'text', 'max' => 150],
        'warranty_expiration'    => ['label' => 'Warranty expiration', 'type' => 'date'],
        'warranty_details'       => ['label' => 'Warranty details', 'type' => 'textarea', 'rows' => 2],
        'description'            => ['label' => 'Description', 'type' => 'textarea', 'rows' => 2],
        'remarks'                => ['label' => 'Remarks', 'type' => 'textarea', 'rows' => 2],
    ];
}

function transaction_item_validate(array $input): array
{
    [$data, $errors] = validate_fields(transaction_item_fields(), $input);

    if ($data['total_amount'] === null && !isset($errors['quantity']) && !isset($errors['unit_price'])) {
        $data['total_amount'] = number_format(round((float) $data['quantity'] * (float) $data['unit_price'], 2), 2, '.', '');
    }

    return [$data, $errors];
}

function transaction_label(array $t): string
{
    return $t['transaction_number'];
}
