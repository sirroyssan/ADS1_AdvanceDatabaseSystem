<?php
/**
 * Penalty engine - rule assignment, automatic monthly penalties, payment
 * allocation order, waiver and deletion.
 *
 * RULE ASSIGNMENT (by bank)
 *   A rule applies automatically only to obligations that come from a card of
 *   the rule's bank (penalty_rules.applies_to_issuer = credit_cards.issuer):
 *   that bank's purchases, installments, cash advances and cash loans. A rule
 *   with no bank is never applied automatically (manual "Apply penalty" only).
 *   Rules are resolved and logged when an obligation is created
 *   (penalty_assign_on_create) and shown on the obligation.
 *
 * CHANGES APPLY FROM THE DAY THEY ARE MADE
 *   A rule charges only on dates from its start: the later of effective_from
 *   and the day the rule was last saved (penalty_rules.updated_at). Earlier
 *   months are never back-filled, and charges already made keep the amount
 *   that was in effect when they were charged.
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
 * Bank (card issuer) an obligation comes from: its transaction's card or its
 * cash loan's card. Null for obligations entered without a card.
 */
function obligation_card_issuer(array $o): ?string
{
    if ($o['transaction_id']) {
        return db_value('SELECT c.issuer FROM transactions t JOIN credit_cards c ON c.id = t.credit_card_id WHERE t.id = :t', [':t' => $o['transaction_id']]) ?: null;
    }
    if ($o['cash_loan_allocation_id']) {
        return db_value(
            'SELECT c.issuer FROM cash_loan_allocations a JOIN cash_loans l ON l.id = a.cash_loan_id JOIN credit_cards c ON c.id = l.credit_card_id WHERE a.id = :a',
            [':a' => $o['cash_loan_allocation_id']]
        ) ?: null;
    }

    return null;
}

/**
 * Active rules of the obligation's bank (issuer compared without case/spaces),
 * from the rules of the borrower's owner (every user keeps their own rules).
 */
function penalty_rules_for_obligation(array $o): array
{
    $issuer = obligation_card_issuer($o);
    if ($issuer === null) {
        return [];
    }

    $rules = db_all(
        'SELECT r.*, GREATEST(COALESCE(r.effective_from, DATE(r.updated_at)), DATE(r.updated_at)) AS charges_from
           FROM penalty_rules r
          WHERE r.is_active = 1 AND r.applies_to_issuer IS NOT NULL
            AND r.owner_user_id = (SELECT b.owner_user_id FROM borrowers b WHERE b.id = :b)
          ORDER BY r.name',
        [':b' => $o['borrower_id']]
    );

    // Same bank list as the card's Issuer field; aliases match ("Unionbank" = "UnionBank").
    return array_values(array_filter($rules, static fn ($r) => same_bank($r['applies_to_issuer'], $issuer)));
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
    // Monthly cycle: first day after the grace period, then the same day every month.
    $first = (new DateTimeImmutable($dueDate))->modify('+' . ((int) $rule['grace_period_days'] + 1) . ' days')->format('Y-m-d');
    $from = $rule['charges_from'] ?? $rule['effective_from'] ?? $first;

    $dates = [];
    $once = strtolower((string) $rule['frequency']) === 'once';
    for ($i = 0; $i < PENALTY_MAX_PERIODS; $i++) {
        $date = schedule_due_date($first, $i, 'monthly');
        if ($date > $today || ($rule['effective_until'] && $date > $rule['effective_until'])) {
            break;
        }
        if ($date < $from) {
            // Before the rule (or its latest change) took effect: never back-filled.
            if ($once) {
                break;
            }
            continue;
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

    $rules = penalty_rules_for_obligation($o);
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
    db_update('borrower_obligations', ['overdue_amount' => number_format(obligation_overdue_amount($o, $today), 2, '.', '')], $o['id']);

    if ($created > 0) {
        // Done by the daily job for the borrower's owner, so it shows in that owner's log.
        log_activity('auto_penalty', 'obligation', $o['id'], 'Charged ' . $created . ' automatic penalt' . ($created === 1 ? 'y' : 'ies') . ' on ' . $o['obligation_number'],
            null, null, (string) (db_value('SELECT owner_user_id FROM borrowers WHERE id = :b', [':b' => $o['borrower_id']]) ?: SYSTEM_ACTOR));
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
    penalty_schema_upgrade();
    try {
        payment_selections_upgrade();
        statement_schema_upgrade();
        multiuser_upgrade();
        borrowed_schema_upgrade();
        schedule_sync_upgrade();
        obligation_refresh_overdue();
        schedule_refresh_statuses();
    } catch (PDOException $e) {
        error_log('[' . APP_NAME . '] Status refresh failed: ' . $e->getMessage());
    }
    $today = date('Y-m-d');
    if (marker_exists(PENALTY_RUN_MARKER, $today)) {
        return;
    }
    // The daily jobs cover every user's records: give them time on slower XAMPP machines.
    @set_time_limit(300);
    // Write the marker first so two simultaneous requests do not both run it.
    marker_write(PENALTY_RUN_MARKER, $today);

    try {
        [$checked, $created] = penalty_run_all($today);
        $created += borrowed_penalty_run_all($today);   // late repayments of borrowed card purchases
        borrowed_send_reminders($today);                 // repay-by reminders to borrowers
        if ($created > 0) {
            log_activity('penalty_run', 'system', null, 'Daily penalty check: ' . $created . ' new penalt' . ($created === 1 ? 'y' : 'ies') . ' on ' . $checked . ' open obligation(s)', null, null, SYSTEM_ACTOR);
        }
    } catch (Throwable $e) {
        @unlink(APP_ROOT . '/' . PENALTY_RUN_MARKER);   // try again on the next page load
        error_log('[' . APP_NAME . '] Daily penalty check failed: ' . $e->getMessage());
    }
    // Once a day: prepare each card's current and next statement from its records (idempotent).
    try {
        $prepared = statement_prepare_all($today);
        if ($prepared > 0) {
            log_activity('prepare_statements', 'system', null, 'Upcoming statements prepared or updated: ' . $prepared, null, null, SYSTEM_ACTOR);
        }
    } catch (Throwable $e) {
        error_log('[' . APP_NAME . '] Statement preparation failed: ' . $e->getMessage());
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
    $rules = penalty_rules_for_obligation($o);
    $issuer = obligation_card_issuer($o);
    log_activity('assign_penalty_rules', 'obligation', $o['id'],
        $rules ? 'Penalty rules assigned (' . $issuer . '): ' . implode('; ', array_map(static fn ($r) => $r['name'] . ' (' . penalty_rule_summary($r) . ')', $rules))
               : ($issuer ? 'No active penalty rule for ' . $issuer : 'No card linked, so no automatic penalty rule applies'));
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

/* ------------------------------------------------------------------
 * One-time upgrade (approved schema change): penalty_rules.applies_to_issuer
 * ------------------------------------------------------------------ */

const PENALTY_UPGRADE_MARKER = 'storage/upgrade_penalty_rule_bank.done';

/**
 * Add the rule's bank column if missing, guess each existing rule's bank from
 * its name (e.g. "BPI Late Payment" -> issuer "BPI"), then remove automatic
 * penalties that were charged wrongly (other bank, or before the rule took
 * effect) and have nothing paid. Each removal is logged with its reason.
 * Same change as db/upgrade_penalty_rule_bank.sql.
 */
function penalty_schema_upgrade(): void
{
    if (marker_exists(PENALTY_UPGRADE_MARKER)) {
        return;
    }
    $hasColumn = (int) db_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penalty_rules' AND COLUMN_NAME = 'applies_to_issuer'"
    ) > 0;

    if (!$hasColumn) {
        // DDL commits on its own; it must run outside a transaction.
        db()->exec('ALTER TABLE penalty_rules ADD COLUMN applies_to_issuer VARCHAR(150) NULL AFTER name');

        // Guess the bank from the rule name: the longest card issuer contained in it.
        $issuers = array_column(db_all("SELECT DISTINCT TRIM(issuer) AS issuer FROM credit_cards WHERE TRIM(issuer) <> ''"), 'issuer');
        usort($issuers, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach (db_all('SELECT id, name FROM penalty_rules') as $rule) {
            foreach ($issuers as $issuer) {
                if (preg_match('/\b' . preg_quote(mb_strtolower($issuer), '/') . '\b/u', mb_strtolower($rule['name']))) {
                    db_update('penalty_rules', ['applies_to_issuer' => $issuer], $rule['id']);
                    log_activity('update', 'penalty_rule', $rule['id'], 'Bank set to ' . $issuer . ' from the rule name (upgrade)', null, ['applies_to_issuer' => $issuer]);
                    break;
                }
            }
        }

        // Remove wrong automatic charges that nothing was paid on.
        db_transaction(static function () {
            $rows = db_all(
                "SELECT ap.*, r.name AS rule_name, r.applies_to_issuer,
                        GREATEST(COALESCE(r.effective_from, DATE(r.updated_at)), DATE(r.updated_at)) AS charges_from,
                        o.obligation_number, o.transaction_id, o.cash_loan_allocation_id
                   FROM applied_penalties ap
                   JOIN penalty_rules r ON r.id = ap.penalty_rule_id
                   JOIN borrower_obligations o ON o.id = ap.obligation_id
                  WHERE ap.notes LIKE 'Automatic %' AND ap.paid_amount = 0
                  FOR UPDATE"
            );
            foreach ($rows as $p) {
                $issuer = obligation_card_issuer($p);
                if ($p['applies_to_issuer'] === null) {
                    $why = 'rule "' . $p['rule_name'] . '" has no bank set';
                } elseif ($issuer === null || !same_bank($issuer, $p['applies_to_issuer'])) {
                    $why = 'rule "' . $p['rule_name'] . '" is for ' . $p['applies_to_issuer'] . ', but ' . $p['obligation_number'] . ' is ' . ($issuer ? 'from ' . $issuer : 'not linked to a card');
                } elseif ($p['applied_date'] < $p['charges_from']) {
                    $why = 'charged for ' . format_date($p['applied_date']) . ', before the rule took effect';
                } else {
                    continue;
                }
                db_exec('DELETE FROM applied_penalties WHERE id = :id', [':id' => $p['id']]);
                log_activity('delete_penalty', 'obligation', $p['obligation_id'],
                    'Removed automatic penalty "' . $p['rule_name'] . '" of ' . format_money($p['amount']) . ' (' . format_date($p['applied_date']) . '): ' . $why . ' (upgrade)',
                    $p + ['penalty_key' => penalty_key($p['obligation_id'], $p['penalty_rule_id'], $p['installment_schedule_id'], $p['applied_date'])],
                    ['reason' => 'Upgrade: penalties apply only to the rule\'s bank and only from the day the rule takes effect']);
            }
        });
    }

    marker_write(PENALTY_UPGRADE_MARKER);
}

/* ------------------------------------------------------------------
 * Totals with penalties: Outstanding + unpaid penalties = Total to pay.
 * Used by the dashboard, obligations, borrowers, reports and the portal.
 * ------------------------------------------------------------------ */

/**
 * LEFT JOIN adding pen.penalties_unpaid / pen.penalties_charged / pen.penalties_paid
 * for obligation alias $o.
 */
function penalty_totals_join(string $o = 'o', string $alias = 'pen'): string
{
    return "LEFT JOIN (SELECT obligation_id, SUM(remaining_amount) AS penalties_unpaid, SUM(amount) AS penalties_charged, SUM(paid_amount) AS penalties_paid
                         FROM applied_penalties GROUP BY obligation_id) $alias ON $alias.obligation_id = $o.id";
}

/**
 * One row per borrower: outstanding, penalties (unpaid), total_due, overdue, open_items.
 * Cancelled and written-off obligations are not counted.
 */
function borrower_totals_sql(): string
{
    return "SELECT o.borrower_id,
                   COALESCE(SUM(o.outstanding_amount), 0) AS outstanding,
                   COALESCE(SUM(pen.penalties_unpaid), 0) AS penalties,
                   COALESCE(SUM(o.outstanding_amount), 0) + COALESCE(SUM(pen.penalties_unpaid), 0) AS total_due,
                   COALESCE(SUM(o.overdue_amount), 0) AS overdue,
                   SUM(o.outstanding_amount > 0 OR COALESCE(pen.penalties_unpaid, 0) > 0) AS open_items
              FROM borrower_obligations o " . penalty_totals_join() . "
             WHERE o.status NOT IN ('cancelled', 'written_off')
             GROUP BY o.borrower_id";
}

/**
 * Grand totals across the signed-in user's borrowers (or one): outstanding, penalties, total_due.
 */
function collection_totals(?string $borrowerId = null): array
{
    $row = db_row(
        "SELECT COALESCE(SUM(o.outstanding_amount), 0) AS outstanding,
                COALESCE(SUM(pen.penalties_unpaid), 0) AS penalties,
                COALESCE(SUM(pen.penalties_charged), 0) AS penalties_charged,
                COALESCE(SUM(pen.penalties_paid), 0) AS penalties_paid
           FROM borrower_obligations o " . penalty_totals_join() . "
          WHERE o.status NOT IN ('cancelled', 'written_off') AND " . ($borrowerId ? 'o.borrower_id = :b' : owner_scope('borrower_obligations', 'o')),
        $borrowerId ? [':b' => $borrowerId] : []
    );
    $row['total_due'] = round((float) $row['outstanding'] + (float) $row['penalties'], 2);

    return $row;
}
