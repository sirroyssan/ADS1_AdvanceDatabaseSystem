<?php
/**
 * Penalty engine - rule assignment, automatic monthly penalties, payment
 * allocation order, waiver and deletion.
 *
 * RULE ASSIGNMENT
 *   The schema links a rule to an obligation only through applied_penalties,
 *   so an obligation's rules are the ACTIVE rules that are in effect on the
 *   obligation date (effective_from / effective_until). They are resolved and
 *   logged the moment an obligation is created from a transaction, cash loan
 *   or installment (penalty_assign_on_create) and shown on the obligation.
 *
 * AUTOMATIC MONTHLY PENALTIES
 *   For every open obligation that is past due (plus the rule's grace days),
 *   one penalty per rule is charged on the first day after the grace period
 *   and then every month on the same day (frequency "once" charges only the
 *   first). Obligations from an installment purchase are charged per overdue
 *   installment (applied_penalties.installment_schedule_id). Each charge is
 *   identified by obligation + rule + installment + date, so running the
 *   check again never charges twice. The check runs once a day on the first
 *   page load (penalty_run_if_due) and can be run from cron:
 *   php db/run_penalties.php
 *
 * PAYMENT ALLOCATION ORDER (payment_allocate)
 *   1. unpaid penalties, oldest first   (allocation_type "penalty")
 *   2. interest and fees of the obligation (agreed_interest + agreed_fees) ("interest")
 *   3. principal                          ("principal")
 *
 * WAIVE / DELETE (owners and administrators only, reason required)
 *   Waive sets the remaining amount to zero and keeps the row with the
 *   reason in its notes. Delete removes an unpaid penalty; the reason and the
 *   penalty's key are written to activity_logs, so the monthly check does not
 *   charge that period again.
 */

defined('APP_ROOT') or exit('No direct access.');

require_once APP_ROOT . '/pages/penalty_rules/penalty_rule_functions.php';

const PENALTY_REASON_MIN = 10;
const PENALTY_MAX_PERIODS = 120;
const PENALTY_RUN_MARKER = 'storage/penalty_last_run.txt';

/**
 * Active rules in effect on a date.
 */
function penalty_rules_on(string $date): array
{
    return db_all(
        'SELECT * FROM penalty_rules
          WHERE is_active = 1
            AND (effective_from IS NULL OR effective_from <= :d1)
            AND (effective_until IS NULL OR effective_until >= :d2)
          ORDER BY name',
        [':d1' => $date, ':d2' => $date]
    );
}

/**
 * Plain-language description of a rule, e.g. "3% of outstanding amount, monthly, after 5 grace days, max ₱500.00".
 */
function penalty_rule_summary(array $rule): string
{
    $how = $rule['calculation_type'] === 'percentage'
        ? rtrim(rtrim(number_format((float) $rule['rate'], 4), '0'), '.') . '% of ' . humanize($rule['basis'])
        : format_money($rule['fixed_amount']);
    $frequency = strtolower((string) $rule['frequency']) === 'once' ? 'once' : 'every month';

    return $how . ', ' . $frequency
        . ((int) $rule['grace_period_days'] > 0 ? ', after ' . (int) $rule['grace_period_days'] . ' grace day(s)' : '')
        . ($rule['maximum_charge'] !== null ? ', max ' . format_money($rule['maximum_charge']) . ' per charge' : '');
}

/**
 * Dates a rule charges for one due date, up to $today.
 *
 * @return string[]
 */
function penalty_charge_dates(string $dueDate, array $rule, string $today): array
{
    $first = (new DateTimeImmutable($dueDate))->modify('+' . ((int) $rule['grace_period_days'] + 1) . ' days')->format('Y-m-d');
    if ($rule['effective_from'] && $first < $rule['effective_from']) {
        $first = $rule['effective_from'];
    }

    $dates = [];
    $once = strtolower((string) $rule['frequency']) === 'once';
    for ($i = 0; $i < PENALTY_MAX_PERIODS; $i++) {
        $date = schedule_due_date($first, $i, 'monthly');
        if ($date > $today || ($rule['effective_until'] && $date > $rule['effective_until'])) {
            break;
        }
        $dates[] = $date;
        if ($once) {
            break;
        }
    }

    return $dates;
}

/**
 * Amount a rule's basis points to.
 */
function penalty_base(string $basis, float $outstanding, float $totalDue, float $original): float
{
    $basis = strtolower($basis);

    return match (true) {
        str_contains($basis, 'original')  => $original,
        str_contains($basis, 'total')     => $totalDue,
        default                           => $outstanding, // outstanding_amount, overdue_amount, remaining…
    };
}

function penalty_key(string $obligationId, string $ruleId, ?string $scheduleId, string $date): string
{
    return $obligationId . '|' . $ruleId . '|' . ($scheduleId ?? '-') . '|' . $date;
}

/**
 * Overdue targets of an obligation: its overdue installments when it is the
 * only obligation of an installment purchase, otherwise the obligation itself.
 *
 * @return array<int, array{schedule_id: ?string, due_date: string, outstanding: float, total: float, original: float}>
 */
function penalty_targets(array $o, string $today): array
{
    if ($o['transaction_id'] && (int) db_value(
        "SELECT COUNT(*) FROM borrower_obligations WHERE transaction_id = :t AND status <> 'cancelled'", [':t' => $o['transaction_id']]
    ) === 1) {
        $rows = db_all(
            "SELECT s.id, s.due_date, s.remaining_amount, s.amount_due, s.principal_amount
               FROM installment_schedules s JOIN installment_plans p ON p.id = s.installment_plan_id
              WHERE p.transaction_id = :t AND p.status <> 'cancelled'
                AND s.status NOT IN ('paid', 'waived', 'cancelled') AND s.remaining_amount > 0 AND s.due_date < :today
              ORDER BY s.installment_number",
            [':t' => $o['transaction_id'], ':today' => $today]
        );
        $hasPlan = (int) db_value("SELECT COUNT(*) FROM installment_plans WHERE transaction_id = :t AND status <> 'cancelled'", [':t' => $o['transaction_id']]) > 0;
        if ($hasPlan) {
            return array_map(static fn ($r) => [
                'schedule_id' => $r['id'], 'due_date' => $r['due_date'], 'outstanding' => (float) $r['remaining_amount'],
                'total' => (float) $r['amount_due'], 'original' => (float) $r['principal_amount'],
            ], $rows);
        }
    }

    if (!$o['due_date'] || $o['due_date'] >= $today) {
        return [];
    }

    return [[
        'schedule_id' => null, 'due_date' => $o['due_date'], 'outstanding' => (float) $o['outstanding_amount'],
        'total' => (float) $o['total_amount_due'], 'original' => (float) $o['original_amount'],
    ]];
}

/**
 * Charge every missing penalty of one obligation. Returns the number of new penalties.
 */
function penalty_run_obligation(array $o, ?string $today = null): int
{
    $today ??= date('Y-m-d');
    if ((float) $o['outstanding_amount'] <= 0.004 || in_array($o['status'], ['cancelled', 'paid', 'written_off'], true)) {
        return 0;
    }

    $rules = penalty_rules_on($o['obligation_date']);
    $targets = penalty_targets($o, $today);
    if (!$rules || !$targets) {
        return 0;
    }

    $existing = [];
    foreach (db_all('SELECT penalty_rule_id, installment_schedule_id, applied_date FROM applied_penalties WHERE obligation_id = :o', [':o' => $o['id']]) as $p) {
        $existing[penalty_key($o['id'], $p['penalty_rule_id'], $p['installment_schedule_id'], $p['applied_date'])] = true;
    }
    foreach (db_all(
        "SELECT JSON_UNQUOTE(JSON_EXTRACT(old_values, '$.penalty_key')) AS k FROM activity_logs WHERE action = 'delete_penalty' AND entity_id = :o",
        [':o' => $o['id']]
    ) as $deleted) {
        $existing[$deleted['k']] = true;
    }

    $created = 0;
    foreach ($rules as $rule) {
        foreach ($targets as $t) {
            $base = penalty_base($rule['basis'], $t['outstanding'], $t['total'], $t['original']);
            if ($base <= 0.004 && $rule['calculation_type'] === 'percentage') {
                continue;
            }
            foreach (penalty_charge_dates($t['due_date'], $rule, $today) as $n => $date) {
                $key = penalty_key($o['id'], $rule['id'], $t['schedule_id'], $date);
                if (isset($existing[$key])) {
                    continue;
                }
                $amount = number_format(penalty_rule_amount($rule, $base), 2, '.', '');
                if ((float) $amount <= 0) {
                    continue;
                }
                db_insert('applied_penalties', [
                    'id'                      => new_uuid(),
                    'obligation_id'           => $o['id'],
                    'installment_schedule_id' => $t['schedule_id'],
                    'penalty_rule_id'         => $rule['id'],
                    'applied_date'            => $date,
                    'overdue_days'            => (int) ((strtotime($date) - strtotime($t['due_date'])) / 86400),
                    'calculation_base'        => number_format($base, 2, '.', ''),
                    'amount'                  => $amount,
                    'paid_amount'             => '0.00',
                    'remaining_amount'        => $amount,
                    'notes'                   => 'Automatic ' . (strtolower((string) $rule['frequency']) === 'once' ? 'one-time' : 'monthly') . ' penalty #' . ($n + 1)
                                                 . ' for ' . ($t['schedule_id'] ? 'an overdue installment' : 'the overdue balance') . ' due ' . format_date($t['due_date']) . '.',
                ]);
                $existing[$key] = true;
                $created++;
            }
        }
    }

    // Keep the obligation's overdue amount current.
    $overdue = $o['due_date'] && $o['due_date'] < $today ? (float) $o['outstanding_amount'] : array_sum(array_column($targets, 'outstanding'));
    db_update('borrower_obligations', ['overdue_amount' => number_format(min($overdue, (float) $o['outstanding_amount']), 2, '.', '')], $o['id']);

    if ($created > 0) {
        log_activity('auto_penalty', 'obligation', $o['id'], 'Charged ' . $created . ' automatic penalt' . ($created === 1 ? 'y' : 'ies') . ' on ' . $o['obligation_number']);
        notify_borrower($o['borrower_id'], 'penalty_charged', 'Late charge added: ' . $o['obligation_number'],
            'A late charge was added because ' . $o['obligation_number'] . ' is past due. Please pay as soon as possible.', 'obligation', $o['id']);
    }

    return $created;
}

/**
 * Run the check for every open obligation. Returns [obligations checked, penalties created].
 */
function penalty_run_all(?string $today = null): array
{
    $today ??= date('Y-m-d');
    $open = db_all(
        "SELECT * FROM borrower_obligations
          WHERE outstanding_amount > 0 AND status NOT IN ('cancelled', 'paid', 'written_off')
            AND (due_date < :today OR transaction_id IS NOT NULL)",
        [':today' => $today]
    );

    $created = 0;
    foreach ($open as $o) {
        $created += db_transaction(static fn () => penalty_run_obligation($o, $today));
    }

    return [count($open), $created];
}

/**
 * Once a day, on the first page load: run the monthly penalty check.
 */
function penalty_run_if_due(): void
{
    $marker = APP_ROOT . '/' . PENALTY_RUN_MARKER;
    $today = date('Y-m-d');
    if (is_file($marker) && trim((string) @file_get_contents($marker)) === $today) {
        return;
    }
    // Write the marker first so two simultaneous requests do not both run it.
    if (@file_put_contents($marker, $today, LOCK_EX) === false) {
        error_log('[' . APP_NAME . '] Cannot write ' . PENALTY_RUN_MARKER . '; the daily penalty check runs on every request.');
    }

    try {
        [$checked, $created] = penalty_run_all($today);
        if ($created > 0) {
            log_activity('penalty_run', 'system', null, 'Daily penalty check: ' . $created . ' new penalt' . ($created === 1 ? 'y' : 'ies') . ' on ' . $checked . ' open obligation(s)');
        }
    } catch (Throwable $e) {
        @unlink($marker);
        error_log('[' . APP_NAME . '] Daily penalty check failed: ' . $e->getMessage());
    }
}

/**
 * Right after an obligation is created: log which rules govern it and charge
 * anything already overdue (a back-dated obligation).
 */
function penalty_assign_on_create(string $obligationId): void
{
    $o = db_row('SELECT * FROM borrower_obligations WHERE id = :id', [':id' => $obligationId]);
    if (!$o) {
        return;
    }
    $rules = penalty_rules_on($o['obligation_date']);
    log_activity('assign_penalty_rules', 'obligation', $o['id'],
        $rules ? 'Penalty rules assigned: ' . implode('; ', array_map(static fn ($r) => $r['name'] . ' (' . penalty_rule_summary($r) . ')', $rules))
               : 'No active penalty rule in effect on ' . format_date($o['obligation_date']));
    penalty_run_obligation($o);
}

/**
 * Unpaid penalties of an obligation.
 */
function penalty_unpaid_total(string $obligationId): float
{
    return (float) db_value('SELECT COALESCE(SUM(remaining_amount), 0) FROM applied_penalties WHERE obligation_id = :o', [':o' => $obligationId]);
}

/**
 * Interest/fees still unpaid on an obligation (payments cover interest before principal).
 */
function obligation_interest_remaining(array $o): float
{
    $interestDue = (float) $o['agreed_interest'] + (float) $o['agreed_fees'];
    $principalPaid = (float) db_value(
        "SELECT COALESCE(SUM(allocated_amount), 0) FROM borrower_payment_allocations WHERE obligation_id = :o AND allocation_type = 'principal'",
        [':o' => $o['id']]
    );
    $interestPaid = min($interestDue, max(0, (float) $o['total_paid'] - $principalPaid));

    return round(min($interestDue - $interestPaid, (float) $o['outstanding_amount']), 2);
}

/**
 * How an amount would be split on an obligation: [penalty, interest, principal].
 */
function allocation_split(array $o, float $amount): array
{
    $penalty = min($amount, penalty_unpaid_total($o['id']));
    $left = $amount - $penalty;
    $interest = min($left, obligation_interest_remaining($o));
    $principal = min($left - $interest, (float) $o['outstanding_amount'] - $interest);

    return [round($penalty, 2), round($interest, 2), round(max(0, $principal), 2)];
}

/**
 * Waive (keep the row, remaining → 0) or delete (remove an unpaid penalty).
 * Owners/administrators only; the caller checks the role. Returns an error or null.
 */
function penalty_terminate(string $penaltyId, string $obligationId, string $mode, string $reason, array $user): ?string
{
    $reason = trim($reason);
    if (mb_strlen($reason) < PENALTY_REASON_MIN) {
        return 'Enter a reason of at least ' . PENALTY_REASON_MIN . ' characters (e.g. "Cash payment received offline but not yet recorded").';
    }
    if (!user_is_admin($user)) {
        return 'Only owners and administrators can waive or delete penalties.';
    }

    return db_transaction(function () use ($penaltyId, $obligationId, $mode, $reason, $user) {
        $p = db_row('SELECT ap.*, r.name AS rule_name FROM applied_penalties ap JOIN penalty_rules r ON r.id = ap.penalty_rule_id
                      WHERE ap.id = :id AND ap.obligation_id = :o FOR UPDATE', [':id' => $penaltyId, ':o' => $obligationId]);
        if (!$p) {
            return 'Penalty not found.';
        }
        $who = user_display_name($user);
        $stamp = date('Y-m-d H:i');

        if ($mode === 'waive') {
            if ((float) $p['remaining_amount'] <= 0.004) {
                return 'This penalty has nothing left to waive.';
            }
            db_update('applied_penalties', [
                'remaining_amount' => '0.00',
                'notes'            => trim(($p['notes'] ? $p['notes'] . "\n" : '') . 'WAIVED ' . format_money($p['remaining_amount']) . ' by ' . $who . ' on ' . $stamp . ': ' . $reason),
            ], $p['id']);
            log_activity('waive_penalty', 'obligation', $obligationId, 'Waived ' . format_money($p['remaining_amount']) . ' of "' . $p['rule_name'] . '" (' . format_date($p['applied_date']) . '): ' . $reason,
                ['remaining_amount' => $p['remaining_amount']], ['remaining_amount' => '0.00', 'reason' => $reason]);
        } elseif ($mode === 'delete') {
            if ((float) $p['paid_amount'] > 0.004) {
                return 'Part of this penalty was already paid, so it cannot be deleted. Waive the rest instead.';
            }
            db_exec('DELETE FROM applied_penalties WHERE id = :id', [':id' => $p['id']]);
            log_activity('delete_penalty', 'obligation', $obligationId, 'Deleted penalty "' . $p['rule_name'] . '" of ' . format_money($p['amount']) . ' (' . format_date($p['applied_date']) . '): ' . $reason,
                $p + ['penalty_key' => penalty_key($obligationId, $p['penalty_rule_id'], $p['installment_schedule_id'], $p['applied_date'])], ['reason' => $reason]);
        } else {
            return 'Unknown action.';
        }

        // An obligation with nothing left (balance and penalties) is paid.
        $o = db_row('SELECT * FROM borrower_obligations WHERE id = :id FOR UPDATE', [':id' => $obligationId]);
        if ($o && (float) $o['outstanding_amount'] <= 0.004 && penalty_unpaid_total($obligationId) <= 0.004 && !in_array($o['status'], ['cancelled', 'written_off', 'paid'], true)) {
            db_update('borrower_obligations', ['status' => 'paid', 'completed_at' => date('Y-m-d H:i:s')], $obligationId);
        }

        return null;
    });
}
