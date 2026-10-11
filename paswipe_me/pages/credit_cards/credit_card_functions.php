<?php
/**
 * Credit Cards module (table: credit_cards) with card_billing_cycles managed
 * on the card page. Only nickname, issuer and the LAST FOUR digits are
 * stored - never the full card number, CVV or PIN (schema note #1).
 * Soft delete: status (blocked/expired/closed/inactive).
 */

defined('APP_ROOT') or exit('No direct access.');

const CARD_DUE_RULES = ['day_of_month' => 'Fixed day of the month', 'days_after_cutoff' => 'Number of days after the cutoff'];

/** Card settings that shape statement periods (changes are logged). */
const CARD_BILLING_SETTINGS = ['billing_cycle_day', 'due_date_rule', 'payment_due_day', 'due_days_after_cutoff', 'minimum_due_percent', 'minimum_due_floor'];

function credit_card_fields(?array $current = null): array
{
    $fields = [
        'card_nickname'     => ['label' => 'Card nickname', 'type' => 'text', 'max' => 100, 'required' => true],
    ] + bank_fields('issuer', ['label' => 'Issuer (bank)', 'required' => true, 'empty_label' => 'Select bank',
                                'help' => 'Penalty rules are set per bank, so this decides which penalty rules apply to this card\'s transactions, cash advances and cash loans.'],
        bank_canonical($current['issuer'] ?? null)) + [
        'card_type'         => ['label' => 'Card type', 'type' => 'select', 'options' => enum_options(ENUM_CARD_TYPE), 'required' => true, 'initial' => 'credit'],
        'network'           => ['label' => 'Network', 'type' => 'select', 'options' => array_combine(ENUM_CARD_NETWORK, ['Visa', 'Mastercard', 'American Express', 'JCB', 'UnionPay', 'Other']), 'empty_label' => '— Not set —'],
        'last_four'         => ['label' => 'Last four digits', 'type' => 'text', 'max' => 4, 'required' => true, 'pattern' => '/^[0-9]{4}$/', 'pattern_message' => 'Enter exactly 4 digits.', 'help' => 'Never enter the full card number.'],
        'status'            => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_CARD_STATUS), 'required' => true, 'initial' => 'active'],
        'credit_limit'      => ['label' => 'Credit limit', 'type' => 'decimal', 'min' => 0, 'required' => true, 'section' => 'Limits and billing'],
        'opening_balance'   => ['label' => 'Opening balance', 'type' => 'decimal', 'min' => 0, 'default' => '0.00',
                                'help' => 'Amount already used on the card before you started recording it here. Used credit, available credit and utilization are then calculated automatically from your transactions, cash loans, cash advances, bank charges and payments.'],
        'interest_rate'     => ['label' => 'Interest rate (% per month)', 'type' => 'decimal', 'scale' => 4, 'digits' => 8, 'min' => 0, 'placeholder' => '0.0000'],
        'billing_cycle_day' => ['label' => 'Statement cutoff day', 'type' => 'int', 'min' => 1, 'max' => 31, 'section' => 'Billing cycle and statements',
                                'help' => 'Day of the month the bank closes each statement (e.g. 15). The Statements module groups this card\'s records into periods with it; a month without that day uses its last day.'],
        'due_date_rule'     => ['label' => 'Payment due date rule', 'type' => 'select', 'options' => CARD_DUE_RULES, 'empty_label' => '— Not known yet —',
                                'help' => 'How the bank sets the due date. Used only to suggest the due date when you record an SOA.'],
        'payment_due_day'   => ['label' => 'Payment due day', 'type' => 'int', 'min' => 1, 'max' => 31, 'help' => 'For "fixed day of the month": the first such day after the cutoff.'],
        'due_days_after_cutoff' => ['label' => 'Days after cutoff', 'type' => 'int', 'min' => 1, 'max' => 60, 'help' => 'For "days after the cutoff", e.g. 20.'],
        'minimum_due_percent' => ['label' => 'Minimum due (% of statement balance)', 'type' => 'decimal', 'scale' => 4, 'digits' => 7, 'min' => 0, 'max' => 100,
                                'help' => 'For reference only (e.g. 3). The minimum due saved on a statement is always the one printed on the bank\'s SOA.'],
        'minimum_due_floor' => ['label' => 'Minimum due at least (₱)', 'type' => 'decimal', 'min' => 0, 'help' => 'e.g. 500 — the minimum is never lower than this (or the whole balance if smaller).'],
        'annual_fee'        => ['label' => 'Annual fee', 'type' => 'bool', 'switch_label' => 'This card has an annual fee'],
        'annual_fee_amount' => ['label' => 'Annual fee amount', 'type' => 'decimal', 'min' => 0, 'default' => '0.00'],
        'opening_date'      => ['label' => 'Opening date', 'type' => 'date', 'section' => 'Dates'],
        'expiration_date'   => ['label' => 'Expiration date', 'type' => 'date'],
        'notes'             => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 3],
    ];
    return $fields;
}

function credit_card_validate(array $fields, array $input, ?string $id = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);
    [$data, $errors] = bank_resolve($data, $errors, 'issuer');

    if ($data['opening_date'] && $data['expiration_date'] && $data['expiration_date'] < $data['opening_date']) {
        $errors['expiration_date'] = 'Expiration date must be after the opening date.';
    }
    if ((int) $data['annual_fee'] === 0) {
        $data['annual_fee_amount'] = '0.00';
    }
    if ($data['due_date_rule'] === null && $data['payment_due_day'] !== null) {
        $data['due_date_rule'] = 'day_of_month';
    }
    if ($data['due_date_rule'] === 'day_of_month' && $data['payment_due_day'] === null) {
        $errors['payment_due_day'] = 'Enter the payment due day for a fixed-day rule.';
    }
    if ($data['due_date_rule'] === 'days_after_cutoff' && $data['due_days_after_cutoff'] === null) {
        $errors['due_days_after_cutoff'] = 'Enter how many days after the cutoff the payment is due.';
    }
    if ($data['due_date_rule'] !== 'days_after_cutoff') {
        $data['due_days_after_cutoff'] = null;
    }

    return [$data, $errors];
}

/**
 * Save a card and its opening balance; available_credit is then calculated
 * (card_usage.php), never typed. Returns nothing; throws PDOException.
 */
function credit_card_save(?string $id, array $data, ?string $existingId = null): void
{
    $opening = $data['opening_balance'] ?? '0.00';
    unset($data['opening_balance']);
    $cardId = $existingId ?? $id;

    card_usage_apply([$cardId], function () use ($id, $existingId, $cardId, $data, $opening) {
        if ($existingId === null) {
            // Start at the full limit (schema CHECK); card_usage_apply then stores the real figure.
            // A card always belongs to the signed-in user; no form can set or change its owner.
            db_insert('credit_cards', ['id' => $id, 'owner_user_id' => owner_id()] + $data + ['available_credit' => $data['credit_limit']]);
        } else {
            db_update('credit_cards', $data, $existingId);
        }
        card_set_opening_balance($cardId, $opening, $data['opening_date'] ?? null);
    });
}

function credit_card_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        "SELECT cc.*, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email) AS owner_name
           FROM credit_cards cc LEFT JOIN users u ON u.id = cc.owner_user_id
          WHERE cc.id = :id AND " . owner_scope('credit_cards', 'cc'),
        [':id' => $id]
    );
}

function credit_card_label(array $card): string
{
    return $card['card_nickname'] . ' •••• ' . $card['last_four'];
}

/**
 * Records that keep a card (billing cycles cascade and are not counted).
 */
function credit_card_usage(string $id): array
{
    $usage = record_usage([
        'transactions'  => ['transactions', 'credit_card_id'],
        'statements'    => ['credit_card_statements', 'credit_card_id'],
        'cash loans'    => ['cash_loans', 'credit_card_id'],
        'cash advances' => ['cash_advances', 'credit_card_id'],
        'card payments' => ['credit_card_payments', 'credit_card_id'],
        'bank charges'  => ['bank_charges', 'credit_card_id'],
    ], $id);

    // The opening balance is a setting of the card itself, not a record that keeps it.
    $opening = (int) db_value('SELECT COUNT(*) FROM bank_charges WHERE credit_card_id = :id AND charge_type = :t', [':id' => $id, ':t' => OPENING_BALANCE_TYPE]);
    $usage['bank charges'] -= $opening;
    $usage['total'] -= $opening;

    return $usage;
}

function billing_cycle_fields(): array
{
    return [
        'cycle_start_date' => ['label' => 'Cycle start', 'type' => 'date', 'required' => true],
        'cycle_end_date'   => ['label' => 'Cycle end', 'type' => 'date', 'required' => true],
        'statement_date'   => ['label' => 'Statement date', 'type' => 'date'],
        'payment_due_date' => ['label' => 'Payment due date', 'type' => 'date'],
    ];
}
