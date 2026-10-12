<?php
/**
 * Installment terms: how a plan's monthly schedule is calculated, and the
 * installment rules (offers) of a bank or a card (db/upgrade_installments.sql).
 *
 * One purchase = one transactions row. Its installment plan (one per
 * transaction, UNIQUE) and schedule rows (UNIQUE plan + number) hang off it.
 * The plan keeps the terms it was calculated with (method, rate, fees, rule
 * and a snapshot of the rule), so editing a rule later never changes it.
 *
 * Interest methods (nothing is assumed: the method and rate come from the
 * rule or from the form):
 *   none             0% - principal only
 *   fixed_amount     a total interest amount, spread evenly
 *   add_on_monthly   rate % of the principal per month x months, spread evenly
 *                    (the usual "add-on rate" of card installments)
 *   add_on_total     rate % of the principal once for the whole term, spread evenly
 *   reducing_balance rate % per month on the remaining principal (equal monthly
 *                    payments; interest falls, principal rises)
 * Processing fee: none, fixed amount, or % of the principal; billed with
 * every installment (spread) or with the first one.
 * Amounts are worked in centavos; the last installment absorbs the rounding,
 * so the rows always add up to principal + interest + fees exactly.
 */

defined('APP_ROOT') or exit('No direct access.');

const INSTALLMENT_UPGRADE_MARKER = 'storage/upgrade_installments.done';

const INSTALLMENT_METHODS = [
    'none'             => 'No interest (0%)',
    'fixed_amount'     => 'Fixed interest amount',
    'add_on_monthly'   => 'Add-on rate per month (% of principal × months)',
    'add_on_total'     => 'Add-on rate for the whole term (% of principal)',
    'reducing_balance' => 'Reducing balance (monthly rate on the remaining principal)',
];
const INSTALLMENT_FEE_TYPES = ['none' => 'No processing fee', 'fixed' => 'Fixed amount', 'percent' => '% of the principal'];
const INSTALLMENT_FEE_BILLING = ['spread' => 'Spread over every installment', 'first' => 'Billed with the first installment'];

/* ------------------------------------------------------------------
 * Schema
 * ------------------------------------------------------------------ */

function installments_schema_upgrade(): void
{
    if (marker_exists(INSTALLMENT_UPGRADE_MARKER)) {
        return;
    }
    $has = static fn (string $table, string $column) => (int) db_value(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
        [':t' => $table, ':c' => $column]
    ) > 0;
    $id = db_row("SELECT CHARACTER_SET_NAME AS cs, COLLATION_NAME AS co FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id'");
    $uuid = 'CHAR(36)' . ($id && $id['cs'] ? ' CHARACTER SET ' . $id['cs'] . ' COLLATE ' . $id['co'] : '');

    foreach ([
        'interest_method'     => 'VARCHAR(30) NULL',
        'interest_rate'       => 'DECIMAL(8,4) NULL',
        'fee_type'            => 'VARCHAR(20) NULL',
        'fee_value'           => 'DECIMAL(15,4) NULL',
        'fee_billing'         => 'VARCHAR(20) NULL',
        'installment_rule_id' => $uuid . ' NULL',
        'terms_snapshot'      => 'TEXT NULL',
    ] as $column => $type) {
        if (!$has('installment_plans', $column)) {
            db()->exec("ALTER TABLE installment_plans ADD COLUMN $column $type");
        }
    }
    db()->exec("CREATE TABLE IF NOT EXISTS installment_rules (
        id $uuid NOT NULL PRIMARY KEY,
        owner_user_id $uuid NOT NULL,
        credit_card_id $uuid NULL,
        issuer VARCHAR(150) NULL,
        name VARCHAR(150) NOT NULL,
        min_amount DECIMAL(15,2) NULL,
        max_amount DECIMAL(15,2) NULL,
        terms VARCHAR(100) NOT NULL,
        interest_method VARCHAR(30) NOT NULL,
        interest_rate DECIMAL(8,4) NULL,
        fee_type VARCHAR(20) NOT NULL DEFAULT 'none',
        fee_value DECIMAL(15,4) NULL,
        fee_billing VARCHAR(20) NOT NULL DEFAULT 'spread',
        effective_from DATE NULL,
        effective_until DATE NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_installment_rules_owner (owner_user_id),
        KEY idx_installment_rules_card (credit_card_id),
        CONSTRAINT chk_installment_rules_amounts CHECK (min_amount IS NULL OR max_amount IS NULL OR min_amount <= max_amount)
    )");
    foreach ([
        ['installment_rules', 'fk_installment_rules_owner', 'owner_user_id', 'users'],
        ['installment_rules', 'fk_installment_rules_card', 'credit_card_id', 'credit_cards'],
        ['installment_plans', 'fk_installment_plans_rule', 'installment_rule_id', 'installment_rules'],
    ] as [$table, $name, $column, $ref]) {
        try {
            if (!(int) db_value('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
                                  AND COLUMN_NAME = :c AND REFERENCED_TABLE_NAME = :r', [':t' => $table, ':c' => $column, ':r' => $ref])) {
                db()->exec("ALTER TABLE $table ADD CONSTRAINT $name FOREIGN KEY ($column) REFERENCES $ref (id)");
            }
        } catch (PDOException $e) {
            error_log('[' . APP_NAME . '] Installments upgrade: foreign key ' . $name . ' not added: ' . $e->getMessage());
        }
    }
    marker_write(INSTALLMENT_UPGRADE_MARKER);
}

/* ------------------------------------------------------------------
 * Calculation
 * ------------------------------------------------------------------ */

/**
 * Check a set of terms. $t: principal, months, interest_method, interest_rate,
 * interest_amount (fixed_amount), fee_type, fee_value, fee_billing.
 * Returns [field => message].
 */
function installment_terms_errors(array $t, string $prefix = ''): array
{
    $errors = [];
    $method = $t['interest_method'] ?? '';
    if ((float) ($t['principal'] ?? 0) <= 0) {
        $errors[$prefix . 'principal'] = 'The amount to pay in installments must be more than zero.';
    }
    if ((int) ($t['months'] ?? 0) < 1 || (int) $t['months'] > 360) {
        $errors[$prefix . 'months'] = 'Enter the number of monthly installments (1 to 360).';
    }
    if (!isset(INSTALLMENT_METHODS[$method])) {
        $errors[$prefix . 'interest_method'] = 'Choose how interest is calculated.';
    } elseif (in_array($method, ['add_on_monthly', 'add_on_total', 'reducing_balance'], true) && ($t['interest_rate'] === null || $t['interest_rate'] === '')) {
        $errors[$prefix . 'interest_rate'] = 'Enter the interest rate of this method (0 if none).';
    } elseif ($method === 'fixed_amount' && ($t['interest_amount'] === null || $t['interest_amount'] === '')) {
        $errors[$prefix . 'interest_amount'] = 'Enter the total interest amount.';
    }
    if (!isset(INSTALLMENT_FEE_TYPES[$t['fee_type'] ?? ''])) {
        $errors[$prefix . 'fee_type'] = 'Choose the processing fee type.';
    } elseif ($t['fee_type'] !== 'none' && ($t['fee_value'] === null || $t['fee_value'] === '')) {
        $errors[$prefix . 'fee_value'] = 'Enter the processing fee.';
    }
    if (!isset(INSTALLMENT_FEE_BILLING[$t['fee_billing'] ?? 'spread'])) {
        $errors[$prefix . 'fee_billing'] = 'Choose how the fee is billed.';
    }

    return $errors;
}

/**
 * Split $cents into $n parts rounded to the nearest centavo (as banks bill:
 * ₱20,999 / 12 = ₱1,749.92); the last part absorbs the difference.
 */
function installment_split(int $cents, int $n): array
{
    $base = (int) round($cents / $n);
    if ($base * ($n - 1) > $cents) {
        $base = intdiv($cents, $n);   // never a negative last part
    }
    $parts = array_fill(0, $n, $base);
    $parts[$n - 1] += $cents - $base * $n;

    return $parts;
}

/**
 * The schedule of a set of terms (see installment_terms_errors for $t) with
 * first due date and frequency. Returns ['interest', 'fees', 'total', 'monthly', 'rows'].
 */
function installment_compute(array $t, string $firstDue, string $frequency = 'monthly'): array
{
    $n = (int) $t['months'];
    $P = (int) round((float) $t['principal'] * 100);
    $rate = (float) ($t['interest_rate'] ?? 0) / 100;
    $method = $t['interest_method'];

    $feeCents = match ($t['fee_type'] ?? 'none') {
        'fixed'   => (int) round((float) $t['fee_value'] * 100),
        'percent' => (int) round($P * (float) $t['fee_value'] / 100),
        default   => 0,
    };
    $feeFirst = ($t['fee_billing'] ?? 'spread') === 'first';
    $fees = $feeFirst ? array_merge([$feeCents], array_fill(0, $n - 1, 0)) : installment_split($feeCents, $n);

    if ($method === 'reducing_balance' && $rate > 0) {
        // Equal payments (annuity) on the declining principal.
        $payment = (int) round($P * $rate / (1 - (1 + $rate) ** -$n));
        $balance = $P;
        $principal = $interest = [];
        for ($i = 0; $i < $n; $i++) {
            $int = (int) round($balance * $rate);
            $prin = $i === $n - 1 ? $balance : min($balance, $payment - $int);
            $balance -= $prin;
            $principal[] = $prin;
            $interest[] = $int;
        }
    } else {
        $interestCents = match ($method) {
            'fixed_amount'   => (int) round((float) $t['interest_amount'] * 100),
            'add_on_monthly' => (int) round($P * $rate * $n),
            'add_on_total'   => (int) round($P * $rate),
            default          => 0,
        };
        // Equal monthly amounts: split the total, interest the same way, principal is the rest.
        $interest = installment_split($interestCents, $n);
        $total = installment_split($P + $interestCents, $n);
        $principal = array_map(static fn ($a, $b) => $a - $b, $total, $interest);
    }

    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $due = $principal[$i] + $interest[$i] + $fees[$i];
        $rows[] = [
            'installment_number' => $i + 1,
            'due_date'           => schedule_due_date($firstDue, $i, $frequency),
            'principal_amount'   => number_format($principal[$i] / 100, 2, '.', ''),
            'interest_amount'    => number_format($interest[$i] / 100, 2, '.', ''),
            'fee_amount'         => number_format($fees[$i] / 100, 2, '.', ''),
            'amount_due'         => number_format($due / 100, 2, '.', ''),
            'remaining_amount'   => number_format($due / 100, 2, '.', ''),
        ];
    }
    $interestTotal = array_sum($interest);
    $totalCents = $P + $interestTotal + $feeCents;
    // The usual monthly amount (not the first one when the fee is billed with it).
    $typical = $rows[$feeFirst && $n > 1 ? 1 : 0]['amount_due'];

    return [
        'interest' => number_format($interestTotal / 100, 2, '.', ''),
        'fees'     => number_format($feeCents / 100, 2, '.', ''),
        'total'    => number_format($totalCents / 100, 2, '.', ''),
        'monthly'  => $typical,
        'rows'     => $rows,
    ];
}

/** Schedule rows of a saved plan: its own terms, or (plans entered by hand) an even split. */
function installment_plan_rows(array $plan): array
{
    if (empty($plan['interest_method'])) {
        return build_schedule((float) $plan['total_principal'], (float) $plan['interest_amount'], (float) $plan['fees_amount'],
            (int) $plan['number_of_installments'], $plan['first_due_date'], $plan['frequency']);
    }

    return installment_compute([
        'principal' => $plan['total_principal'], 'months' => $plan['number_of_installments'], 'interest_method' => $plan['interest_method'],
        'interest_rate' => $plan['interest_rate'], 'interest_amount' => $plan['interest_amount'],
        'fee_type' => $plan['fee_type'] ?? 'none', 'fee_value' => $plan['fee_value'], 'fee_billing' => $plan['fee_billing'] ?? 'spread',
    ], $plan['first_due_date'], $plan['frequency'])['rows'];
}

/**
 * Save a purchase's plan and schedule (inside the caller's transaction).
 * $t: terms (installment_terms_errors) + first_due_date; $rule: the rule used, if any.
 */
function installment_plan_create(array $txn, array $t, ?array $rule): string
{
    $calc = installment_compute($t, $t['first_due_date']);
    $id = new_uuid();
    db_insert('installment_plans', [
        'id' => $id, 'transaction_id' => $txn['id'], 'total_principal' => number_format((float) $t['principal'], 2, '.', ''),
        'number_of_installments' => (int) $t['months'], 'installment_amount' => $calc['monthly'],
        'interest_amount' => $calc['interest'], 'fees_amount' => $calc['fees'], 'total_repayment' => $calc['total'],
        'frequency' => 'monthly', 'start_date' => $txn['transaction_date'], 'first_due_date' => $t['first_due_date'],
        'end_date' => end($calc['rows'])['due_date'], 'status' => 'active',
        'interest_method' => $t['interest_method'], 'interest_rate' => $t['interest_rate'] === '' ? null : $t['interest_rate'],
        'fee_type' => $t['fee_type'], 'fee_value' => $t['fee_value'] === '' ? null : $t['fee_value'], 'fee_billing' => $t['fee_billing'],
        'installment_rule_id' => $rule['id'] ?? null,
        'terms_snapshot' => $rule ? json_encode(array_intersect_key($rule, array_flip(
            ['name', 'issuer', 'min_amount', 'max_amount', 'terms', 'interest_method', 'interest_rate', 'fee_type', 'fee_value', 'fee_billing', 'effective_from', 'effective_until']
        )) + ['saved_at' => date('c')], JSON_UNESCAPED_UNICODE) : null,
    ]);
    foreach ($calc['rows'] as $row) {
        db_insert('installment_schedules', ['id' => new_uuid(), 'installment_plan_id' => $id] + $row);
    }

    return $id;
}

/** Terms in words, e.g. "12 × ₱1,750.00 · add-on 0.5%/month · fee ₱200 with the first". */
function installment_terms_text(array $plan): string
{
    $method = $plan['interest_method'] ?? null;
    $interest = match ($method) {
        null, '' => 'interest ' . format_money($plan['interest_amount']) . ' (entered)',
        'none' => 'no interest',
        'fixed_amount' => 'interest ' . format_money($plan['interest_amount']),
        'add_on_monthly' => 'add-on ' . rtrim(rtrim((string) $plan['interest_rate'], '0'), '.') . '% per month',
        'add_on_total' => 'add-on ' . rtrim(rtrim((string) $plan['interest_rate'], '0'), '.') . '% for the term',
        'reducing_balance' => rtrim(rtrim((string) $plan['interest_rate'], '0'), '.') . '% per month on the remaining balance',
        default => $method,
    };
    $fee = (float) $plan['fees_amount'] > 0 ? 'fee ' . format_money($plan['fees_amount']) . (($plan['fee_billing'] ?? '') === 'first' ? ' with the first' : '') : 'no fee';

    return $interest . ' · ' . $fee;
}

/* ------------------------------------------------------------------
 * Installment rules (offers of a bank or a card)
 * ------------------------------------------------------------------ */

/** Available terms of a rule, e.g. "3,6,12" -> [3, 6, 12]. */
function installment_rule_terms(array $rule): array
{
    return array_values(array_filter(array_map('intval', preg_split('/[^0-9]+/', (string) $rule['terms'])), static fn ($n) => $n > 0));
}

/** The signed-in owner's active rules that apply to a card (its own, or its bank's). */
function installment_rules_for_card(?string $cardId, ?string $onDate = null): array
{
    $card = $cardId ? db_row('SELECT id, issuer FROM credit_cards c WHERE c.id = :c AND ' . owner_scope('credit_cards', 'c'), [':c' => $cardId]) : null;
    $onDate ??= date('Y-m-d');
    $rules = db_all(
        'SELECT r.* FROM installment_rules r WHERE r.is_active = 1 AND ' . owner_scope('installment_rules', 'r') . '
            AND (r.effective_from IS NULL OR r.effective_from <= :d1) AND (r.effective_until IS NULL OR r.effective_until >= :d2)
          ORDER BY r.name',
        [':d1' => $onDate, ':d2' => $onDate]
    );

    return array_values(array_filter($rules, static fn ($r) => $card === null
        ? true
        : ($r['credit_card_id'] ? $r['credit_card_id'] === $card['id'] : ($r['issuer'] === null || same_bank($r['issuer'], $card['issuer'])))));
}

function installment_rule_find(string $id): ?array
{
    return is_valid_uuid($id) ? db_row('SELECT r.* FROM installment_rules r WHERE r.id = :id AND ' . owner_scope('installment_rules', 'r'), [':id' => $id]) : null;
}

/** Problems using $rule for this card, amount, term and date (null when it applies). */
function installment_rule_problem(array $rule, string $cardId, float $amount, int $months, string $date): ?string
{
    $applies = array_column(installment_rules_for_card($cardId, $date), 'id');
    if (!in_array($rule['id'], $applies, true)) {
        return 'The rule "' . $rule['name'] . '" does not apply to this card on ' . format_date($date) . '.';
    }
    if ($rule['min_amount'] !== null && $amount < (float) $rule['min_amount'] - 0.004) {
        return 'The rule "' . $rule['name'] . '" starts at ' . format_money($rule['min_amount']) . '.';
    }
    if ($rule['max_amount'] !== null && $amount > (float) $rule['max_amount'] + 0.004) {
        return 'The rule "' . $rule['name'] . '" goes up to ' . format_money($rule['max_amount']) . '.';
    }
    if (!in_array($months, installment_rule_terms($rule), true)) {
        return 'The rule "' . $rule['name'] . '" offers ' . implode(', ', installment_rule_terms($rule)) . ' months.';
    }

    return null;
}

const OBLIGATION_RATE_UPGRADE_MARKER = 'storage/upgrade_obligation_rate.done';

/**
 * Additive upgrade: borrower_obligations.agreed_rate, the interest agreed
 * with the borrower in % per month (NULL = interest entered as an amount).
 * See db/upgrade_obligation_rate.sql.
 */
function obligation_rate_upgrade(): void
{
    if (marker_exists(OBLIGATION_RATE_UPGRADE_MARKER)) {
        return;
    }
    db()->exec('ALTER TABLE borrower_obligations ADD COLUMN IF NOT EXISTS agreed_rate DECIMAL(8,4) NULL AFTER agreed_interest');
    marker_write(OBLIGATION_RATE_UPGRADE_MARKER);
}

/**
 * Months the agreed rate is charged for: the obligation's monthly
 * installments (a cash loan share: the loan's installments; otherwise one a
 * month from the obligation date to the due date), at least 1.
 */
function obligation_rate_months(array $o): int
{
    return max(1, count(obligation_monthly_dates($o + ['cash_loan_allocation_id' => null, 'obligation_date' => null, 'due_date' => null])));
}

/** Agreed interest from a rate: original amount x rate % per month x months. */
function obligation_rate_interest(float $original, float $rate, int $months): float
{
    return round($original * $rate / 100 * $months, 2);
}

/**
 * An obligation with an agreed rate whose months changed (its cash loan's
 * term): recalculate the interest, total due and outstanding. Never below
 * what was already paid. Call inside a transaction.
 */
function obligation_rate_refresh(string $obligationId): void
{
    $o = db_row('SELECT * FROM borrower_obligations WHERE id = :id FOR UPDATE', [':id' => $obligationId]);
    if (!$o || ($o['agreed_rate'] ?? null) === null) {
        return;
    }
    $interest = obligation_rate_interest((float) $o['original_amount'], (float) $o['agreed_rate'], obligation_rate_months($o));
    $total = round((float) $o['original_amount'] + $interest + (float) $o['agreed_fees'], 2);
    if (abs($interest - (float) $o['agreed_interest']) < 0.005 || $total + 0.004 < (float) $o['total_paid']) {
        return;
    }
    db_update('borrower_obligations', [
        'agreed_interest' => number_format($interest, 2, '.', ''), 'total_amount_due' => number_format($total, 2, '.', ''),
        'outstanding_amount' => number_format(max(0, $total - (float) $o['total_paid']), 2, '.', ''),
    ], $o['id']);
}
