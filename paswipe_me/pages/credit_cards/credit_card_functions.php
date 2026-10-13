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
    [$lineOptions, $lineData, $lineSuggest] = credit_line_choices($current);
    $fields = [
        'card_nickname'     => ['label' => 'Card nickname', 'type' => 'text', 'max' => 100, 'required' => true],
    ] + bank_fields('issuer', ['label' => 'Issuer (bank)', 'required' => true, 'empty_label' => 'Select bank',
                                'help' => 'Penalty rules are set per bank, so this decides which penalty rules apply to this card\'s transactions, cash advances and cash loans.'],
        bank_canonical($current['issuer'] ?? null)) + [
        'card_type'         => ['label' => 'Card type', 'type' => 'select', 'options' => enum_options(ENUM_CARD_TYPE), 'required' => true, 'initial' => 'credit'],
        'network'           => ['label' => 'Network', 'type' => 'select', 'options' => array_combine(ENUM_CARD_NETWORK, ['Visa', 'Mastercard', 'American Express', 'JCB', 'UnionPay', 'Other']), 'empty_label' => '— Not set —'],
        'last_four'         => ['label' => 'Last four digits', 'type' => 'text', 'max' => 4, 'required' => true, 'pattern' => '/^[0-9]{4}$/', 'pattern_message' => 'Enter exactly 4 digits.', 'help' => 'Never enter the full card number.'],
        'status'            => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_CARD_STATUS), 'required' => true, 'initial' => 'active'],
        'credit_limit'      => ['label' => 'Credit limit', 'type' => 'decimal', 'min' => 0, 'required' => true, 'section' => 'Limits and billing',
                                'help' => 'For a card that shares its limit: the shared limit of the whole line (changing it changes it for every card in the line).'],
        'credit_line'       => ['label' => 'Limit shared with other cards?', 'type' => 'select', 'options' => $lineOptions, 'option_data' => $lineData,
                                'attrs' => ['data-fills' => 'f_credit_limit:limit', 'data-suggest' => json_encode($lineSuggest, JSON_UNESCAPED_UNICODE)],
                                'help' => 'Some banks give several cards one limit (e.g. BDO Visa, Shopmore and AMEX). Each card keeps its own statements and due dates; only the room left is shared.'],
        'sub_limit'         => ['label' => 'Card sub-limit (optional)', 'type' => 'decimal', 'min' => 0,
                                'help' => 'Only for a shared limit: the most this card may use by itself (e.g. a cash or AMEX cap). Leave empty to use the whole shared limit.'],
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

    if (($data['sub_limit'] ?? null) !== null && ($data['credit_line'] ?? '') === '') {
        $errors['sub_limit'] = 'A sub-limit is only for a card that shares its limit. Leave it empty, or choose the shared limit above.';
    }
    if (!isset($errors['issuer']) && !isset($errors['last_four']) && ($message = credit_card_duplicate_message($data, $id)) !== null) {
        $errors['last_four'] = $message;
    }
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
 * Save a card, its opening balance and its shared credit line; available_credit
 * is then calculated (card_usage.php), never typed. Returns notes about the
 * shared limit; throws DomainException / PDOException.
 */
function credit_card_save(?string $id, array $data, ?string $existingId = null): array
{
    $opening = $data['opening_balance'] ?? '0.00';
    $choice = (string) ($data['credit_line'] ?? '');
    $subLimit = isset($data['sub_limit']) && $data['sub_limit'] !== null ? (float) $data['sub_limit'] : null;
    unset($data['opening_balance'], $data['credit_line'], $data['sub_limit']);
    $cardId = $existingId ?? $id;
    // The cards whose room changes with this one: the line it joins, or the card it now shares with.
    $others = str_starts_with($choice, '__with:') ? [substr($choice, 7)]
        : ($choice !== '' ? array_column(db_all('SELECT id FROM credit_cards WHERE credit_line_id = :l', [':l' => $choice]), 'id') : []);

    return card_usage_apply(array_merge([$cardId], $others), function () use ($id, $existingId, $cardId, $data, $opening, $choice, $subLimit) {
        // Checked again under a lock: two saves at once (a double click) cannot both add the card.
        db_value('SELECT id FROM users WHERE id = :o FOR UPDATE', [':o' => owner_id()]);
        if (($message = credit_card_duplicate_message($data, $existingId)) !== null) {
            throw new DomainException($message);
        }
        if ($existingId === null) {
            // Start at the full limit (schema CHECK); card_usage_apply then stores the real figure.
            // A card always belongs to the signed-in user; no form can set or change its owner.
            db_insert('credit_cards', ['id' => $id, 'owner_user_id' => owner_id()] + $data + ['available_credit' => $data['credit_limit']]);
        } else {
            db_update('credit_cards', $data, $existingId);
        }
        card_set_opening_balance($cardId, $opening, $data['opening_date'] ?? null);

        return credit_line_assign($cardId, $choice, (float) $data['credit_limit'], $subLimit);
    });
}

/**
 * The same card is never recorded twice: one of your cards (not closed) of
 * the same bank, network (Visa, Mastercard ...) and last four digits. Cards of
 * one bank on different networks may end in the same digits (e.g. BDO Visa
 * •••• 4106 and BDO Shopmore Mastercard •••• 4106). Returns why, or null.
 */
function credit_card_duplicate_message(array $data, ?string $exceptId = null): ?string
{
    if (empty($data['last_four']) || empty($data['issuer'])) {
        return null;
    }
    $same = db_all(
        "SELECT cc.id, cc.card_nickname, cc.issuer, cc.network, cc.last_four FROM credit_cards cc
          WHERE cc.last_four = :l AND cc.status <> 'closed' AND cc.id <> :x AND " . owner_scope('credit_cards', 'cc'),
        [':l' => $data['last_four'], ':x' => $exceptId ?? '']
    );
    foreach ($same as $card) {
        if (same_bank($card['issuer'], $data['issuer']) && (string) $card['network'] === (string) ($data['network'] ?? '')) {
            return 'You already have this card: "' . $card['card_nickname'] . '" (' . bank_canonical($card['issuer'])
                . ($card['network'] ? ' ' . strtoupper($card['network']) : '') . ' •••• ' . $card['last_four']
                . '). Open that card instead of adding it again. A card of another network (Visa, Mastercard ...) may end in the same digits.';
        }
    }

    return null;
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
