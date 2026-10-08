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

function penalty_rule_fields(?array $current = null): array
{
    return [
        'name'              => ['label' => 'Rule name', 'type' => 'text', 'max' => 150, 'required' => true],
        'calculation_type'  => ['label' => 'Calculation type', 'type' => 'select', 'options' => penalty_calculation_types($current['calculation_type'] ?? null), 'required' => true, 'empty_label' => 'Select type'],
        'basis'             => ['label' => 'Basis', 'type' => 'text', 'max' => 50, 'required' => true, 'help' => 'What the penalty is computed from, e.g. outstanding_amount or overdue_amount.'],
        'rate'              => ['label' => 'Rate (%)', 'type' => 'decimal', 'scale' => 4, 'digits' => 10, 'min' => 0, 'placeholder' => '0.0000'],
        'fixed_amount'      => ['label' => 'Fixed amount', 'type' => 'decimal', 'min' => 0],
        'maximum_charge'    => ['label' => 'Maximum charge', 'type' => 'decimal', 'min' => 0, 'help' => 'Optional cap per application.'],
        'frequency'         => ['label' => 'Frequency', 'type' => 'text', 'max' => 30, 'help' => 'e.g. once, daily, monthly.'],
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

function penalty_rule_summary(array $rule): string
{
    if ($rule['calculation_type'] === 'percentage') {
        $text = rtrim(rtrim(number_format((float) $rule['rate'], 4), '0'), '.') . '% of ' . $rule['basis'];
    } elseif ($rule['calculation_type'] === 'fixed') {
        $text = format_money($rule['fixed_amount']) . ' fixed';
    } else {
        $text = $rule['calculation_type'] . ' on ' . $rule['basis'];
    }

    return $text . ($rule['maximum_charge'] !== null ? ' (max ' . format_money($rule['maximum_charge']) . ')' : '');
}
