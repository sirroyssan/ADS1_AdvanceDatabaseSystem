<?php
/**
 * Installment schedules follow the borrowers' payments - no "Pay" button.
 *
 * An obligation's schedule is
 *   - the installment plan of its transaction (installment purchase or a
 *     cash advance given to the borrower), when it is the only obligation on
 *     that transaction, or
 *   - the repayment schedule of the cash loan its allocation belongs to.
 *
 * What has been paid on the schedule is always DERIVED from the borrowers'
 * applied payments (borrower_obligations.total_paid; for a cash loan, all
 * its borrowers together), spread over the installments oldest first
 * (schedule_sync_obligation). It is re-derived whenever a payment is applied
 * or taken back, so the schedule can never disagree with the payments.
 *
 * The amount that reaches a bank installment (a card installment purchase or
 * a cash loan) is recorded as a card payment automatically, referenced by the
 * borrower payment number, so the card's used credit goes down too. Taking
 * the payment back reduces that card payment again.
 */

defined('APP_ROOT') or exit('No direct access.');

const AUTO_CARD_PAYMENT_TYPE = 'Borrower payment (auto)';

/**
 * The schedule an obligation pays, or null.
 *
 * @return array{table: string, parent: string, parent_id: string, card_id: ?string, bank: bool, pool: float}|null
 */
function obligation_schedule_target(array $o): ?array
{
    if ($o['transaction_id']) {
        $t = db_row('SELECT id, credit_card_id, payment_type FROM transactions WHERE id = :t', [':t' => $o['transaction_id']]);
        $plan = db_row("SELECT id FROM installment_plans WHERE transaction_id = :t AND status <> 'cancelled'", [':t' => $o['transaction_id']]);
        $alone = (int) db_value("SELECT COUNT(*) FROM borrower_obligations WHERE transaction_id = :t AND status <> 'cancelled'", [':t' => $o['transaction_id']]) === 1;
        if ($t && $plan && $alone) {
            return [
                'table' => 'installment_schedules', 'parent' => 'installment_plan_id', 'parent_id' => $plan['id'],
                'card_id' => $t['credit_card_id'],
                // A cash advance given to a borrower is repaid to the owner, not billed by the bank in installments.
                'bank' => $t['payment_type'] !== 'cash_advance',
                'pool' => (float) $o['total_paid'],
            ];
        }
    }
    if ($o['cash_loan_allocation_id']) {
        $loan = db_row(
            'SELECT l.id, l.credit_card_id FROM cash_loan_allocations a JOIN cash_loans l ON l.id = a.cash_loan_id WHERE a.id = :a',
            [':a' => $o['cash_loan_allocation_id']]
        );
        if ($loan && (int) db_value('SELECT COUNT(*) FROM cash_loan_schedules WHERE cash_loan_id = :l', [':l' => $loan['id']]) > 0) {
            // Everything the loan's borrowers have paid goes to the loan's installments.
            $pool = (float) db_value(
                "SELECT COALESCE(SUM(o.total_paid), 0) FROM borrower_obligations o JOIN cash_loan_allocations a ON a.id = o.cash_loan_allocation_id
                  WHERE a.cash_loan_id = :l AND o.status <> 'cancelled'",
                [':l' => $loan['id']]
            );
            return [
                'table' => 'cash_loan_schedules', 'parent' => 'cash_loan_id', 'parent_id' => $loan['id'],
                'card_id' => $loan['credit_card_id'], 'bank' => true, 'pool' => $pool,
            ];
        }
    }

    return null;
}

/**
 * Re-derive the paid amounts of an obligation's schedule from the borrowers'
 * payments. With $payment, the change in what reached the bank is recorded as
 * (or taken off) that payment's automatic card payment. Returns the change.
 * Must run inside a transaction.
 */
function schedule_sync_obligation(string $obligationId, ?array $payment = null): float
{
    $delta = schedule_sync_rows($obligationId, $payment);

    // Overdue follows the payment right away (not only on the next page load).
    $o = db_row('SELECT * FROM borrower_obligations WHERE id = :id', [':id' => $obligationId]);
    if ($o) {
        db_update('borrower_obligations', ['overdue_amount' => number_format(obligation_overdue_amount($o), 2, '.', '')], $o['id']);
    }

    return $delta;
}

function schedule_sync_rows(string $obligationId, ?array $payment): float
{
    $o = db_row('SELECT * FROM borrower_obligations WHERE id = :id', [':id' => $obligationId]);
    $target = $o ? obligation_schedule_target($o) : null;
    if (!$target) {
        return 0.0;
    }

    $table = db_identifier($target['table']);
    $rows = db_all(
        "SELECT * FROM $table WHERE " . db_identifier($target['parent']) . " = :p AND status <> 'cancelled' ORDER BY installment_number FOR UPDATE",
        [':p' => $target['parent_id']]
    );

    // Money the borrower (or admin) aimed at a specific installment goes there first;
    // the rest fills the installments oldest first.
    $targeted = [];
    if ($target['table'] === 'installment_schedules') {
        foreach (db_all(
            "SELECT installment_schedule_id AS sid, SUM(allocated_amount) AS amt FROM borrower_payment_allocations
              WHERE obligation_id = :o AND installment_schedule_id IS NOT NULL AND allocation_type <> 'penalty'
              GROUP BY installment_schedule_id",
            [':o' => $obligationId]
        ) as $t) {
            $targeted[$t['sid']] = (float) $t['amt'];
        }
    }
    $plan = [];
    $free = $target['pool'];
    foreach ($rows as $row) {
        $plan[$row['id']] = $row['status'] === 'waived' ? 0.0 : min((float) $row['amount_due'], $targeted[$row['id']] ?? 0.0);
        $free -= $plan[$row['id']];
    }
    $free = max(0, $free);

    $before = 0.0;
    foreach ($rows as $row) {
        $before += (float) $row['amount_paid'];
        if ($row['status'] === 'waived') {
            continue;
        }
        $extra = min((float) $row['amount_due'] - $plan[$row['id']], $free);
        $free -= $extra;
        $paid = round($plan[$row['id']] + $extra, 2);
        if (abs($paid - (float) $row['amount_paid']) < 0.005) {
            continue;
        }
        $update = [
            'amount_paid'      => number_format($paid, 2, '.', ''),
            'remaining_amount' => number_format(max(0, (float) $row['amount_due'] - $paid), 2, '.', ''),
            'status'           => schedule_status_after_payment((float) $row['amount_due'], $paid, $row['due_date']),
        ];
        if ($target['table'] === 'installment_schedules') {
            $update['paid_at'] = $paid >= (float) $row['amount_due'] - 0.004 ? ($row['paid_at'] ?: date('Y-m-d H:i:s')) : null;
        }
        db_update($target['table'], $update, $row['id']);
    }
    $after = (float) db_value("SELECT COALESCE(SUM(amount_paid), 0) FROM $table WHERE " . db_identifier($target['parent']) . ' = :p', [':p' => $target['parent_id']]);
    $delta = round($after - $before, 2);

    // Plan / loan complete once nothing is left.
    $open = (int) db_value("SELECT COUNT(*) FROM $table WHERE " . db_identifier($target['parent']) . " = :p AND status NOT IN ('paid', 'waived', 'cancelled')", [':p' => $target['parent_id']]);
    if ($target['table'] === 'installment_schedules') {
        db_exec("UPDATE installment_plans SET status = :s WHERE id = :id AND status IN ('active', 'completed')",
            [':s' => $open === 0 ? 'completed' : 'active', ':id' => $target['parent_id']]);
    } elseif ($open === 0) {
        db_exec("UPDATE cash_loans SET status = 'completed' WHERE id = :id AND status IN ('accepted', 'disbursed', 'active')", [':id' => $target['parent_id']]);
    }

    if ($payment !== null && $target['bank'] && $target['card_id'] && abs($delta) >= 0.005) {
        auto_card_payment_adjust($target['card_id'], $payment, $delta);
    }

    return $delta;
}

/**
 * Add to / take from the automatic card payment of a borrower payment.
 */
function auto_card_payment_adjust(string $cardId, array $payment, float $delta): void
{
    if ($delta > 0) {
        db_insert('credit_card_payments', [
            'id'               => new_uuid(),
            'credit_card_id'   => $cardId,
            'payment_date'     => $payment['payment_date'] ?? date('Y-m-d'),
            'amount'           => number_format($delta, 2, '.', ''),
            'payment_type'     => AUTO_CARD_PAYMENT_TYPE,
            'reference_number' => $payment['payment_number'],
            'notes'            => 'Recorded automatically: borrower payment ' . $payment['payment_number'] . ' applied to card installments.',
        ]);
    } else {
        $left = -$delta;
        foreach (db_all(
            'SELECT id, amount FROM credit_card_payments WHERE credit_card_id = :c AND reference_number = :r AND payment_type = :t ORDER BY created_at DESC FOR UPDATE',
            [':c' => $cardId, ':r' => $payment['payment_number'], ':t' => AUTO_CARD_PAYMENT_TYPE]
        ) as $cp) {
            if ($left <= 0.004) {
                break;
            }
            $take = min($left, (float) $cp['amount']);
            $rest = round((float) $cp['amount'] - $take, 2);
            $rest <= 0.004
                ? db_exec('DELETE FROM credit_card_payments WHERE id = :id', [':id' => $cp['id']])
                : db_update('credit_card_payments', ['amount' => number_format($rest, 2, '.', '')], $cp['id']);
            $left -= $take;
        }
    }
    card_usage_sync([$cardId]);
}

/**
 * Overdue part of an obligation, from the borrower's own payments:
 * what was due by today minus what the borrower paid (never more than the
 * outstanding balance). Without a schedule: the whole outstanding once the
 * due date has passed.
 */
function obligation_overdue_amount(array $o, ?string $today = null): float
{
    $today ??= date('Y-m-d');
    $outstanding = (float) $o['outstanding_amount'];
    if ($outstanding <= 0.004 || in_array($o['status'], ['cancelled', 'paid', 'written_off'], true)) {
        return 0.0;
    }

    $target = obligation_schedule_target($o);
    if ($target) {
        $table = db_identifier($target['table']);
        $sums = db_row(
            "SELECT COALESCE(SUM(amount_due), 0) AS total, COALESCE(SUM(IF(due_date < :today, amount_due, 0)), 0) AS due
               FROM $table WHERE " . db_identifier($target['parent']) . " = :p AND status NOT IN ('waived', 'cancelled')",
            [':today' => $today, ':p' => $target['parent_id']]
        );
        // A cash loan is shared: this borrower owes their share of each installment.
        $share = $target['table'] === 'cash_loan_schedules' && (float) $sums['total'] > 0
            ? min(1, (float) $o['total_amount_due'] / (float) $sums['total']) : 1.0;
        $dueByToday = (float) $sums['due'] * $share;

        return round(max(0, min($outstanding, $dueByToday - (float) $o['total_paid'])), 2);
    }

    return $o['due_date'] && $o['due_date'] < $today ? round($outstanding, 2) : 0.0;
}

/**
 * The monthly schedule of one obligation, for display: the installment plan of
 * its transaction, or its share of the cash loan's installments. A shared cash
 * loan shows this borrower's part of each installment, filled oldest-first
 * from what this obligation has been paid. Returns [rows, title, note].
 */
function obligation_schedule_view(array $o): array
{
    $target = obligation_schedule_target($o);
    if (!$target) {
        return [[], '', ''];
    }
    $table = db_identifier($target['table']);
    $rows = db_all(
        "SELECT * FROM $table WHERE " . db_identifier($target['parent']) . ' = :p ORDER BY installment_number',
        [':p' => $target['parent_id']]
    );
    if ($target['table'] === 'installment_schedules') {
        return [$rows, 'Installment schedule', ''];
    }

    $counted = array_filter($rows, static fn ($r) => !in_array($r['status'], ['waived', 'cancelled'], true));
    $loanTotal = array_sum(array_column($counted, 'amount_due'));
    $share = $loanTotal > 0 ? min(1, (float) $o['total_amount_due'] / $loanTotal) : 1.0;
    $pool = (float) $o['total_paid'];
    $lastKey = array_key_last($counted);
    $left = ['principal_amount' => 0.0, 'interest_amount' => 0.0, 'fee_amount' => 0.0];
    foreach ($left as $k => $v) {
        $left[$k] = round(array_sum(array_column($counted, $k)) * $share, 2);
    }

    foreach ($rows as $i => $r) {
        if (in_array($r['status'], ['waived', 'cancelled'], true)) {
            continue;
        }
        foreach ($left as $k => $v) {
            // The last installment takes the rounding so the parts add up to the share.
            $part = $i === $lastKey ? $v : round((float) $r[$k] * $share, 2);
            $rows[$i][$k] = $part;
            $left[$k] = round($v - $part, 2);
        }
        $due = round($rows[$i]['principal_amount'] + $rows[$i]['interest_amount'] + $rows[$i]['fee_amount'], 2);
        $paid = round(min($due, $pool), 2);
        $pool = round($pool - $paid, 2);
        $rows[$i]['amount_due'] = $due;
        $rows[$i]['amount_paid'] = $paid;
        $rows[$i]['remaining_amount'] = round($due - $paid, 2);
        $rows[$i]['status'] = schedule_status_after_payment($due, $paid, $r['due_date']);
    }

    $note = $share < 0.9999
        ? 'This cash loan is shared. The amounts are your share (' . rtrim(rtrim(number_format($share * 100, 2), '0'), '.') . '%) of each monthly installment; payments fill the oldest installment first.'
        : 'Monthly installments of the cash loan. Payments fill the oldest installment first.';

    return [$rows, 'Monthly schedule', $note];
}

/**
 * Store the live overdue amount on every open obligation (cheap; runs with
 * the status refresh on each signed-in page load).
 */
function obligation_refresh_overdue(): void
{
    foreach (db_all("SELECT * FROM borrower_obligations WHERE status NOT IN ('cancelled', 'written_off')") as $o) {
        $overdue = number_format(obligation_overdue_amount($o), 2, '.', '');
        if ($overdue !== number_format((float) $o['overdue_amount'], 2, '.', '')) {
            db_update('borrower_obligations', ['overdue_amount' => $overdue], $o['id']);
        }
    }
}

/**
 * One-time upgrade: re-derive every schedule from the borrowers' payments,
 * replacing amounts that were typed with the old Pay button. No card
 * payments are created or removed by this step.
 */
function schedule_sync_upgrade(): void
{
    $marker = APP_ROOT . '/storage/upgrade_schedule_sync.done';
    if (is_file($marker)) {
        return;
    }
    $changed = 0;
    db_transaction(static function () use (&$changed) {
        foreach (db_all("SELECT id FROM borrower_obligations WHERE status <> 'cancelled' AND (transaction_id IS NOT NULL OR cash_loan_allocation_id IS NOT NULL)") as $o) {
            if (abs(schedule_sync_obligation($o['id'])) >= 0.005) {
                $changed++;
            }
        }
    });
    if ($changed > 0) {
        log_activity('schedule_sync', 'system', null, 'Upgrade: ' . $changed . ' installment schedule(s) re-derived from borrower payments (Pay button removed)');
    }
    @file_put_contents($marker, date('c'), LOCK_EX);
}
