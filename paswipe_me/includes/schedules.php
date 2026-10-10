<?php
/**
 * Repayment schedule helpers shared by Installment Plans (installment_schedules)
 * and Cash Loans (cash_loan_schedules). Both tables have the same amount columns.
 */

defined('APP_ROOT') or exit('No direct access.');

/**
 * Due date of installment $index (0-based) counted from the first due date.
 * Monthly dates keep the same day, clamped to the month's last day (Jan 31 -> Feb 28).
 */
function schedule_due_date(string $firstDue, int $index, string $frequency): string
{
    $first = new DateTimeImmutable($firstDue);

    if ($frequency === 'weekly' || $frequency === 'biweekly') {
        $days = ($frequency === 'weekly' ? 7 : 14) * $index;
        return $first->modify('+' . $days . ' days')->format('Y-m-d');
    }

    $months = ($frequency === 'quarterly' ? 3 : 1) * $index;
    $day = (int) $first->format('j');
    $target = $first->modify('first day of this month')->modify('+' . $months . ' months');
    $lastDay = (int) $target->format('t');

    return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), min($day, $lastDay))->format('Y-m-d');
}

/**
 * Split principal, interest and fees evenly; the last row absorbs rounding.
 *
 * @return array<int, array{installment_number:int, due_date:string, principal_amount:string, interest_amount:string, fee_amount:string, amount_due:string, remaining_amount:string}>
 */
function build_schedule(float $principal, float $interest, float $fees, int $count, string $firstDue, string $frequency): array
{
    $rows = [];
    $split = static function (float $total, int $count): array {
        $cents = (int) round($total * 100);
        $base = intdiv($cents, $count);
        $parts = array_fill(0, $count, $base);
        $parts[$count - 1] += $cents - $base * $count;
        return array_map(static fn ($c) => $c / 100, $parts);
    };

    $p = $split($principal, $count);
    $i = $split($interest, $count);
    $f = $split($fees, $count);

    for ($n = 0; $n < $count; $n++) {
        $due = $p[$n] + $i[$n] + $f[$n];
        $rows[] = [
            'installment_number' => $n + 1,
            'due_date'           => schedule_due_date($firstDue, $n, $frequency),
            'principal_amount'   => number_format($p[$n], 2, '.', ''),
            'interest_amount'    => number_format($i[$n], 2, '.', ''),
            'fee_amount'         => number_format($f[$n], 2, '.', ''),
            'amount_due'         => number_format($due, 2, '.', ''),
            'remaining_amount'   => number_format($due, 2, '.', ''),
        ];
    }

    return $rows;
}

/**
 * Status after a payment is applied to a schedule row.
 */
function schedule_status_after_payment(float $amountDue, float $amountPaid, string $dueDate): string
{
    if ($amountPaid >= $amountDue - 0.004) {
        return 'paid';
    }
    $today = date('Y-m-d');
    if ($dueDate < $today) {
        return 'overdue';            // past due and not fully paid (Paid column shows any part payment)
    }
    if ($amountPaid > 0) {
        return 'partially_paid';
    }

    return $dueDate === $today ? 'due' : 'upcoming';
}

/**
 * Keep every installment and obligation status in line with today's date:
 * unpaid past-due rows become overdue, rows due today become due, and an
 * obligation is overdue while it (or one of its installments) is past due.
 * Cheap UPDATEs; runs on every signed-in page load (penalty_run_if_due).
 */
function schedule_refresh_statuses(): void
{
    foreach (['installment_schedules', 'cash_loan_schedules'] as $table) {
        db_exec("UPDATE $table SET status = 'overdue'
                  WHERE status IN ('upcoming', 'due', 'partially_paid') AND remaining_amount > 0 AND due_date < CURDATE()");
        db_exec("UPDATE $table SET status = 'due'
                  WHERE status = 'upcoming' AND remaining_amount > 0 AND due_date = CURDATE()");
        db_exec("UPDATE $table SET status = IF(amount_paid > 0, 'partially_paid', IF(due_date = CURDATE(), 'due', 'upcoming'))
                  WHERE status = 'overdue' AND remaining_amount > 0 AND due_date >= CURDATE()");
    }

    // overdue_amount is refreshed first (obligation_refresh_overdue), so a missed month counts too.
    $pastDue = "(o.due_date < CURDATE() OR o.overdue_amount > 0 OR EXISTS (
                    SELECT 1 FROM installment_plans p JOIN installment_schedules s ON s.installment_plan_id = p.id
                     WHERE p.transaction_id = o.transaction_id AND p.status <> 'cancelled' AND s.status = 'overdue'
                       AND (SELECT COUNT(*) FROM borrower_obligations x WHERE x.transaction_id = o.transaction_id AND x.status <> 'cancelled') = 1))";
    db_exec("UPDATE borrower_obligations o SET o.status = 'overdue'
              WHERE o.status IN ('pending', 'active', 'partially_paid') AND o.outstanding_amount > 0 AND $pastDue");
    db_exec("UPDATE borrower_obligations o SET o.status = IF(o.total_paid > 0, 'partially_paid', 'active')
              WHERE o.status = 'overdue' AND o.outstanding_amount > 0 AND NOT $pastDue");
}

/**
 * Schedule table with per-row "record payment" and status forms.
 *
 * @param string $actionUrl POST target that handles record_payment / schedule_status
 * @param array  $hidden    hidden fields identifying the parent record
 */
/**
 * With $cardPaymentOption, each Pay form has an "Also a card payment" box
 * (checked): the amount is then also saved in credit_card_payments, which is
 * what lowers the card's used credit.
 */
function render_schedule_table(array $rows, string $actionUrl, array $hidden, bool $canEdit, bool $cardPaymentOption = false): string
{
    if (!$rows) {
        return '<p class="empty-inline">No schedule generated.</p>';
    }

    $totals = ['amount_due' => 0, 'amount_paid' => 0, 'remaining_amount' => 0];
    $html = '<div class="table-wrap"><table class="table table--cards table--compact"><thead><tr>'
        . '<th scope="col" class="num">#</th><th scope="col">Due date</th><th scope="col" class="num">Principal</th>'
        . '<th scope="col" class="num">Interest + fees</th><th scope="col" class="num">Amount due</th><th scope="col" class="num">Paid</th>'
        . '<th scope="col" class="num">Remaining</th><th scope="col">Status</th>'
        . ($canEdit ? '<th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>' : '')
        . '</tr></thead><tbody>';

    foreach ($rows as $row) {
        foreach ($totals as $key => $value) {
            $totals[$key] = $value + (float) $row[$key];
        }
        $isOpen = !in_array($row['status'], ['paid', 'waived', 'cancelled'], true);

        $html .= '<tr>'
            . '<td data-label="#" class="num">' . e($row['installment_number']) . '</td>'
            . '<td data-label="Due date">' . e(format_date($row['due_date'])) . '</td>'
            . '<td data-label="Principal" class="num">' . e(format_money($row['principal_amount'])) . '</td>'
            . '<td data-label="Interest + fees" class="num">' . e(format_money((float) $row['interest_amount'] + (float) $row['fee_amount'])) . '</td>'
            . '<td data-label="Amount due" class="num">' . e(format_money($row['amount_due'])) . '</td>'
            . '<td data-label="Paid" class="num">' . e(format_money($row['amount_paid'])) . '</td>'
            . '<td data-label="Remaining" class="num">' . e(format_money($row['remaining_amount'])) . '</td>'
            . '<td data-label="Status">' . badge($row['status']) . '</td>';

        if ($canEdit) {
            $html .= '<td class="actions no-print">';
            if ($isOpen) {
                $html .= '<form method="post" action="' . e($actionUrl) . '" class="schedule-pay">' . csrf_field();
                foreach ($hidden as $name => $value) {
                    $html .= '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
                }
                $html .= '<input type="hidden" name="action" value="record_payment"><input type="hidden" name="schedule_id" value="' . e($row['id']) . '">'
                    . '<label class="visually-hidden" for="pay-' . e($row['id']) . '">Amount paid for installment ' . e($row['installment_number']) . '</label>'
                    . '<input type="text" inputmode="decimal" class="input-num" id="pay-' . e($row['id']) . '" name="amount" value="' . e($row['remaining_amount']) . '" required>'
                    . '<button type="submit" class="btn btn--sm btn--primary" data-loading-text="…">' . icon('check') . ' <span class="btn__label">Pay</span></button>'
                    . ($cardPaymentOption
                        ? '<label class="schedule-pay__card" title="Also saves this amount as a payment to the bank, which lowers the card\'s used credit. Untick it if you record the whole statement payment in Card Payments.">'
                          . '<input type="checkbox" name="card_payment" value="1" checked> Also a card payment</label>'
                        : '')
                    . '</form>';
                $html .= post_button($actionUrl, $hidden + ['action' => 'schedule_status', 'schedule_id' => $row['id'], 'status' => 'waived'], 'Waive installment ' . $row['installment_number'], 'x',
                    'icon-button', 'Waive the remaining amount of installment ' . $row['installment_number'] . '?', true, 'Waive installment?');
            }
            $html .= '</td>';
        }
        $html .= '</tr>';
    }

    $html .= '</tbody><tfoot><tr><td colspan="4">Total</td><td class="num">' . e(format_money($totals['amount_due'])) . '</td>'
        . '<td class="num">' . e(format_money($totals['amount_paid'])) . '</td><td class="num">' . e(format_money($totals['remaining_amount'])) . '</td>'
        . '<td' . ($canEdit ? ' colspan="2"' : '') . '></td></tr></tfoot></table></div>';

    return $html;
}

/**
 * Apply a payment to one schedule row of $table (installment_schedules or cash_loan_schedules).
 * Returns an error message or null on success.
 */
function apply_schedule_payment(string $table, string $parentColumn, string $parentId, string $scheduleId, string $rawAmount): ?string
{
    $amount = str_replace([',', ' ', '₱'], '', $rawAmount);
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount) || (float) $amount <= 0) {
        return 'Enter a payment amount greater than zero.';
    }

    return db_transaction(function () use ($table, $parentColumn, $parentId, $scheduleId, $amount) {
        $row = db_row(
            'SELECT * FROM ' . db_identifier($table) . ' WHERE id = :id AND ' . db_identifier($parentColumn) . ' = :p FOR UPDATE',
            [':id' => $scheduleId, ':p' => $parentId]
        );
        if (!$row) {
            return 'Installment not found.';
        }
        if (in_array($row['status'], ['paid', 'waived', 'cancelled'], true)) {
            return 'This installment is already ' . $row['status'] . '.';
        }
        if ((float) $amount > (float) $row['remaining_amount'] + 0.004) {
            return 'The payment is more than the remaining ' . format_money($row['remaining_amount']) . '.';
        }

        $paid = (float) $row['amount_paid'] + (float) $amount;
        $remaining = max(0, (float) $row['amount_due'] - $paid);
        $status = schedule_status_after_payment((float) $row['amount_due'], $paid, $row['due_date']);

        $update = [
            'amount_paid'      => number_format($paid, 2, '.', ''),
            'remaining_amount' => number_format($remaining, 2, '.', ''),
            'status'           => $status,
        ];
        if ($table === 'installment_schedules' && $status === 'paid') {
            $update['paid_at'] = date('Y-m-d H:i:s');
        }
        db_update($table, $update, $row['id']);

        return null;
    });
}

/**
 * Move an existing schedule to new due dates after its first due date or
 * frequency changed: row n is due on (first due date + n−1 periods). Amounts
 * and what was already paid stay exactly as they are; each open row's status
 * follows its new date. Returns the new last due date (null: no rows).
 */
function schedule_shift_dates(string $table, string $parentColumn, string $parentId, string $firstDue, ?string $frequency): ?string
{
    $rows = db_all(
        'SELECT id, installment_number, amount_due, amount_paid, status FROM ' . db_identifier($table)
        . ' WHERE ' . db_identifier($parentColumn) . ' = :p ORDER BY installment_number',
        [':p' => $parentId]
    );
    $last = null;
    foreach ($rows as $r) {
        $due = schedule_due_date($firstDue, (int) $r['installment_number'] - 1, $frequency ?: 'monthly');
        $update = ['due_date' => $due, 'updated_at' => date('Y-m-d H:i:s')];
        if (!in_array($r['status'], ['waived', 'cancelled'], true)) {
            $update['status'] = schedule_status_after_payment((float) $r['amount_due'], (float) $r['amount_paid'], $due);
        }
        db_update($table, $update, $r['id']);
        $last = $due;
    }

    return $last;
}

/**
 * After a schedule moved: borrower obligations that ended on the old last
 * date now end on the new one, and their paid / overdue figures are re-derived.
 */
function schedule_move_obligations(array $obligationIds, ?string $oldEnd, ?string $newEnd): void
{
    foreach ($obligationIds as $oid) {
        if ($newEnd && $oldEnd && db_value('SELECT due_date FROM borrower_obligations WHERE id = :id', [':id' => $oid]) === $oldEnd) {
            db_update('borrower_obligations', ['due_date' => $newEnd], $oid);
        }
        schedule_sync_obligation($oid);
    }
}

/** Fields of the "Change due dates" form of a schedule. */
function schedule_shift_fields(): array
{
    return [
        'first_due_date' => ['label' => 'New first due date', 'type' => 'date', 'required' => true,
                             'help' => 'Installment 1 is due on this date; every next one on the same day of the following month(s).'],
        'frequency'      => ['label' => 'Frequency', 'type' => 'select', 'options' => array_intersect_key(enum_options(ENUM_FREQUENCY), array_flip(['monthly', 'quarterly', 'weekly', 'biweekly'])),
                             'required' => true, 'initial' => 'monthly'],
    ];
}

/**
 * The inline form (opened in the modal) that moves a schedule's due dates.
 * Amounts and payments are kept.
 */
function render_schedule_shift_form(string $actionUrl, string $id, string $firstDue, ?string $frequency, int $rows): string
{
    $fields = schedule_shift_fields();

    return '<form method="post" action="' . e($actionUrl) . '" class="inline-panel no-print" id="shift-dates" hidden novalidate data-validate>'
        . csrf_field() . '<input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="action" value="shift_dates">'
        . '<p class="field-help">Moves all ' . e($rows) . ' installments to the new monthly due day. Amounts and what was already paid stay exactly the same;'
        . ' each status follows its new date, and borrower obligations ending on the old last date move with it.</p>'
        . render_fields($fields, form_defaults($fields, ['first_due_date' => $firstDue, 'frequency' => $frequency ?: 'monthly']), [])
        . '<div class="inline-panel__actions"><button type="button" class="btn btn--outline" data-toggle-panel="shift-dates">Cancel</button>'
        . '<button type="submit" class="btn btn--primary" data-loading-text="Updating…">' . icon('repeat') . ' <span class="btn__label">Update due dates</span></button></div>'
        . '</form>';
}
