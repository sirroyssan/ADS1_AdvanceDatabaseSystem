<?php
/**
 * Installment Rules module (table: installment_rules).
 * The installment terms a bank offers (months, interest method and rate,
 * processing fee) as written in the bank's own terms - entered by the user,
 * never assumed. A plan created from a rule keeps a snapshot of the terms
 * (installment_plans.terms_snapshot), so later changes to a rule never change
 * existing plans. Referenced by installment_plans.installment_rule_id; soft
 * delete via is_active.
 */

defined('APP_ROOT') or exit('No direct access.');

/** Methods a rule can use: a fixed interest amount belongs to one purchase, not to a rule. */
function installment_rule_methods(): array
{
    return array_diff_key(INSTALLMENT_METHODS, ['fixed_amount' => true]);
}

function installment_rule_fields(?array $current = null): array
{
    return [
        'name'            => ['label' => 'Rule name', 'type' => 'text', 'max' => 150, 'required' => true, 'placeholder' => 'e.g. BDO 0% installment'],
        'credit_card_id'  => ['label' => 'Card', 'type' => 'select', 'options' => credit_card_options($current['credit_card_id'] ?? null), 'empty_label' => '— Any card of the bank below —',
                              'help' => 'Leave empty to use the rule for every card of the bank.'],
    ] + bank_fields('issuer', ['label' => 'Bank', 'empty_label' => '— Any bank —'], bank_canonical($current['issuer'] ?? null)) + [
        'terms'           => ['label' => 'Months offered', 'type' => 'text', 'max' => 100, 'required' => true, 'placeholder' => '3, 6, 12, 24',
                              'help' => 'The installment terms the bank offers, separated by commas.'],
        'min_amount'      => ['label' => 'Minimum amount', 'type' => 'decimal', 'min' => 0],
        'max_amount'      => ['label' => 'Maximum amount', 'type' => 'decimal', 'min' => 0],
        'interest_method' => ['label' => 'Interest', 'type' => 'select', 'options' => installment_rule_methods(), 'required' => true, 'initial' => 'none'],
        'interest_rate'   => ['label' => 'Interest rate (%)', 'type' => 'decimal', 'scale' => 4, 'digits' => 8, 'min' => 0,
                              'help' => 'Per month for add-on per month and reducing balance; for the whole term for add-on total. As stated by the bank.'],
        'fee_type'        => ['label' => 'Processing fee', 'type' => 'select', 'options' => INSTALLMENT_FEE_TYPES, 'required' => true, 'initial' => 'none'],
        'fee_value'       => ['label' => 'Fee (₱ or %)', 'type' => 'decimal', 'scale' => 4, 'digits' => 15, 'min' => 0],
        'fee_billing'     => ['label' => 'Fee billed', 'type' => 'select', 'options' => INSTALLMENT_FEE_BILLING, 'required' => true, 'initial' => 'spread'],
        'effective_from'  => ['label' => 'Effective from', 'type' => 'date'],
        'effective_until' => ['label' => 'Effective until', 'type' => 'date'],
        'is_active'       => ['label' => 'Status', 'type' => 'bool', 'switch_label' => 'Active (offered on new installment purchases)', 'default' => 1],
        'notes'           => ['label' => 'Notes', 'type' => 'textarea', 'help' => 'For example where the terms come from (bank website, SOA, promo letter).'],
    ];
}

function installment_rule_validate(array $fields, array $input, ?string $id = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);
    [$data, $errors] = bank_resolve($data, $errors, 'issuer');

    if (!isset($errors['terms'])) {
        $months = installment_rule_terms(['terms' => $data['terms']]);
        if (!$months || max($months) > 360) {
            $errors['terms'] = 'Enter the months offered, for example 3, 6, 12 (1 to 360).';
        } else {
            sort($months);
            $data['terms'] = implode(',', array_unique($months));
        }
    }
    foreach (installment_terms_errors(['principal' => 1, 'months' => 1, 'interest_amount' => null] + $data) as $field => $message) {
        $errors[$field] ??= $message;
    }
    if ($data['interest_method'] === 'none') {
        $data['interest_rate'] = null;
    }
    if ($data['fee_type'] === 'none') {
        $data['fee_value'] = null;
    }
    if ($data['min_amount'] !== null && $data['max_amount'] !== null && (float) $data['max_amount'] < (float) $data['min_amount']) {
        $errors['max_amount'] = 'The maximum must be at least the minimum.';
    }
    if ($data['effective_from'] && $data['effective_until'] && $data['effective_until'] < $data['effective_from']) {
        $errors['effective_until'] = 'Effective until must be on or after effective from.';
    }
    if ($data['credit_card_id'] && !isset($errors['credit_card_id'])) {
        // A card rule belongs to the card's bank.
        $data['issuer'] = db_value('SELECT issuer FROM credit_cards WHERE id = :id', [':id' => $data['credit_card_id']]);
    }

    return [$data, $errors];
}

function installment_rule_usage(string $id): array
{
    return record_usage(['installment plans' => ['installment_plans', 'installment_rule_id']], $id);
}

/** Short text of a rule's terms, e.g. "3/6/12 months · no interest · fee 1% with the first". */
function installment_rule_summary(array $rule): string
{
    $rate = rtrim(rtrim((string) $rule['interest_rate'], '0'), '.');
    $interest = match ($rule['interest_method']) {
        'none' => 'no interest',
        'add_on_monthly' => 'add-on ' . $rate . '% per month',
        'add_on_total' => 'add-on ' . $rate . '% for the term',
        'reducing_balance' => $rate . '% per month on the remaining balance',
        default => $rule['interest_method'],
    };
    $value = rtrim(rtrim((string) $rule['fee_value'], '0'), '.');
    $fee = match ($rule['fee_type']) {
        'fixed' => 'fee ' . format_money($rule['fee_value']),
        'percent' => 'fee ' . $value . '%',
        default => 'no fee',
    };
    if ($rule['fee_type'] !== 'none' && $rule['fee_billing'] === 'first') {
        $fee .= ' with the first';
    }

    return implode('/', installment_rule_terms($rule)) . ' months · ' . $interest . ' · ' . $fee;
}
