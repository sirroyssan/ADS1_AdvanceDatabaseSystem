<?php
/**
 * Penalty Rules module (table: penalty_rules).
 * Referenced by applied_penalties.penalty_rule_id; soft delete via is_active.
 *
 * calculation_type is VARCHAR(30) in the schema. The form offers the two
 * types the columns support: "percentage" (uses rate) and "fixed" (uses
 * fixed_amount). Existing other values are kept when editing.
 */

defined('APP_ROOT') or exit('No direct access.');

function penalty_calculation_types(?string $current = null): array
{
    $types = ['percentage' => 'Percentage of the basis (uses Rate %)', 'fixed' => 'Fixed amount (uses Fixed amount)'];
    if ($current !== null && $current !== '' && !isset($types[$current])) {
        $types[$current] = $current;
    }

    return $types;
}

const PENALTY_BASIS_OPTIONS = [
    'outstanding_amount' => 'Outstanding (unpaid) amount',
    'overdue_amount'     => 'Overdue amount',
    'total_amount_due'   => 'Total amount due',
    'original_amount'    => 'Original amount',
];
const PENALTY_FREQUENCY_OPTIONS = [
    'monthly' => 'Every month while overdue',
    'once'    => 'Once (first time it becomes overdue)',
];

/** Keep an older free-text value selectable when editing. */
function penalty_keep_option(array $options, ?string $current): array
{
    if ($current !== null && $current !== '' && !isset($options[$current])) {
        $options[$current] = $current . ' (treated as ' . (strtolower($current) === 'once' ? 'once' : 'monthly') . ')';
    }

    return $options;
}

function penalty_rule_fields(?array $current = null): array
{
    return [
        'name'              => ['label' => 'Rule name', 'type' => 'text', 'max' => 150, 'required' => true],
        'applies_to_issuer' => ['label' => 'Bank', 'type' => 'select', 'options' => bank_options(bank_canonical($current['applies_to_issuer'] ?? null)),
                                'empty_label' => '— No bank: never charged automatically —',
                                'help' => 'Charged automatically only on this bank\'s purchases, installments, cash advances and cash loans. Changes take effect from the day you save; past charges are not recalculated.'],
        'calculation_type'  => ['label' => 'Calculation type', 'type' => 'select', 'options' => penalty_calculation_types($current['calculation_type'] ?? null), 'required' => true, 'empty_label' => 'Select type'],
        'basis'             => ['label' => 'Basis', 'type' => 'select', 'options' => penalty_keep_option(PENALTY_BASIS_OPTIONS, $current['basis'] ?? null), 'required' => true, 'initial' => 'outstanding_amount',
                                'help' => 'What a percentage is computed from. For an installment purchase it is the overdue installment.'],
        'rate'              => ['label' => 'Rate (%)', 'type' => 'decimal', 'scale' => 4, 'digits' => 10, 'min' => 0, 'placeholder' => '0.0000'],
        'fixed_amount'      => ['label' => 'Fixed amount', 'type' => 'decimal', 'min' => 0],
        'maximum_charge'    => ['label' => 'Maximum charge', 'type' => 'decimal', 'min' => 0, 'help' => 'Optional cap per application.'],
        'frequency'         => ['label' => 'Frequency', 'type' => 'select', 'options' => penalty_keep_option(PENALTY_FREQUENCY_OPTIONS, $current['frequency'] ?? null), 'required' => true, 'initial' => 'monthly',
                                'help' => 'Charged automatically on the first day after the grace period, then every month while still overdue.'],
        'grace_period_days' => ['label' => 'Grace period (days)', 'type' => 'int', 'min' => 0, 'max' => 3650, 'default' => 0],
        'effective_from'    => ['label' => 'Effective from', 'type' => 'date'],
        'effective_until'   => ['label' => 'Effective until', 'type' => 'date'],
        'is_active'         => ['label' => 'Status', 'type' => 'bool', 'switch_label' => 'Active (can be applied to obligations)', 'default' => 1],
    ];
}

function penalty_rule_validate(array $fields, array $input, ?string $id = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);

    if (($data['calculation_type'] ?? '') === 'percentage' && $data['rate'] === null && !isset($errors['rate'])) {
        $errors['rate'] = 'A percentage rule needs a rate.';
    }
    if (($data['calculation_type'] ?? '') === 'fixed' && $data['fixed_amount'] === null && !isset($errors['fixed_amount'])) {
        $errors['fixed_amount'] = 'A fixed rule needs a fixed amount.';
    }
    if ($data['effective_from'] && $data['effective_until'] && $data['effective_until'] < $data['effective_from']) {
        $errors['effective_until'] = 'Effective until must be on or after effective from.';
    }

    return [$data, $errors];
}

function penalty_rule_find(string $id): ?array
{
    return is_valid_uuid($id) ? db_row('SELECT * FROM penalty_rules WHERE id = :id', [':id' => $id]) : null;
}

function penalty_rule_usage(string $id): array
{
    return record_usage(['applied penalties' => ['applied_penalties', 'penalty_rule_id']], $id);
}

/**
 * Amount a rule produces for a calculation base (used by the obligation module).
 */
function penalty_rule_amount(array $rule, float $base): float
{
    $amount = $rule['calculation_type'] === 'percentage'
        ? round($base * ((float) $rule['rate']) / 100, 2)
        : (float) $rule['fixed_amount'];

    if ($rule['maximum_charge'] !== null) {
        $amount = min($amount, (float) $rule['maximum_charge']);
    }

    return max(0, $amount);
}

