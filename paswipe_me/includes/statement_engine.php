<?php
/**
 * Credit card statements, generated from the card's billing settings and the
 * records already stored for the card (see db/upgrade_statements.sql).
 *
 * Billing period
 *   A card's statement cutoff day (credit_cards.billing_cycle_day) closes each
 *   period; the next period starts the day after. A period (start .. cutoff)
 *   is stored once in card_billing_cycles and in its statement and is never
 *   moved afterwards: changing the cutoff day only shapes the periods that are
 *   not generated yet. A special period the bank uses can be entered as a
 *   billing cycle on the card ("Add cycle"); the generator follows it.
 *
 * What belongs to a statement - by the record's own date (never the date it
 *   was typed into the system), between the period start and the cutoff:
 *     purchases            transactions (straight / other; installment purchases
 *                          without a schedule are counted in full and flagged)
 *     installment charges  installment_schedules rows of installment purchases
 *                          and cash_loan_schedules rows of cash loans, by their
 *                          due date - one row is one charge, so a charge can
 *                          never be billed twice
 *     cash advances        cash_advances (+ cash-type transactions without a
 *                          cash advance / loan record)
 *     interest and fees    bank_charges by type, cash advance fee and interest,
 *                          cash loan fees not taken from the proceeds
 *     payments / credits   credit_card_payments, refunded purchases
 *   statement balance = previous statement balance + charges − payments −
 *                       credits + adjustments (entered by hand, with a reason)
 *
 * The figures are recalculated from those records every time a statement is
 * shown; the totals columns of credit_card_statements hold the latest result
 * so lists and reports can read them without recalculating. Statements typed
 * in by hand (source = manual) are never recalculated.
 *
 * Not the same as the statement balance:
 *   current card balance          card_usage() - everything charged and paid so far
 *   remaining installment balance installments not billed yet
 *   minimum amount due            from the card's minimum-due rule, otherwise
 *                                 "needs confirmation" (never guessed)
 *   payment due date              from the card's due-date rule, otherwise
 *                                 "needs confirmation"
 */

defined('APP_ROOT') or exit('No direct access.');

const STATEMENT_UPGRADE_MARKER = 'storage/upgrade_statements.done';

/** Statement figure columns, in display order. */
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

/* ------------------------------------------------------------------
 * Schema
 * ------------------------------------------------------------------ */

/**
 * Apply db/upgrade_statements.sql once (every statement in it is idempotent).
 */
function statement_schema_upgrade(): void
{
    if (is_file(APP_ROOT . '/' . STATEMENT_UPGRADE_MARKER)) {
        return;
    }
    $sql = (string) file_get_contents(APP_ROOT . '/db/upgrade_statements.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        db()->exec($statement);
    }
    @file_put_contents(APP_ROOT . '/' . STATEMENT_UPGRADE_MARKER, date('c'), LOCK_EX);
}

/* ------------------------------------------------------------------
 * Dates
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
 * Payment due date for a cutoff, from the card's rule: [date, status].
 * Without a known rule the cutoff date is a placeholder marked 'unconfirmed'.
 */
function card_due_date(array $card, string $cutoff): array
{
    $rule = $card['due_date_rule'] ?? null;
    if ($rule === 'days_after_cutoff' && (int) $card['due_days_after_cutoff'] > 0) {
        return [date_add_days($cutoff, (int) $card['due_days_after_cutoff']), 'rule'];
    }
    if (($rule === 'day_of_month' || $rule === null) && (int) $card['payment_due_day'] > 0) {
        return [cutoff_on_or_after((int) $card['payment_due_day'], date_add_days($cutoff, 1)), 'rule'];
    }

    return [$cutoff, 'unconfirmed'];
}

function card_has_minimum_rule(array $card): bool
{
    return $card['minimum_due_percent'] !== null || $card['minimum_due_floor'] !== null;
}

/** Minimum due from the card's rule (null when the rule is not known). */
function card_minimum_due(array $card, float $balance): ?float
{
    if (!card_has_minimum_rule($card)) {
        return null;
    }
    if ($balance <= 0.004) {
        return 0.0;
    }
    $minimum = max((float) $card['minimum_due_floor'], $balance * (float) $card['minimum_due_percent'] / 100);

    return round(min($balance, $minimum), 2);
}

function card_billing_summary(array $card): string
{
    if (!$card['billing_cycle_day']) {
        return 'No statement cutoff day — statements are not generated.';
    }
    [, $status] = card_due_date($card, date('Y-m-d'));
    $due = match (true) {
        $status !== 'rule' => 'due date needs confirmation',
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
 * Periods
 * ------------------------------------------------------------------ */

/** End of the last stored statement of a card (cutoff, else statement date). */
function card_last_statement_end(string $cardId): ?string
{
    $value = db_value('SELECT MAX(COALESCE(cutoff_date, statement_date)) FROM credit_card_statements WHERE credit_card_id = :c', [':c' => $cardId]);

    return $value ?: null;
}

/** Date of the card's first charge or payment, for the first period. */
function card_first_activity(string $cardId): ?string
{
    $value = db_value(
        "SELECT MIN(d) FROM (
            SELECT MIN(transaction_date) AS d FROM transactions WHERE credit_card_id = :c1 AND status IN (" . sql_in_list(CARD_TXN_CHARGED) . ")
            UNION ALL SELECT MIN(advance_date) FROM cash_advances WHERE credit_card_id = :c2 AND status IN (" . sql_in_list(CASH_ADVANCE_CHARGED) . ")
            UNION ALL SELECT MIN(charge_date) FROM bank_charges WHERE credit_card_id = :c3
            UNION ALL SELECT MIN(payment_date) FROM credit_card_payments WHERE credit_card_id = :c4
            UNION ALL SELECT MIN(s.due_date) FROM cash_loan_schedules s JOIN cash_loans l ON l.id = s.cash_loan_id
                       WHERE l.credit_card_id = :c5 AND l.status IN (" . sql_in_list(CASH_LOAN_CHARGED) . ")
         ) x",
        [':c1' => $cardId, ':c2' => $cardId, ':c3' => $cardId, ':c4' => $cardId, ':c5' => $cardId]
    );

    return $value ?: null;
}

/**
 * The period that starts on $start: a billing cycle entered on the card for
 * that start date (special bank schedule), otherwise start .. next cutoff day.
 * Returns [start, cutoff, statement_date|null, due_date|null] or null.
 */
function card_period_from(array $card, string $start): ?array
{
    $special = db_row(
        'SELECT cycle_end_date, statement_date, payment_due_date FROM card_billing_cycles WHERE credit_card_id = :c AND cycle_start_date = :s',
        [':c' => $card['id'], ':s' => $start]
    );
    if ($special) {
        return [$start, $special['cycle_end_date'], $special['statement_date'], $special['payment_due_date']];
    }
    if (!$card['billing_cycle_day']) {
        return null;
    }

    return [$start, cutoff_on_or_after((int) $card['billing_cycle_day'], $start), null, null];
}

/** Where the next period of a card starts (null: nothing to bill yet). */
function card_next_period_start(array $card): ?string
{
    $last = card_last_statement_end($card['id']);
    if ($last) {
        return date_add_days($last, 1);
    }
    $first = card_first_activity($card['id']);
    if (!$first || !$card['billing_cycle_day']) {
        return null;
    }

    return date_add_days(cutoff_before((int) $card['billing_cycle_day'], $first), 1);
}

/** The period still open today (not a statement yet), or null. */
function card_open_period(array $card): ?array
{
    $start = card_next_period_start($card);

    return $start ? card_period_from($card, $start) : null;
}

/* ------------------------------------------------------------------
 * What a period contains
 * ------------------------------------------------------------------ */

/**
 * Every record billed in a period, one line each:
 * [part, date, description, amount, link, kind, note].
 * Also returns warnings for records that need attention.
 *
 * @return array{lines: array<int, array>, opening: float, warnings: string[]}
 */
function statement_period_lines(string $cardId, string $start, string $end): array
{
    $p = [':c' => $cardId, ':s' => $start, ':e' => $end];
    $lines = [];
    $warnings = [];
    $opening = 0.0;
    $charged = sql_in_list(CARD_TXN_CHARGED);
    $linked = "((t.payment_type = 'cash_loan' AND EXISTS (SELECT 1 FROM cash_loans xl WHERE xl.credit_card_id = t.credit_card_id AND xl.loan_reference = t.reference_number))
             OR (t.payment_type = 'cash_advance' AND EXISTS (SELECT 1 FROM cash_advances xa WHERE xa.credit_card_id = t.credit_card_id AND xa.reference_number = t.reference_number)))";
    $line = static fn (string $part, string $date, string $text, float $amount, string $link, string $kind, string $note = '') =>
        ['part' => $part, 'date' => $date, 'description' => $text, 'amount' => round($amount, 2), 'link' => $link, 'kind' => $kind, 'note' => $note];

    // Purchases and cash-type transactions without a loan / advance record.
    foreach (db_all(
        "SELECT t.id, t.transaction_number, t.transaction_date, t.payment_type, t.status, t.amount, m.merchant_name,
                (SELECT COUNT(*) FROM installment_plans p WHERE p.transaction_id = t.id AND p.status <> 'cancelled') AS has_plan
           FROM transactions t LEFT JOIN merchants m ON m.id = t.merchant_id
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
        $lines[] = $line($part, $t['transaction_date'], $text, (float) $t['amount'], $link, 'transaction', $note);
        if ($t['status'] === 'refunded') {
            $lines[] = $line('credits', $t['transaction_date'], 'Refund · ' . $text, (float) $t['amount'], $link, 'transaction');
        }
    }

    // Installment charges of installment purchases, by due date.
    foreach (db_all(
        "SELECT s.installment_number, s.due_date, s.amount_due, p.id AS plan_id, p.number_of_installments, t.id AS txn_id, t.transaction_number,
                (SELECT COALESCE(SUM(x.amount_due), 0) FROM installment_schedules x
                  WHERE x.installment_plan_id = p.id AND x.installment_number > s.installment_number AND x.status NOT IN ('waived', 'cancelled')) AS remaining_after
           FROM installment_schedules s
           JOIN installment_plans p ON p.id = s.installment_plan_id AND p.status <> 'cancelled'
           JOIN transactions t ON t.id = p.transaction_id
          WHERE t.credit_card_id = :c AND t.payment_type = 'installment' AND t.status IN ($charged) AND t.status <> 'refunded'
            AND s.due_date BETWEEN :s AND :e AND s.status NOT IN ('waived', 'cancelled')
          ORDER BY s.due_date, t.transaction_number",
        $p
    ) as $s) {
        $lines[] = $line('installment_charges', $s['due_date'],
            'Installment ' . $s['installment_number'] . ' of ' . $s['number_of_installments'] . ' · ' . $s['transaction_number'],
            (float) $s['amount_due'], url('pages/installment_plans/view.php', ['id' => $s['plan_id']]), 'installment',
            'Not billed yet after this: ' . format_money($s['remaining_after']));
    }

    // Cash loans: monthly installments by due date, fees on the card when the loan started.
    foreach (db_all(
        "SELECT s.installment_number, s.due_date, s.amount_due, l.id AS loan_id, l.loan_reference,
                (SELECT COUNT(*) FROM cash_loan_schedules y WHERE y.cash_loan_id = l.id) AS total_rows,
                (SELECT COALESCE(SUM(x.amount_due), 0) FROM cash_loan_schedules x
                  WHERE x.cash_loan_id = l.id AND x.installment_number > s.installment_number AND x.status NOT IN ('waived', 'cancelled')) AS remaining_after
           FROM cash_loan_schedules s JOIN cash_loans l ON l.id = s.cash_loan_id
          WHERE l.credit_card_id = :c AND l.status IN (" . sql_in_list(CASH_LOAN_CHARGED) . ")
            AND s.due_date BETWEEN :s AND :e AND s.status NOT IN ('waived', 'cancelled')
          ORDER BY s.due_date",
        $p
    ) as $s) {
        $lines[] = $line('installment_charges', $s['due_date'],
            'Cash loan ' . $s['loan_reference'] . ' · installment ' . $s['installment_number'] . ' of ' . $s['total_rows'],
            (float) $s['amount_due'], url('pages/cash_loans/view.php', ['id' => $s['loan_id']]), 'cash_loan',
            'Not billed yet after this: ' . format_money($s['remaining_after']));
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
                url('pages/cash_loans/view.php', ['id' => $loan['id']]), 'cash_loan');
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
        $lines[] = $line('cash_advances', $a['advance_date'], $text, (float) $a['amount'], $link, 'cash_advance');
        if ((float) $a['fee'] > 0) {
            $lines[] = $line('other_charges', $a['advance_date'], $text . ' · fee', (float) $a['fee'], $link, 'cash_advance');
        }
        if ((float) $a['interest'] > 0) {
            $lines[] = $line('interest', $a['advance_date'], $text . ' · interest', (float) $a['interest'], $link, 'cash_advance');
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
            (float) $b['amount'], url('pages/bank_charges/view.php', ['id' => $b['id']]), 'bank_charge');
    }

    // Payments to the bank.
    foreach (db_all('SELECT * FROM credit_card_payments WHERE credit_card_id = :c AND payment_date BETWEEN :s AND :e ORDER BY payment_date', $p) as $pay) {
        $lines[] = $line('payments', $pay['payment_date'], 'Payment' . ($pay['reference_number'] ? ' ' . $pay['reference_number'] : '') . ($pay['payment_type'] ? ' · ' . $pay['payment_type'] : ''),
            (float) $pay['amount'], url('pages/card_payments/view.php', ['id' => $pay['id']]), 'payment');
    }

    usort($lines, static fn ($a, $b) => [$a['date'], $a['part']] <=> [$b['date'], $b['part']]);

    return ['lines' => $lines, 'opening' => round($opening, 2), 'warnings' => array_values(array_unique($warnings))];
}

/**
 * Totals of a period. $previous is the previous statement's balance.
 *
 * @return array<string, float>
 */
function statement_period_totals(array $period, float $previous, float $adjustments): array
{
    $totals = array_fill_keys(array_keys(STATEMENT_PARTS), 0.0);
    foreach ($period['lines'] as $l) {
        $totals[$l['part']] += $l['amount'];
    }
    $totals = array_map(static fn ($v) => round($v, 2), $totals);
    $totals['previous_balance'] = round($previous + $period['opening'], 2);
    $totals['adjustments'] = round($adjustments, 2);

    $balance = $totals['previous_balance'] + $totals['adjustments'];
    foreach (STATEMENT_PARTS as $part => $label) {
        $balance += in_array($part, STATEMENT_CREDIT_PARTS, true) ? -$totals[$part] : $totals[$part];
    }
    $totals['total_amount_due'] = round($balance, 2);

    return $totals;
}

/* ------------------------------------------------------------------
 * Generating and recalculating
 * ------------------------------------------------------------------ */

function statement_previous(array $statement): ?array
{
    return db_row(
        'SELECT * FROM credit_card_statements WHERE credit_card_id = :c AND statement_date < :d ORDER BY statement_date DESC LIMIT 1',
        [':c' => $statement['credit_card_id'], ':d' => $statement['statement_date']]
    );
}

/**
 * Recalculate one generated statement from its records and store the result
 * (totals, minimum due by rule, due date when it was unconfirmed).
 */
function statement_recalculate(array $statement, array $card): array
{
    if ($statement['source'] !== 'generated') {
        return $statement;
    }
    $previous = statement_previous($statement);
    $period = statement_period_lines($card['id'], $statement['period_start_date'], $statement['cutoff_date']);
    $totals = statement_period_totals($period, $previous ? (float) $previous['total_amount_due'] : 0.0, (float) $statement['adjustments']);

    $update = [];
    foreach ($totals as $column => $value) {
        $update[$column] = number_format($value, 2, '.', '');
    }
    if ($statement['minimum_due_status'] !== 'manual') {
        $minimum = card_minimum_due($card, $totals['total_amount_due']);
        $update['minimum_amount_due'] = number_format($minimum ?? 0, 2, '.', '');
        $update['minimum_due_status'] = $minimum === null ? 'unconfirmed' : 'rule';
    }
    if ($statement['due_date_status'] === 'unconfirmed') {
        [$due, $status] = card_due_date($card, $statement['cutoff_date']);
        $update['due_date'] = $due;
        $update['due_date_status'] = $status;
    }

    $changed = array_filter($update, static fn ($v, $k) => (string) $statement[$k] !== (string) $v, ARRAY_FILTER_USE_BOTH);
    if ($changed) {
        db_update('credit_card_statements', $changed + ['updated_at' => date('Y-m-d H:i:s')], $statement['id']);
    }

    return $changed + $statement;
}

/**
 * Create the statements of every closed period of a card that has none yet,
 * then recalculate the card's generated statements (oldest first, so each
 * previous balance is current). Returns the number of new statements.
 */
function statement_sync_card(string $cardId, ?string $today = null): int
{
    $today ??= date('Y-m-d');

    return db_transaction(static function () use ($cardId, $today): int {
        // Locking the card row keeps two requests from generating the same period twice.
        $card = db_row('SELECT * FROM credit_cards WHERE id = :id FOR UPDATE', [':id' => $cardId]);
        if (!$card) {
            return 0;
        }

        $created = 0;
        for ($guard = 0; $guard < 240; $guard++) {
            $start = card_next_period_start($card);
            $period = $start ? card_period_from($card, $start) : null;
            if (!$period || $period[1] >= $today || $period[1] < $period[0]) {
                break;   // the period still open today is not a statement yet
            }
            [$pStart, $cutoff, $statementDate, $dueDate] = $period;
            $statementDate = $statementDate ?: $cutoff;
            [$due, $dueStatus] = $dueDate ? [$dueDate, 'manual'] : card_due_date($card, $cutoff);
            if ($due < $statementDate) {
                [$due, $dueStatus] = [$statementDate, 'unconfirmed'];
            }
            db_exec(
                'INSERT IGNORE INTO card_billing_cycles (id, credit_card_id, cycle_start_date, cycle_end_date, statement_date, payment_due_date)
                 VALUES (:id, :c, :s, :e, :sd, :d)',
                [':id' => new_uuid(), ':c' => $cardId, ':s' => $pStart, ':e' => $cutoff, ':sd' => $statementDate, ':d' => $due]
            );
            if ((int) db_value('SELECT COUNT(*) FROM credit_card_statements WHERE credit_card_id = :c AND statement_date = :d', [':c' => $cardId, ':d' => $statementDate]) > 0) {
                break;   // never a second statement for the same date
            }
            db_insert('credit_card_statements', [
                'id' => new_uuid(), 'credit_card_id' => $cardId, 'period_start_date' => $pStart, 'source' => 'generated',
                'statement_date' => $statementDate, 'cutoff_date' => $cutoff, 'due_date' => $due, 'due_date_status' => $dueStatus,
                'minimum_due_status' => 'unconfirmed',
            ]);
            $created++;
        }

        foreach (db_all("SELECT * FROM credit_card_statements WHERE credit_card_id = :c AND source = 'generated' ORDER BY statement_date", [':c' => $cardId]) as $s) {
            statement_recalculate($s, $card);
        }
        if ($created > 0) {
            log_activity('generate_statements', 'credit_card', $cardId, 'Generated ' . $created . ' statement(s) from the billing cycle');
        }

        return $created;
    });
}

function statement_sync_all(): int
{
    $created = 0;
    foreach (db_all("SELECT id FROM credit_cards WHERE billing_cycle_day IS NOT NULL OR id IN (SELECT credit_card_id FROM card_billing_cycles)") as $c) {
        $created += statement_sync_card($c['id']);
    }

    return $created;
}

/* ------------------------------------------------------------------
 * Lookups used around the system
 * ------------------------------------------------------------------ */

/**
 * Which statement a date on a card belongs to:
 * ['statement' => row|null, 'open' => period|null, 'label' => text].
 */
function statement_for_date(string $cardId, string $date): array
{
    $s = db_row(
        'SELECT * FROM credit_card_statements WHERE credit_card_id = :c
            AND :d BETWEEN COALESCE(period_start_date, cutoff_date, statement_date) AND COALESCE(cutoff_date, statement_date)
          ORDER BY statement_date LIMIT 1',
        [':c' => $cardId, ':d' => $date]
    );
    if ($s) {
        return ['statement' => $s, 'open' => null, 'label' => 'the statement cut off ' . format_date($s['cutoff_date'] ?: $s['statement_date'])];
    }
    $card = db_row('SELECT * FROM credit_cards WHERE id = :c', [':c' => $cardId]);
    $open = $card ? card_open_period($card) : null;
    if ($open && $date >= $open[0] && $date <= $open[1]) {
        return ['statement' => null, 'open' => $open, 'label' => 'the current billing cycle (cutoff ' . format_date($open[1]) . ')'];
    }
    if ($open && $date > $open[1]) {
        return ['statement' => null, 'open' => null, 'label' => 'a later statement (not generated yet)'];
    }

    return ['statement' => null, 'open' => null, 'label' => 'a period with no statement'];
}

/**
 * After a record is added from a statement's reconciliation: say which
 * statement it went to, and warn when that is not the statement being
 * reconciled (it is never forced into it).
 */
function statement_placement_notice(string $cardId, string $date, ?string $expectedStatementId): void
{
    if (!is_valid_uuid((string) $expectedStatementId)) {
        return;
    }
    statement_sync_card($cardId);   // the period of an older date may not have a statement yet
    $where = statement_for_date($cardId, $date);
    if ($where['statement'] && $where['statement']['id'] === $expectedStatementId) {
        flash('info', 'It is included in this statement, which was recalculated.');
    } else {
        flash('warning', 'The date ' . format_date($date) . ' falls in ' . $where['label'] . ', not in the statement you are reconciling — it was added there.');
    }
}

/**
 * Reconciliation state of a statement, recalculated against its current
 * figures: ['status' => open|pending|balanced|difference, 'latest' => row|null,
 * 'difference' => float|null, 'changed_since' => bool].
 */
function statement_reconciliation_state(array $statement): array
{
    $latest = db_row(
        'SELECT r.*, u.username FROM statement_reconciliations r LEFT JOIN users u ON u.id = r.created_by
          WHERE r.statement_id = :s ORDER BY r.created_at DESC, r.id DESC LIMIT 1',
        [':s' => $statement['id']]
    );
    if (!$latest) {
        return ['status' => 'pending', 'latest' => null, 'difference' => null, 'changed_since' => false];
    }
    $difference = round((float) $latest['bank_statement_balance'] - (float) $statement['total_amount_due'], 2);

    return [
        'status'        => abs($difference) < 0.005 ? 'balanced' : 'difference',
        'latest'        => $latest,
        'difference'    => $difference,
        'changed_since' => abs((float) $latest['system_statement_balance'] - (float) $statement['total_amount_due']) >= 0.005,
    ];
}

function reconciliation_badge(string $status): string
{
    return match ($status) {
        'balanced'   => '<span class="status status--success">' . icon('check', 'icon icon--sm') . ' Balanced</span>',
        'difference' => '<span class="status status--danger">' . icon('alert', 'icon icon--sm') . ' Difference detected</span>',
        default      => '<span class="status status--warning">Pending review</span>',
    };
}

/** SQL expression: reconciliation status of statement alias s (for lists). */
function statement_reconciliation_sql(string $alias = 's'): string
{
    return "(SELECT IF(ABS(r.bank_statement_balance - $alias.total_amount_due) < 0.005, 'balanced', 'difference')
               FROM statement_reconciliations r WHERE r.statement_id = $alias.id ORDER BY r.created_at DESC, r.id DESC LIMIT 1)";
}

/**
 * Save the bank's SOA figures for a statement with a snapshot of the system's
 * figures. Nothing in the records is changed. Returns the new row.
 */
function statement_reconcile(array $statement, array $data, string $userId): array
{
    $previous = (float) $statement['previous_balance'];
    $paymentsCredits = round((float) $statement['payments'] + (float) $statement['credits'], 2);
    $systemMinimum = $statement['minimum_due_status'] === 'unconfirmed' ? null : (float) $statement['minimum_amount_due'];
    $difference = round((float) $data['bank_statement_balance'] - (float) $statement['total_amount_due'], 2);

    $row = [
        'id' => new_uuid(), 'statement_id' => $statement['id'],
        'bank_statement_balance' => $data['bank_statement_balance'],
        'bank_minimum_due' => $data['bank_minimum_due'],
        'bank_previous_balance' => $data['bank_previous_balance'],
        'bank_payments_credits' => $data['bank_payments_credits'],
        'system_statement_balance' => number_format((float) $statement['total_amount_due'], 2, '.', ''),
        'system_minimum_due' => $systemMinimum === null ? null : number_format($systemMinimum, 2, '.', ''),
        'system_previous_balance' => number_format($previous, 2, '.', ''),
        'system_payments_credits' => number_format($paymentsCredits, 2, '.', ''),
        'difference' => number_format($difference, 2, '.', ''),
        'status' => abs($difference) < 0.005 ? 'balanced' : 'difference',
        'notes' => $data['notes'],
        'created_by' => $userId,
    ];
    db_insert('statement_reconciliations', $row);
    log_activity('reconcile', 'statement', $statement['id'],
        'SOA ' . format_money($data['bank_statement_balance']) . ' vs system ' . format_money($statement['total_amount_due'])
        . ' → ' . ($row['status'] === 'balanced' ? 'balanced' : 'difference ' . format_money($difference)), null, $row);

    return $row;
}

/**
 * Compare each SOA figure that was entered with the system's figure:
 * list of [label, bank, system, difference|null (null = no system figure)].
 */
function reconciliation_comparison(array $r, array $statement): array
{
    $rows = [['Statement balance (total amount due)', (float) $r['bank_statement_balance'], (float) $statement['total_amount_due']]];
    if ($r['bank_minimum_due'] !== null) {
        $rows[] = ['Minimum amount due', (float) $r['bank_minimum_due'],
            $statement['minimum_due_status'] === 'unconfirmed' ? null : (float) $statement['minimum_amount_due']];
    }
    if ($r['bank_previous_balance'] !== null) {
        $rows[] = ['Previous balance', (float) $r['bank_previous_balance'], (float) $statement['previous_balance']];
    }
    if ($r['bank_payments_credits'] !== null) {
        $rows[] = ['Payments and credits', (float) $r['bank_payments_credits'], (float) $statement['payments'] + (float) $statement['credits']];
    }

    return array_map(static fn ($x) => [$x[0], $x[1], $x[2], $x[2] === null ? null : round($x[1] - $x[2], 2)], $rows);
}

/**
 * After a record's card or date was edited: say when it moved to another
 * statement, and warn when it left (or joined) a statement already reconciled
 * with the bank's SOA - that statement's difference changes.
 */
function statement_move_notice(string $oldCard, string $oldDate, string $newCard, string $newDate): void
{
    if ($oldCard === $newCard && $oldDate === $newDate) {
        return;
    }
    statement_sync_card($newCard);
    $from = statement_for_date($oldCard, $oldDate);
    $to = statement_for_date($newCard, $newDate);
    $fromId = $from['statement']['id'] ?? null;
    $toId = $to['statement']['id'] ?? null;
    if ($fromId === $toId) {
        return;
    }
    $reconciled = array_filter([$fromId, $toId], static fn ($id) => $id && (int) db_value(
        'SELECT COUNT(*) FROM statement_reconciliations WHERE statement_id = :s', [':s' => $id]) > 0);
    $text = 'The record moved from ' . $from['label'] . ' to ' . $to['label'] . '.';
    flash($reconciled ? 'warning' : 'info', $text . ($reconciled
        ? ' A statement already reconciled with the bank SOA is affected: its figures and difference were recalculated — check it again.' : ''));
}
