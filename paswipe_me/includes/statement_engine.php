<?php
/**
 * Credit card statements (version 2).
 *
 * A statement page shows, for each card and billing period, the records that
 * are already in the database (by their own dates, using the card's cutoff
 * day). Nothing is generated or written while pages are shown. A statement
 * ROW (credit_card_statements) exists only when the bank's actual SOA was
 * recorded for that period: SOA amount (total_amount_due), minimum amount due,
 * payment due date and notes - exactly as entered, never recalculated.
 *
 * Billing period of a date
 *   1. a recorded SOA whose period contains it (its own start and cutoff);
 *   2. a special billing cycle entered on the card;
 *   3. otherwise the card's cutoff day: day after the previous cutoff .. cutoff
 *      (calendar month when the card has no cutoff day),
 *   clipped so it never overlaps a recorded SOA.
 *
 * Payment status of a recorded SOA (payments linked with statement_id):
 *   Paid            payments cover the SOA amount
 *   Overdue         due date passed and something is still unpaid
 *   Partially paid  some payment, something still unpaid
 *   Pending         nothing paid yet
 *   Unrecorded SOA  (a period without a statement row)
 */

defined('APP_ROOT') or exit('No direct access.');

const STATEMENT_UPGRADE_MARKER = 'storage/upgrade_statements.done';
const STATEMENT_UPGRADE_V2_MARKER = 'storage/upgrade_statements_v2.done';
const STATEMENT_UPGRADE_V3_MARKER = 'storage/upgrade_statements_v3.done';
const STATEMENT_UPGRADE_V4_MARKER = 'storage/upgrade_statements_v4.done';
/** Installments billed by billing period (not due date): prepared statements are rebuilt once. */
const STATEMENT_UPGRADE_V5_MARKER = 'storage/upgrade_statements_v5.done';
/** Days ahead that count as an upcoming payment. */
const STATEMENT_UPCOMING_DAYS = 7;

/** Record groups in a period, in display order. */
const STATEMENT_PARTS = [
    'purchases'           => 'Purchases',
    'installment_charges' => 'Installment charges',
    'cash_advances'       => 'Cash advances',
    'interest'            => 'Interest',
    'finance_charges'     => 'Finance charges',
    'late_fees'           => 'Late fees',
    'other_charges'       => 'Annual and other fees',
    'payments'            => 'Payments',
    'credits'             => 'Credits / refunds',
];

/** Parts that lower the balance. */
const STATEMENT_CREDIT_PARTS = ['payments', 'credits'];

const STATEMENT_PAY_STATUSES = [
    'prepared'       => 'Prepared · SOA not recorded',
    'unrecorded'     => 'Unrecorded SOA',
    'pending'        => 'Pending',
    'partially_paid' => 'Partially paid',
    'paid'           => 'Paid',
    'overdue'        => 'Overdue',
];

/* ------------------------------------------------------------------
 * Schema
 * ------------------------------------------------------------------ */

function run_sql_file(string $file): void
{
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        db()->exec($statement);
    }
}

/** Upgrade 1 (columns for card billing rules and statements) and upgrade 2. */
function statement_schema_upgrade(): void
{
    if (!marker_exists(STATEMENT_UPGRADE_MARKER)) {
        run_sql_file(APP_ROOT . '/db/upgrade_statements.sql');
        marker_write(STATEMENT_UPGRADE_MARKER);
    }
    if (!marker_exists(STATEMENT_UPGRADE_V2_MARKER)) {
        statement_schema_upgrade_v2();
        marker_write(STATEMENT_UPGRADE_V2_MARKER);
    }
    if (!marker_exists(STATEMENT_UPGRADE_V3_MARKER)) {
        run_sql_file(APP_ROOT . '/db/upgrade_statements_v3.sql');
        // Every statement that exists before this upgrade is a recorded SOA.
        db()->exec('UPDATE credit_card_statements SET soa_recorded_at = created_at WHERE soa_recorded_at IS NULL AND prepared_at IS NULL');
        marker_write(STATEMENT_UPGRADE_V3_MARKER);
    }
    if (!marker_exists(STATEMENT_UPGRADE_V4_MARKER)) {
        run_sql_file(APP_ROOT . '/db/upgrade_statements_v4.sql');
        marker_write(STATEMENT_UPGRADE_V4_MARKER);
    }
    if (!marker_exists(STATEMENT_UPGRADE_V5_MARKER)) {
        marker_write(STATEMENT_UPGRADE_V5_MARKER);   // first: a failure is retried by the daily run, never in a loop
        $rebuilt = statement_prepare_all();
        log_activity('statements_upgrade', 'system', null, 'Installments are now placed on statements by billing period (from the transaction date), not by due date. Prepared statements rebuilt: ' . $rebuilt, null, null, SYSTEM_ACTOR);
    }
}

/**
 * Upgrade 2: statements are only what you recorded from the bank.
 *  - generated statements with an SOA you entered become that SOA (your figures);
 *  - generated statements you edited or linked to something are kept as records;
 *  - generated statements nobody touched are removed (they held no bank data)
 *    together with the billing cycles created for them.
 */
function statement_schema_upgrade_v2(): void
{
    db()->exec('ALTER TABLE credit_card_statements ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER minimum_due_status');

    $converted = 0;
    $kept = 0;
    $removed = 0;
    db_transaction(static function () use (&$converted, &$kept, &$removed) {
        foreach (db_all("SELECT * FROM credit_card_statements WHERE source = 'generated'") as $s) {
            $soa = db_row('SELECT * FROM statement_reconciliations WHERE statement_id = :s ORDER BY created_at DESC, id DESC LIMIT 1', [':s' => $s['id']]);
            $linked = (int) db_value(
                'SELECT (SELECT COUNT(*) FROM credit_card_payments WHERE statement_id = :a) + (SELECT COUNT(*) FROM bank_charges WHERE statement_id = :b)
                      + (SELECT COUNT(*) FROM cash_advances WHERE statement_id = :c)',
                [':a' => $s['id'], ':b' => $s['id'], ':c' => $s['id']]
            );
            $touched = $s['statement_number'] !== null || $s['attachment_document_id'] !== null || (float) $s['adjustments'] != 0.0
                || $s['due_date_status'] === 'manual';
            if ($soa) {
                db_update('credit_card_statements', [
                    'source' => 'manual', 'total_amount_due' => $soa['bank_statement_balance'],
                    'minimum_amount_due' => $soa['bank_minimum_due'] ?? $s['minimum_amount_due'], 'minimum_due_status' => 'manual', 'due_date_status' => 'manual',
                    'notes' => trim('SOA entered ' . substr($soa['created_at'], 0, 10) . '. ' . ($soa['notes'] ?? '')),
                ], $s['id']);
                $converted++;
            } elseif ($linked > 0 || $touched) {
                db_update('credit_card_statements', [
                    'source' => 'manual', 'minimum_due_status' => 'manual', 'due_date_status' => 'manual',
                    'notes' => 'Kept from the earlier automatic statements: the amount was calculated, not taken from the bank SOA. Check it against the SOA.',
                ], $s['id']);
                $kept++;
            } else {
                db_exec('DELETE FROM credit_card_statements WHERE id = :id', [':id' => $s['id']]);
                db_exec('DELETE FROM card_billing_cycles WHERE credit_card_id = :c AND cycle_start_date = :s AND cycle_end_date = :e',
                    [':c' => $s['credit_card_id'], ':s' => $s['period_start_date'], ':e' => $s['cutoff_date']]);
                $removed++;
            }
        }
    });
    if ($converted + $kept + $removed > 0) {
        log_activity('statements_upgrade', 'system', null, 'Statements now hold only bank SOA figures: ' . $converted . ' converted to their recorded SOA, '
            . $kept . ' kept (edited or linked), ' . $removed . ' generated statement(s) without any input removed', null, null, SYSTEM_ACTOR);
    }

    try {
        run_sql_file(APP_ROOT . '/db/upgrade_statements_v2.sql');
    } catch (PDOException $e) {
        // Most likely two older hand-entered statements share a cutoff date: keep them, report it.
        log_activity('statements_upgrade', 'system', null, 'Index not added: ' . $e->getMessage() . ' — duplicate SOA periods are still prevented by validation.', null, null, SYSTEM_ACTOR);
        error_log('[' . APP_NAME . '] statements upgrade 2: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------
 * Dates and card rules
 * ------------------------------------------------------------------ */

/** $day in the month of $date (clamped to the month's last day). */
function day_in_month(int $day, string $date): string
{
    $d = new DateTimeImmutable($date);

    return $d->setDate((int) $d->format('Y'), (int) $d->format('n'), min($day, (int) $d->format('t')))->format('Y-m-d');
}

/** First cutoff date on or after $date. */
function cutoff_on_or_after(int $day, string $date): string
{
    $candidate = day_in_month($day, $date);
    if ($candidate >= $date) {
        return $candidate;
    }

    return day_in_month($day, (new DateTimeImmutable($date))->modify('first day of next month')->format('Y-m-d'));
}

/** Last cutoff date before $date. */
function cutoff_before(int $day, string $date): string
{
    $candidate = day_in_month($day, $date);
    if ($candidate < $date) {
        return $candidate;
    }

    return day_in_month($day, (new DateTimeImmutable($date))->modify('first day of previous month')->format('Y-m-d'));
}

function date_add_days(string $date, int $days): string
{
    return (new DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

/**
 * Payment due date suggested by the card's rule: [date, known?]. Only a
 * suggestion for the SOA form - the date printed on the SOA is what is saved.
 */
function card_due_date(array $card, string $cutoff): array
{
    $rule = $card['due_date_rule'] ?? null;
    if ($rule === 'days_after_cutoff' && (int) $card['due_days_after_cutoff'] > 0) {
        return [date_add_days($cutoff, (int) $card['due_days_after_cutoff']), true];
    }
    if (($rule === 'day_of_month' || $rule === null) && (int) $card['payment_due_day'] > 0) {
        return [cutoff_on_or_after((int) $card['payment_due_day'], date_add_days($cutoff, 1)), true];
    }

    return [null, false];
}

function card_has_minimum_rule(array $card): bool
{
    return ($card['minimum_due_percent'] ?? null) !== null || ($card['minimum_due_floor'] ?? null) !== null;
}

function card_billing_summary(array $card): string
{
    if (!$card['billing_cycle_day']) {
        return 'No statement cutoff day set — periods follow calendar months.';
    }
    [, $known] = card_due_date($card, date('Y-m-d'));
    $due = match (true) {
        !$known => 'due date rule not set',
        ($card['due_date_rule'] ?? '') === 'days_after_cutoff' => 'due ' . (int) $card['due_days_after_cutoff'] . ' days after cutoff',
        default => 'due every ' . ordinal((int) $card['payment_due_day']),
    };

    return 'Cutoff every ' . ordinal((int) $card['billing_cycle_day']) . ' · ' . $due;
}

function ordinal(int $n): string
{
    $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

    return $n . $suffix;
}

/* ------------------------------------------------------------------
 * Billing periods (computed, nothing stored)
 * ------------------------------------------------------------------ */

/**
 * Everything needed to work out a card's periods, loaded once:
 * the card, its recorded SOAs (with a start date) and special cycles.
 */
function card_period_context(array $card): array
{
    $soas = db_all(
        'SELECT id, period_start_date, cutoff_date, statement_date, soa_recorded_at FROM credit_card_statements
          WHERE credit_card_id = :c ORDER BY COALESCE(cutoff_date, statement_date)',
        [':c' => $card['id']]
    );
    $previousEnd = null;
    foreach ($soas as $i => $s) {
        $end = $s['cutoff_date'] ?: $s['statement_date'];
        $start = $s['period_start_date'];
        if (!$start) {
            $start = $previousEnd ? date_add_days($previousEnd, 1)
                : ($card['billing_cycle_day'] ? date_add_days(cutoff_before((int) $card['billing_cycle_day'], $end), 1) : substr($end, 0, 8) . '01');
        }
        $soas[$i] = ['id' => $s['id'], 'start' => $start, 'end' => $end, 'recorded' => $s['soa_recorded_at'] !== null];
        $previousEnd = $end;
    }
    // A prepared statement that is not closed yet follows the card's current rules (it moves if the
    // cutoff day changes); recorded SOAs and closed periods keep their own dates.
    $today = date('Y-m-d');
    $soas = array_values(array_filter($soas, static fn ($x) => $x['recorded'] || $x['end'] < $today));
    $cycles = db_all('SELECT cycle_start_date AS start, cycle_end_date AS end FROM card_billing_cycles WHERE credit_card_id = :c', [':c' => $card['id']]);

    return ['card' => $card, 'soas' => $soas, 'cycles' => $cycles];
}

/** The billing period containing $date: ['start', 'end', 'statement_id'|null]. */
function card_period_containing(array $ctx, string $date): array
{
    foreach ($ctx['soas'] as $s) {
        if ($date >= $s['start'] && $date <= $s['end']) {
            return ['start' => $s['start'], 'end' => $s['end'], 'statement_id' => $s['id'], 'recorded' => $s['recorded']];
        }
    }
    $start = null;
    $end = null;
    foreach ($ctx['cycles'] as $c) {
        if ($date >= $c['start'] && $date <= $c['end']) {
            [$start, $end] = [$c['start'], $c['end']];
        }
    }
    if ($start === null) {
        $day = (int) $ctx['card']['billing_cycle_day'];
        if ($day > 0) {
            $end = cutoff_on_or_after($day, $date);
            $start = date_add_days(cutoff_before($day, $end), 1);
        } else {
            $start = substr($date, 0, 8) . '01';
            $end = (new DateTimeImmutable($date))->format('Y-m-t');
        }
    }
    // Never overlap a recorded SOA.
    foreach ($ctx['soas'] as $s) {
        if ($s['end'] < $date && $s['end'] >= $start) {
            $start = date_add_days($s['end'], 1);
        }
        if ($s['start'] > $date && $s['start'] <= $end) {
            $end = date_add_days($s['start'], -1);
        }
    }

    return ['start' => $start, 'end' => $end, 'statement_id' => null, 'recorded' => false];
}

/** Earliest record date of a card (for how far back periods go). */
function card_first_activity(string $cardId): ?string
{
    $value = db_value(
        "SELECT MIN(d) FROM (
            SELECT MIN(transaction_date) AS d FROM transactions WHERE credit_card_id = :c1
            UNION ALL SELECT MIN(advance_date) FROM cash_advances WHERE credit_card_id = :c2
            UNION ALL SELECT MIN(charge_date) FROM bank_charges WHERE credit_card_id = :c3 AND charge_type <> :ob
            UNION ALL SELECT MIN(payment_date) FROM credit_card_payments WHERE credit_card_id = :c4
            UNION ALL SELECT MIN(COALESCE(cutoff_date, statement_date)) FROM credit_card_statements WHERE credit_card_id = :c5
         ) x",
        [':c1' => $cardId, ':c2' => $cardId, ':c3' => $cardId, ':c4' => $cardId, ':c5' => $cardId, ':ob' => OPENING_BALANCE_TYPE]
    );

    return $value ?: null;
}

/**
 * One page of a card's periods, newest first, from the period open today back
 * to the period of its first record (at least 12 periods): [periods, pages, page].
 */
function card_periods_page(array $ctx, int $page, int $perPage = 6, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $first = card_first_activity($ctx['card']['id']) ?? $today;
    // Start with the next period (prepared ahead), then the open one, then back in time.
    $current = card_period_containing($ctx, $today);
    $periods = [card_period_containing($ctx, date_add_days($current['end'], 1))];
    $date = $today;
    // Walk back period by period (each step is computed in memory; 240 = 20 years).
    for ($i = 0; $i < 240; $i++) {
        $p = card_period_containing($ctx, $date);
        $periods[] = $p;
        // Back to the first record (at least 6 periods, to record an SOA for a month without records).
        if ($p['start'] <= $first && $i >= 4) {
            break;
        }
        $date = date_add_days($p['start'], -1);
    }
    $pages = max(1, (int) ceil(count($periods) / $perPage));
    $page = min(max(1, $page), $pages);

    return [array_slice($periods, ($page - 1) * $perPage, $perPage), $pages, $page];
}

/** "September 2026" - the month of the period's cutoff ("—" for a statement without dates). */
function statement_month_label(?string $cutoff): string
{
    return $cutoff ? (new DateTimeImmutable($cutoff))->format('F Y') : '—';
}

/**
 * Where each installment of a purchase (or cash loan) is billed - by the
 * card's own billing periods, never by its payment due date. Installment 1 is
 * billed on the statement whose period contains $startDate (the transaction
 * date; posted that day); installment n on the (n-1)th period after it
 * (posted on that period's cutoff date). The due date that a statement gives
 * is the payment deadline only.
 *
 * Example: card with cutoff on the 10th, purchase on Oct 9 in 12 installments:
 * 1/12 is on the statement of Sep 11 - Oct 10 (October), 2/12 on Oct 11 - Nov 10
 * (November) ... - whatever their payment due dates are.
 *
 * @return array<int, array{date: string, period: array}> keyed by installment number
 */
function installment_billing(array $ctx, string $startDate, int $count): array
{
    $out = [];
    $period = card_period_containing($ctx, $startDate);
    for ($n = 1; $n <= $count; $n++) {
        if ($n > 1) {
            $period = card_period_containing($ctx, date_add_days($period['end'], 1));
        }
        $out[$n] = ['date' => $n === 1 ? $startDate : $period['end'], 'period' => $period];
    }

    return $out;
}

function statement_period_lines(string $cardId, string $start, string $end): array
{
    $p = [':c' => $cardId, ':s' => $start, ':e' => $end];
    $lines = [];
    $warnings = [];
    $opening = 0.0;
    $charged = sql_in_list(CARD_TXN_CHARGED);
    $linked = "((t.payment_type = 'cash_loan' AND EXISTS (SELECT 1 FROM cash_loans xl WHERE xl.credit_card_id = t.credit_card_id AND xl.loan_reference = t.reference_number))
             OR (t.payment_type = 'cash_advance' AND EXISTS (SELECT 1 FROM cash_advances xa WHERE xa.credit_card_id = t.credit_card_id AND xa.reference_number = t.reference_number)))";
    // Every line carries its source (type + id): the stable key used by statement_items.
    $line = static fn (string $part, string $date, string $text, float $amount, string $link, string $kind, string $note = '', ?string $category = null, ?string $status = null, array $extra = []) =>
        $extra + ['part' => $part, 'date' => $date, 'description' => $text, 'amount' => round($amount, 2), 'link' => $link, 'kind' => $kind, 'note' => $note,
         'category' => $category, 'status' => $status, 'source_type' => $kind, 'source_id' => null, 'progress' => null, 'remaining_count' => null, 'remaining_after' => null];

    // Purchases and cash-type transactions without a loan / advance record.
    foreach (db_all(
        "SELECT t.id, t.transaction_number, t.transaction_date, t.payment_type, t.status, t.amount, m.merchant_name, cat.name AS category_name,
                (SELECT COUNT(*) FROM installment_plans p WHERE p.transaction_id = t.id AND p.status <> 'cancelled') AS has_plan
           FROM transactions t LEFT JOIN merchants m ON m.id = t.merchant_id LEFT JOIN categories cat ON cat.id = t.category_id
          WHERE t.credit_card_id = :c AND t.transaction_date BETWEEN :s AND :e AND t.status IN ($charged) AND NOT $linked
          ORDER BY t.transaction_date, t.transaction_number",
        $p
    ) as $t) {
        $text = $t['transaction_number'] . ($t['merchant_name'] ? ' · ' . $t['merchant_name'] : '');
        $link = url('pages/transactions/view.php', ['id' => $t['id']]);
        if ($t['payment_type'] === 'installment' && (int) $t['has_plan'] > 0) {
            continue;   // billed through its installment schedule
        }
        $part = in_array($t['payment_type'], ['cash_advance', 'cash_loan'], true) ? 'cash_advances' : 'purchases';
        $note = '';
        if ($t['payment_type'] === 'installment') {
            $note = 'Installment purchase without a schedule: counted in full. Create its installment plan to bill it monthly.';
            $warnings[] = $t['transaction_number'] . ' is an installment purchase without a schedule.';
        }
        $lines[] = $line($part, $t['transaction_date'], $text, (float) $t['amount'], $link, 'transaction', $note, $t['category_name'], $t['status'], ['source_id' => $t['id']]);
        if ($t['status'] === 'refunded') {
            $lines[] = $line('credits', $t['transaction_date'], 'Refund · ' . $text, (float) $t['amount'], $link, 'transaction', '', null, null,
                ['source_type' => 'refund', 'source_id' => $t['id']]);
        }
    }

    // Installment charges of installment purchases: each on the statement that bills it
    // (installment_billing: from the transaction date, one per billing period).
    $card = db_row('SELECT * FROM credit_cards WHERE id = :c', [':c' => $cardId]);
    $ctx = $card ? card_period_context($card) : null;
    $billing = [];
    $billedIn = static function (string $key, string $startDate, int $count, int $n) use (&$billing, $ctx): ?string {
        $billing[$key] ??= installment_billing($ctx, $startDate, $count);

        return $billing[$key][$n]['date'] ?? null;
    };
    foreach (db_all(
        "SELECT s.id, s.installment_number, s.due_date, s.amount_due, s.status, p.id AS plan_id, p.number_of_installments, t.id AS txn_id, t.transaction_number,
                t.transaction_date, (SELECT MAX(z.installment_number) FROM installment_schedules z WHERE z.installment_plan_id = p.id) AS max_number,
                m.merchant_name, (SELECT ti.product_name FROM transaction_items ti WHERE ti.transaction_id = t.id ORDER BY ti.created_at LIMIT 1) AS product_name,
                (SELECT COUNT(*) FROM installment_schedules y WHERE y.installment_plan_id = p.id AND y.status NOT IN ('waived', 'cancelled')) AS total_rows,
                (SELECT COUNT(*) FROM installment_schedules x
                  WHERE x.installment_plan_id = p.id AND x.installment_number > s.installment_number AND x.status NOT IN ('waived', 'cancelled')) AS remaining_count,
                (SELECT COALESCE(SUM(x.amount_due), 0) FROM installment_schedules x
                  WHERE x.installment_plan_id = p.id AND x.installment_number > s.installment_number AND x.status NOT IN ('waived', 'cancelled')) AS remaining_after
           FROM installment_schedules s
           JOIN installment_plans p ON p.id = s.installment_plan_id AND p.status <> 'cancelled'
           JOIN transactions t ON t.id = p.transaction_id
           LEFT JOIN merchants m ON m.id = t.merchant_id
          WHERE t.credit_card_id = :c AND t.payment_type = 'installment' AND t.status IN ($charged) AND t.status <> 'refunded'
            AND t.transaction_date <= :e AND s.status NOT IN ('waived', 'cancelled')
          ORDER BY t.transaction_date, t.transaction_number, s.installment_number",
        [':c' => $cardId, ':e' => $end]
    ) as $s) {
        $billed = $ctx ? $billedIn('p' . $s['plan_id'], $s['transaction_date'], (int) $s['max_number'], (int) $s['installment_number']) : null;
        if ($billed === null || $billed < $start || $billed > $end) {
            continue;   // billed on another statement
        }
        $what = $s['product_name'] ?: ($s['merchant_name'] ?: $s['transaction_number']);
        $lines[] = $line('installment_charges', $billed, $what . ' installment · ' . $s['transaction_number'],
            (float) $s['amount_due'], url('pages/installment_plans/view.php', ['id' => $s['plan_id']]), 'installment',
            $s['remaining_count'] . ' left after this · ' . format_money($s['remaining_after']) . ' not billed yet · installment due ' . format_date($s['due_date']), 'Installment', $s['status'],
            ['source_type' => 'installment_schedule', 'source_id' => $s['id'], 'progress' => $s['installment_number'] . '/' . max((int) $s['number_of_installments'], (int) $s['total_rows']),
             'installment_number' => (int) $s['installment_number'], 'installment_count' => max((int) $s['number_of_installments'], (int) $s['total_rows']),
             'remaining_count' => (int) $s['remaining_count'], 'remaining_after' => (float) $s['remaining_after']]);
    }

    // Cash loans: monthly installments billed one per period from the loan's start (not by due date),
    // fees on the card when the loan started.
    foreach (db_all(
        "SELECT s.id, s.installment_number, s.due_date, s.amount_due, s.status, l.id AS loan_id, l.loan_reference,
                COALESCE(l.start_date, DATE(l.created_at)) AS billing_start,
                (SELECT MAX(z.installment_number) FROM cash_loan_schedules z WHERE z.cash_loan_id = l.id) AS max_number,
                (SELECT COUNT(*) FROM cash_loan_schedules y WHERE y.cash_loan_id = l.id AND y.status NOT IN ('waived', 'cancelled')) AS total_rows,
                (SELECT COUNT(*) FROM cash_loan_schedules x
                  WHERE x.cash_loan_id = l.id AND x.installment_number > s.installment_number AND x.status NOT IN ('waived', 'cancelled')) AS remaining_count,
                (SELECT COALESCE(SUM(x.amount_due), 0) FROM cash_loan_schedules x
                  WHERE x.cash_loan_id = l.id AND x.installment_number > s.installment_number AND x.status NOT IN ('waived', 'cancelled')) AS remaining_after
           FROM cash_loan_schedules s JOIN cash_loans l ON l.id = s.cash_loan_id
          WHERE l.credit_card_id = :c AND l.status IN (" . sql_in_list(CASH_LOAN_CHARGED) . ")
            AND COALESCE(l.start_date, DATE(l.created_at)) <= :e AND s.status NOT IN ('waived', 'cancelled')
          ORDER BY s.installment_number",
        [':c' => $cardId, ':e' => $end]
    ) as $s) {
        $billed = $ctx ? $billedIn('l' . $s['loan_id'], $s['billing_start'], (int) $s['max_number'], (int) $s['installment_number']) : null;
        if ($billed === null || $billed < $start || $billed > $end) {
            continue;   // billed on another statement
        }
        $lines[] = $line('installment_charges', $billed, 'Cash loan ' . $s['loan_reference'] . ' repayment',
            (float) $s['amount_due'], url('pages/cash_loans/view.php', ['id' => $s['loan_id']]), 'cash_loan',
            $s['remaining_count'] . ' left after this · ' . format_money($s['remaining_after']) . ' not billed yet · installment due ' . format_date($s['due_date']), 'Cash loan', $s['status'],
            ['source_type' => 'cash_loan_schedule', 'source_id' => $s['id'], 'progress' => $s['installment_number'] . '/' . $s['total_rows'],
             'installment_number' => (int) $s['installment_number'], 'installment_count' => (int) $s['total_rows'],
             'remaining_count' => (int) $s['remaining_count'], 'remaining_after' => (float) $s['remaining_after']]);
    }
    foreach (db_all(
        "SELECT l.* FROM cash_loans l
          WHERE l.credit_card_id = :c AND l.status IN (" . sql_in_list(CASH_LOAN_CHARGED) . ")
            AND COALESCE(l.start_date, l.first_due_date, DATE(l.created_at)) BETWEEN :s AND :e",
        $p
    ) as $loan) {
        $charge = cash_loan_card_charge($loan);
        $date = $loan['start_date'] ?: ($loan['first_due_date'] ?: substr($loan['created_at'], 0, 10));
        if ($charge['fees'] > 0) {
            $lines[] = $line('other_charges', $date, 'Cash loan ' . $loan['loan_reference'] . ' · fees', $charge['fees'],
                url('pages/cash_loans/view.php', ['id' => $loan['id']]), 'cash_loan', '', null, null, ['source_type' => 'cash_loan_fee', 'source_id' => $loan['id']]);
        }
        if ((int) db_value('SELECT COUNT(*) FROM cash_loan_schedules WHERE cash_loan_id = :l', [':l' => $loan['id']]) === 0) {
            $warnings[] = 'Cash loan ' . $loan['loan_reference'] . ' has no repayment schedule, so its monthly installments are not billed. Generate its schedule.';
        }
    }

    // Cash advances.
    foreach (db_all(
        "SELECT * FROM cash_advances WHERE credit_card_id = :c AND advance_date BETWEEN :s AND :e
            AND status IN (" . sql_in_list(CASH_ADVANCE_CHARGED) . ") ORDER BY advance_date",
        $p
    ) as $a) {
        $link = url('pages/cash_advances/view.php', ['id' => $a['id']]);
        $text = 'Cash advance' . ($a['reference_number'] ? ' ' . $a['reference_number'] : '');
        $lines[] = $line('cash_advances', $a['advance_date'], $text, (float) $a['amount'], $link, 'cash_advance', 'Billed in full on the statement of its date', null, $a['status'],
            ['source_id' => $a['id']]);
        if ((float) $a['fee'] > 0) {
            $lines[] = $line('other_charges', $a['advance_date'], $text . ' · fee', (float) $a['fee'], $link, 'cash_advance', '', null, null, ['source_type' => 'cash_advance_fee', 'source_id' => $a['id']]);
        }
        if ((float) $a['interest'] > 0) {
            $lines[] = $line('interest', $a['advance_date'], $text . ' · interest', (float) $a['interest'], $link, 'cash_advance', '', null, null, ['source_type' => 'cash_advance_interest', 'source_id' => $a['id']]);
        }
    }

    // Bank charges: interest, finance charges, late fees, annual and other fees.
    foreach (db_all('SELECT * FROM bank_charges WHERE credit_card_id = :c AND charge_date BETWEEN :s AND :e ORDER BY charge_date', $p) as $b) {
        if ($b['charge_type'] === OPENING_BALANCE_TYPE) {
            $opening += (float) $b['amount'];
            continue;
        }
        $type = strtolower($b['charge_type']);
        $part = match (true) {
            str_contains($type, 'interest') => 'interest',
            str_contains($type, 'finance')  => 'finance_charges',
            str_contains($type, 'late')     => 'late_fees',
            default                         => 'other_charges',
        };
        $lines[] = $line($part, $b['charge_date'], $b['charge_type'] . ($b['description'] ? ' · ' . excerpt($b['description'], 60) : ''),
            (float) $b['amount'], url('pages/bank_charges/view.php', ['id' => $b['id']]), 'bank_charge', '', null, null, ['source_id' => $b['id']]);
    }

    // Payments to the bank.
    foreach (db_all('SELECT * FROM credit_card_payments WHERE credit_card_id = :c AND payment_date BETWEEN :s AND :e ORDER BY payment_date', $p) as $pay) {
        $lines[] = $line('payments', $pay['payment_date'], 'Payment' . ($pay['reference_number'] ? ' ' . $pay['reference_number'] : '') . ($pay['payment_type'] ? ' · ' . $pay['payment_type'] : ''),
            (float) $pay['amount'], url('pages/card_payments/view.php', ['id' => $pay['id']]), 'payment', '', null, null, ['source_type' => 'card_payment', 'source_id' => $pay['id']]);
    }

    usort($lines, static fn ($a, $b) => [$a['date'], $a['part']] <=> [$b['date'], $b['part']]);

    return ['lines' => $lines, 'opening' => round($opening, 2), 'warnings' => array_values(array_unique($warnings))];
}

/** Charges, payments and credits of a set of period lines. */
function statement_lines_summary(array $period): array
{
    $sum = array_fill_keys(array_keys(STATEMENT_PARTS), 0.0);
    foreach ($period['lines'] as $l) {
        $sum[$l['part']] += $l['amount'];
    }
    $charges = 0.0;
    foreach ($sum as $part => $amount) {
        if (!in_array($part, STATEMENT_CREDIT_PARTS, true)) {
            $charges += $amount;
        }
    }

    return [
        'parts'    => array_map(static fn ($v) => round($v, 2), $sum),
        'opening'  => $period['opening'],
        'charges'  => round($charges + $period['opening'], 2),
        'payments' => round($sum['payments'] + $sum['credits'], 2),
        'count'    => count($period['lines']),
    ];
}

/** Split lines of a date range into the given periods (keyed by period end). */
function statement_bucket_lines(array $range, array $periods): array
{
    $out = [];
    foreach ($periods as $p) {
        $out[$p['end']] = ['lines' => [], 'opening' => 0.0, 'warnings' => []];
    }
    foreach ($range['lines'] as $l) {
        foreach ($periods as $p) {
            if ($l['date'] >= $p['start'] && $l['date'] <= $p['end']) {
                $out[$p['end']]['lines'][] = $l;
                break;
            }
        }
    }

    return $out;
}

/* ------------------------------------------------------------------
 * Recorded SOAs and their payments
 * ------------------------------------------------------------------ */

/** Joins the payments recorded against each statement (one grouped subquery, no per-row queries). */
function statement_paid_join(string $alias = 's'): string
{
    return "LEFT JOIN (SELECT statement_id, SUM(amount) AS paid, COUNT(*) AS payment_count FROM credit_card_payments
                        WHERE statement_id IS NOT NULL GROUP BY statement_id) sp ON sp.statement_id = $alias.id";
}

/** SQL: payment status of statement alias s joined with statement_paid_join(). */
function statement_status_sql(string $alias = 's'): string
{
    return "(CASE WHEN $alias.soa_recorded_at IS NULL THEN 'prepared'
                  WHEN $alias.total_amount_due - COALESCE(sp.paid, 0) <= 0.004 THEN 'paid'
                  WHEN $alias.due_date < CURDATE() THEN 'overdue'
                  WHEN COALESCE(sp.paid, 0) > 0 THEN 'partially_paid'
                  ELSE 'pending' END)";
}

function statement_remaining_sql(string $alias = 's'): string
{
    return "IF($alias.soa_recorded_at IS NULL, 0, GREATEST($alias.total_amount_due - COALESCE(sp.paid, 0), 0))";
}

/** One recorded SOA with card details, paid, remaining and status. */
function statement_soa_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        'SELECT s.*, c.card_nickname, c.issuer, c.last_four, d.original_filename,
                COALESCE(sp.paid, 0) AS paid, COALESCE(sp.payment_count, 0) AS payment_count,
                ' . statement_remaining_sql() . ' AS remaining, ' . statement_status_sql() . ' AS pay_status
           FROM credit_card_statements s
           JOIN credit_cards c ON c.id = s.credit_card_id
           LEFT JOIN documents d ON d.id = s.attachment_document_id
           ' . statement_paid_join() . '
          WHERE s.id = :id',
        [':id' => $id]
    );
}

function statement_status_badge(string $status, ?string $dueDate = null): string
{
    $tone = ['prepared' => 'info', 'unrecorded' => 'muted', 'pending' => 'warning', 'partially_paid' => 'info', 'paid' => 'success', 'overdue' => 'danger'][$status] ?? 'muted';
    $text = STATEMENT_PAY_STATUSES[$status] ?? humanize($status);
    if (!in_array($status, ['paid', 'unrecorded', 'prepared'], true) && $dueDate && $dueDate >= date('Y-m-d') && $dueDate <= date_add_days(date('Y-m-d'), STATEMENT_UPCOMING_DAYS)) {
        $text .= ' · due soon';
    }

    return '<span class="status status--' . $tone . '">' . e($text) . '</span>';
}

/**
 * What the records say the bank should show for a recorded SOA:
 * previous balance + this period's charges − payments and credits.
 * The previous balance comes from, in order:
 *   1. the "Previous balance" printed on this SOA (entered with it);
 *   2. the previous period's recorded SOA amount;
 *   3. what the records carry over: every charge minus every payment before
 *      this period (e.g. installments billed earlier and not paid to the bank).
 * Returns ['expected', 'previous', 'basis' (text), 'carried' (records), 'previous_soa'].
 */
function statement_expected(array $statement, array $summary): array
{
    $start = $statement['period_start_date'];
    $previousSoa = $start ? db_row(
        'SELECT id, total_amount_due, cutoff_date, statement_date FROM credit_card_statements WHERE credit_card_id = :c AND COALESCE(cutoff_date, statement_date) = :d AND soa_recorded_at IS NOT NULL',
        [':c' => $statement['credit_card_id'], ':d' => date_add_days($start, -1)]
    ) : null;

    // Carried over by the records (all periods before this one).
    $carried = 0.0;
    $first = $start ? card_first_activity($statement['credit_card_id']) : null;
    if ($start && $first && $first < $start) {
        $before = statement_lines_summary(statement_period_lines($statement['credit_card_id'], $first, date_add_days($start, -1)));
        $carried = round($before['charges'] - $before['payments'], 2);
    }

    if (($statement['soa_previous_balance'] ?? null) !== null) {
        [$previous, $basis] = [(float) $statement['soa_previous_balance'], 'previous balance printed on this SOA'];
    } elseif ($previousSoa) {
        [$previous, $basis] = [(float) $previousSoa['total_amount_due'], 'previous SOA you recorded (' . statement_month_label($previousSoa['cutoff_date'] ?: $previousSoa['statement_date']) . ')'];
    } else {
        [$previous, $basis] = [$carried, 'carried over by your records (charges − payments before this period)'];
    }

    return [
        'expected' => round($previous + $summary['charges'] - $summary['payments'], 2),
        'previous' => round($previous, 2), 'basis' => $basis, 'carried' => $carried, 'previous_soa' => $previousSoa,
    ];
}

/* ------------------------------------------------------------------
 * Where a record belongs (notices after adding / editing records)
 * ------------------------------------------------------------------ */

function statement_for_date(string $cardId, string $date): array
{
    $card = db_row('SELECT * FROM credit_cards WHERE id = :c', [':c' => $cardId]);
    if (!$card) {
        return ['period' => null, 'label' => 'no billing period'];
    }
    $p = card_period_containing(card_period_context($card), $date);

    return ['period' => $p, 'label' => 'the ' . statement_month_label($p['end']) . ' statement period (' . format_date($p['start']) . ' – ' . format_date($p['end']) . ')'];
}

/**
 * After a record is added from a statement ("Add missing transaction"): say
 * which period it went to; warn when it is not the period being viewed (it is
 * never forced into it). $expectedEnd is that period's cutoff date.
 */
function statement_placement_notice(string $cardId, string $date, ?string $expectedEnd): void
{
    if (!$expectedEnd || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expectedEnd)) {
        return;
    }
    $where = statement_for_date($cardId, $date);
    if ($where['period'] && $where['period']['end'] === $expectedEnd) {
        flash('info', 'It is listed in this statement period. A recorded SOA amount is not changed — compare it with the updated records.');
    } else {
        flash('warning', 'The date ' . format_date($date) . ' falls in ' . $where['label'] . ', not in the statement you were viewing — it is listed there.');
    }
}

/** After a record's card or date changed: say when it moved to another statement period. */
function statement_move_notice(string $oldCard, string $oldDate, string $newCard, string $newDate): void
{
    if ($oldCard === $newCard && $oldDate === $newDate) {
        return;
    }
    $from = statement_for_date($oldCard, $oldDate);
    $to = statement_for_date($newCard, $newDate);
    if ($oldCard === $newCard && $from['period'] && $to['period'] && $from['period']['end'] === $to['period']['end']) {
        return;
    }
    $recorded = ($from['period']['recorded'] ?? false) || ($to['period']['recorded'] ?? false);
    flash($recorded ? 'warning' : 'info', 'The record moved from ' . $from['label'] . ' to ' . $to['label'] . '.'
        . ($recorded ? ' A recorded SOA is affected: its difference from the records changed — check it again.' : ''));
}

/* ------------------------------------------------------------------
 * Earlier SOA comparisons (statement_reconciliations, read-only history)
 * ------------------------------------------------------------------ */

function reconciliation_badge(string $status): string
{
    return $status === 'balanced'
        ? '<span class="status status--success">Balanced</span>'
        : '<span class="status status--danger">Difference</span>';
}

/**
 * Table of recorded SOAs (statement list filters and the dashboard). Rows need
 * the card columns plus paid, remaining and pay_status.
 */
function render_statement_rows(array $rows, int $offset = 0): string
{
    $html = '<div class="table-wrap"><table class="table table--cards"><thead><tr>'
        . '<th scope="col" class="col-num">#</th><th scope="col">Card</th><th scope="col">Statement month</th><th scope="col" class="num">Actual SOA amount</th>'
        . '<th scope="col" class="num">Minimum due</th><th scope="col" class="num">Paid</th><th scope="col" class="num">Remaining</th><th scope="col">Due date</th>'
        . '<th scope="col">Status</th><th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
    foreach ($rows as $i => $r) {
        $view = url('pages/statements/view.php', ['id' => $r['id']]);
        $html .= '<tr>'
            . '<td class="col-num" data-label="#">' . e($offset + $i + 1) . '</td>'
            . '<td data-label="Card">' . e($r['card_nickname']) . '<br><span class="text-muted">' . e($r['issuer']) . ' •••• ' . e($r['last_four']) . '</span></td>'
            . '<td data-label="Statement month"><a data-modal-form class="table-link" href="' . e($view) . '">' . e(statement_month_label($r['cutoff_date'] ?: $r['statement_date'])) . '</a></td>'
            . '<td data-label="Actual SOA amount" class="num">' . e(format_money($r['total_amount_due'])) . '</td>'
            . '<td data-label="Minimum due" class="num">' . e(format_money($r['minimum_amount_due'])) . '</td>'
            . '<td data-label="Paid" class="num">' . e(format_money($r['paid'])) . '</td>'
            . '<td data-label="Remaining" class="num"><strong>' . e(format_money($r['remaining'])) . '</strong></td>'
            . '<td data-label="Due date">' . e(format_date($r['due_date'])) . '</td>'
            . '<td data-label="Status">' . statement_status_badge($r['pay_status'], $r['due_date']) . '</td>'
            . '<td class="actions no-print"><div class="row-actions">'
            . '<a data-modal-form href="' . e($view) . '" class="icon-button" title="View" aria-label="View statement">' . icon('eye') . '</a>'
            . ((float) $r['remaining'] > 0.004
                ? '<a data-modal-form href="' . e(url('pages/statements/pay.php', ['id' => $r['id']])) . '" class="btn btn--sm btn--primary">' . icon('wallet') . ' <span>Pay</span></a>' : '')
            . '</div></td></tr>';
    }

    return $html . '</tbody></table></div>';
}

/**
 * The signed-in user's credit card payment position for the dashboard, from
 * the recorded SOAs and their payments (each statement counted once).
 */
function statement_payment_summary(): array
{
    $remaining = statement_remaining_sql();
    $paid = 'COALESCE(sp.paid, 0)';
    $upcoming = 'CURDATE() + INTERVAL ' . STATEMENT_UPCOMING_DAYS . ' DAY';
    $mine = owner_scope('credit_cards', 'c');

    return db_row(
        "SELECT (SELECT COUNT(*) FROM credit_cards c WHERE $mine) AS cards,
                (SELECT COUNT(*) FROM credit_cards c WHERE status = 'active' AND $mine) AS active_cards,
                COALESCE(SUM($remaining > 0.004), 0) AS unpaid,
                COUNT(DISTINCT IF($remaining > 0.004, s.credit_card_id, NULL)) AS unpaid_cards,
                COALESCE(SUM($remaining > 0.004 AND $paid = 0 AND s.due_date >= CURDATE()), 0) AS pending,
                COALESCE(SUM($remaining > 0.004 AND $paid > 0), 0) AS partial,
                COALESCE(SUM(IF($remaining > 0.004, $remaining, 0)), 0) AS outstanding,
                COALESCE(SUM($remaining > 0.004 AND s.due_date BETWEEN CURDATE() AND $upcoming), 0) AS upcoming,
                COALESCE(SUM($remaining > 0.004 AND s.due_date < CURDATE()), 0) AS overdue,
                COUNT(DISTINCT IF($remaining > 0.004 AND s.due_date < CURDATE(), s.credit_card_id, NULL)) AS overdue_cards,
                COALESCE(SUM(IF($remaining > 0.004 AND s.due_date < CURDATE(), $remaining, 0)), 0) AS overdue_amount,
                COALESCE(SUM(IF($remaining > 0.004, LEAST(GREATEST(s.minimum_amount_due - $paid, 0), $remaining), 0)), 0) AS minimum_due,
                (SELECT COUNT(*) FROM credit_card_statements x WHERE x.soa_recorded_at IS NULL AND x.cutoff_date < CURDATE() AND " . owner_scope('credit_card_statements', 'x') . ") AS awaiting_soa
           FROM credit_card_statements s " . statement_paid_join() . ' WHERE s.soa_recorded_at IS NOT NULL AND ' . owner_scope('credit_card_statements', 's')
    ) ?: [];
}

/* ------------------------------------------------------------------
 * Preparing upcoming statements (current and next billing period)
 * ------------------------------------------------------------------ */

/** The billing period open today and the next one. */
function card_upcoming_periods(array $ctx, string $today): array
{
    $current = card_period_containing($ctx, $today);

    return [$current, card_period_containing($ctx, date_add_days($current['end'], 1))];
}

/** Fingerprint of a period's items: unchanged records mean nothing to write. */
function statement_items_signature(array $period, array $lines): string
{
    return md5(json_encode([$period['start'], $period['end'], array_map(
        static fn ($l) => [$l['source_type'], $l['source_id'], $l['amount'], $l['date'], $l['part'], $l['progress']], $lines)]));
}

/**
 * Prepare a card's upcoming statements from its existing records: the period
 * open today and the next one. Never touches earlier periods, never copies a
 * record, never changes a recorded SOA. Safe to run any number of times:
 * nothing is written when the records did not change since the last run.
 * Returns the number of periods that were (re)written.
 */
function statement_prepare_card(string $cardId, ?string $today = null): int
{
    $today ??= date('Y-m-d');
    $card = db_row('SELECT * FROM credit_cards WHERE id = :id', [':id' => $cardId]);
    // Only cards with a statement cutoff day: without it the periods would be guesses.
    if (!$card || $card['status'] !== 'active' || !$card['billing_cycle_day']) {
        return 0;
    }
    $ctx = card_period_context($card);
    $written = 0;
    $keep = [];
    foreach (card_upcoming_periods($ctx, $today) as $period) {
        if ($period['recorded']) {
            continue;   // inside a recorded SOA (also an older one without a cutoff date)
        }
        $existing = db_row(
            'SELECT id, soa_recorded_at, prepared_signature FROM credit_card_statements WHERE credit_card_id = :c AND cutoff_date = :e',
            [':c' => $cardId, ':e' => $period['end']]
        );
        if ($existing && $existing['soa_recorded_at'] !== null) {
            continue;   // the bank's SOA is recorded: left exactly as it is
        }
        $lines = array_values(array_filter(statement_period_lines($cardId, $period['start'], $period['end'])['lines'], static fn ($l) => $l['source_id'] !== null));
        $signature = statement_items_signature($period, $lines);
        if ($existing && $existing['prepared_signature'] === $signature) {
            $keep[] = $existing['id'];
            continue;   // nothing changed since it was prepared
        }
        $keep[] = db_transaction(static fn () => statement_prepare_period($card, $period, $lines, $signature, $today));
        $written++;
    }

    // A prepared future statement left over from an earlier cutoff day (no SOA, no payment) is removed.
    $keep = array_filter($keep);
    $stale = db_all(
        'SELECT s.id FROM credit_card_statements s WHERE s.credit_card_id = :c AND s.soa_recorded_at IS NULL AND s.cutoff_date >= :t
            AND NOT EXISTS (SELECT 1 FROM credit_card_payments p WHERE p.statement_id = s.id)',
        [':c' => $cardId, ':t' => $today]
    );
    foreach ($stale as $row) {
        if (!in_array($row['id'], $keep, true)) {
            db_exec('DELETE FROM credit_card_statements WHERE id = :id AND soa_recorded_at IS NULL', [':id' => $row['id']]);
            $written++;
        }
    }

    return $written;
}

/**
 * Create or update one prepared statement and its items. Runs in a transaction;
 * the card row lock keeps two requests from preparing the same period at once.
 */
function statement_prepare_period(array $card, array $period, array $lines, string $signature, string $today): ?string
{
    db_row('SELECT id FROM credit_cards WHERE id = :id FOR UPDATE', [':id' => $card['id']]);

    $row = db_row('SELECT * FROM credit_card_statements WHERE credit_card_id = :c AND cutoff_date = :e', [':c' => $card['id'], ':e' => $period['end']]);
    if (!$row) {
        // Reuse a prepared row of the same period made with an earlier cutoff day.
        $row = db_row(
            'SELECT * FROM credit_card_statements WHERE credit_card_id = :c AND soa_recorded_at IS NULL AND cutoff_date >= :t
                AND COALESCE(period_start_date, cutoff_date) <= :e AND cutoff_date >= :s ORDER BY cutoff_date LIMIT 1',
            [':c' => $card['id'], ':t' => $today, ':s' => $period['start'], ':e' => $period['end']]
        );
    }
    if ($row && $row['soa_recorded_at'] !== null) {
        return null;
    }
    [$due] = card_due_date($card, $period['end']);
    $now = date('Y-m-d H:i:s');
    if (!$row) {
        $id = new_uuid();
        db_insert('credit_card_statements', [
            'id' => $id, 'credit_card_id' => $card['id'], 'period_start_date' => $period['start'], 'source' => 'generated',
            'statement_date' => $period['end'], 'cutoff_date' => $period['end'], 'due_date' => $due ?? $period['end'],
            'due_date_status' => $due ? 'rule' : 'unconfirmed', 'minimum_due_status' => 'unconfirmed',
            'total_amount_due' => '0.00', 'minimum_amount_due' => '0.00', 'prepared_at' => $now, 'prepared_signature' => $signature,
        ]);
    } else {
        $id = $row['id'];
        db_update('credit_card_statements', [
            'period_start_date' => $period['start'], 'cutoff_date' => $period['end'], 'statement_date' => $period['end'],
            'due_date' => $due ?? $period['end'], 'due_date_status' => $due ? 'rule' : 'unconfirmed',
            'prepared_at' => $now, 'prepared_signature' => $signature, 'updated_at' => $now,
        ], $id);
    }

    // Items: one per source record (UNIQUE source_type + source_id).
    $current = [];
    foreach (db_all(
        'SELECT i.id, i.statement_id, i.source_type, i.source_id, s.soa_recorded_at FROM statement_items i
           JOIN credit_card_statements s ON s.id = i.statement_id WHERE s.credit_card_id = :c',
        [':c' => $card['id']]
    ) as $i) {
        $current[$i['source_type'] . '|' . $i['source_id']] = $i;
    }
    $mine = [];
    foreach ($lines as $l) {
        $key = $l['source_type'] . '|' . $l['source_id'];
        $item = [
            'statement_id' => $id, 'item_date' => $l['date'], 'part' => $l['part'], 'description' => mb_substr($l['description'], 0, 255),
            'amount' => number_format($l['amount'], 2, '.', ''), 'installment_number' => $l['installment_number'] ?? null,
            'installment_count' => $l['installment_count'] ?? null,
        ];
        $found = $current[$key] ?? db_row(
            'SELECT i.id, i.statement_id, s.soa_recorded_at FROM statement_items i JOIN credit_card_statements s ON s.id = i.statement_id
              WHERE i.source_type = :t AND i.source_id = :s',
            [':t' => $l['source_type'], ':s' => $l['source_id']]
        );
        if ($found && $found['statement_id'] !== $id && $found['soa_recorded_at'] !== null) {
            continue;   // already on a recorded SOA: never moved off it
        }
        if ($found) {
            db_update('statement_items', $item + ['updated_at' => date('Y-m-d H:i:s')], $found['id']);
        } else {
            db_insert('statement_items', ['id' => new_uuid(), 'credit_card_id' => $card['id'], 'source_type' => $l['source_type'], 'source_id' => $l['source_id']] + $item);
        }
        $mine[$key] = true;
    }
    // Records that no longer belong to this period (date, schedule or cutoff changed).
    foreach (db_all('SELECT id, source_type, source_id FROM statement_items WHERE statement_id = :s', [':s' => $id]) as $i) {
        if (!isset($mine[$i['source_type'] . '|' . $i['source_id']])) {
            db_exec('DELETE FROM statement_items WHERE id = :id', [':id' => $i['id']]);
        }
    }

    return $id;
}

/** Prepare the upcoming statements of every active card (daily run; idempotent). */
function statement_prepare_all(?string $today = null): int
{
    $today ??= date('Y-m-d');
    // Cards no longer prepared (no cutoff day, not active): drop their open prepared statements (no SOA, no payment).
    $written = db_exec(
        "DELETE s FROM credit_card_statements s JOIN credit_cards c ON c.id = s.credit_card_id
          WHERE s.soa_recorded_at IS NULL AND s.cutoff_date >= :t AND (c.billing_cycle_day IS NULL OR c.status <> 'active')
            AND NOT EXISTS (SELECT 1 FROM credit_card_payments p WHERE p.statement_id = s.id)",
        [':t' => $today]
    );
    foreach (db_all("SELECT id FROM credit_cards WHERE status = 'active' AND billing_cycle_day IS NOT NULL") as $c) {
        try {
            $written += statement_prepare_card($c['id'], $today);
        } catch (PDOException $e) {
            // One card's problem never stops the others; it is logged and retried next run.
            error_log('[' . APP_NAME . '] Statement preparation for card ' . $c['id'] . ': ' . $e->getMessage());
        }
    }

    return $written;
}
