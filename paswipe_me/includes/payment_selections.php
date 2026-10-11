<?php
/**
 * What a borrower says a payment is for (approved schema change: the table
 * borrower_payment_selections - see db/upgrade_payment_selections.sql).
 *
 *   - In the portal the borrower ticks one or more items (an obligation, or
 *     one installment of it) and types the amount for each. The total chosen
 *     can be less than the amount paid, never more.
 *   - When the owner/admin approves the payment, it is applied exactly as
 *     chosen (each item: late charges first, then interest, then principal;
 *     an installment choice is credited to that installment).
 *   - Anything paid beyond the choices stays unallocated: the owner/admin
 *     posts it with Allocate on the payment page. Nothing is applied to
 *     "the oldest item" automatically any more.
 */

defined('APP_ROOT') or exit('No direct access.');

const PAYMENT_SELECTIONS_MARKER = 'storage/upgrade_payment_selections.done';

/**
 * Create the table on first use (same SQL as db/upgrade_payment_selections.sql).
 */
function payment_selections_upgrade(): void
{
    if (marker_exists(PAYMENT_SELECTIONS_MARKER)) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS borrower_payment_selections (
            id CHAR(36) NOT NULL PRIMARY KEY,
            borrower_payment_id CHAR(36) NOT NULL,
            obligation_id CHAR(36) NOT NULL,
            installment_schedule_id CHAR(36) NULL,
            amount DECIMAL(15,2) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_selection_payment (borrower_payment_id),
            CONSTRAINT fk_selection_payment FOREIGN KEY (borrower_payment_id) REFERENCES borrower_payments(id) ON DELETE CASCADE,
            CONSTRAINT fk_selection_obligation FOREIGN KEY (obligation_id) REFERENCES borrower_obligations(id),
            CONSTRAINT fk_selection_schedule FOREIGN KEY (installment_schedule_id) REFERENCES installment_schedules(id),
            CONSTRAINT chk_selection_amount CHECK (amount > 0)
        )'
    );
    marker_write(PAYMENT_SELECTIONS_MARKER);
}

/**
 * Everything a borrower can choose to pay, grouped by obligation:
 * each open installment of an installment purchase, or the item as a whole.
 *
 * @return array<int, array{obligation: array, penalties: float, owed: float, rows: array}>
 */
function payable_items(string $borrowerId): array
{
    $groups = [];
    $obligations = db_all(
        "SELECT o.*, COALESCE(pen.penalties_unpaid, 0) AS penalties_unpaid
           FROM borrower_obligations o " . penalty_totals_join() . "
          WHERE o.borrower_id = :b AND o.status NOT IN ('cancelled', 'written_off')
            AND (o.outstanding_amount > 0 OR COALESCE(pen.penalties_unpaid, 0) > 0)
          ORDER BY o.due_date IS NULL, o.due_date, o.obligation_date",
        [':b' => $borrowerId]
    );

    foreach ($obligations as $o) {
        $owed = round((float) $o['outstanding_amount'] + (float) $o['penalties_unpaid'], 2);
        $rows = [];
        $target = obligation_schedule_target($o);
        if ($target && $target['table'] === 'installment_schedules') {
            foreach (db_all(
                "SELECT id, installment_number, due_date, remaining_amount FROM installment_schedules
                  WHERE installment_plan_id = :p AND status NOT IN ('paid', 'waived', 'cancelled') AND remaining_amount > 0
                  ORDER BY installment_number",
                [':p' => $target['parent_id']]
            ) as $s) {
                $rows[] = [
                    'key' => $o['id'] . ':' . $s['id'], 'obligation_id' => $o['id'], 'schedule_id' => $s['id'],
                    'label' => 'Installment #' . $s['installment_number'], 'due_date' => $s['due_date'],
                    'owed' => (float) $s['remaining_amount'], 'overdue' => $s['due_date'] < date('Y-m-d'),
                ];
            }
        }
        if (!$rows || (float) $o['penalties_unpaid'] > 0) {
            // The item as a whole (also the way to pay its late charges).
            array_unshift($rows, [
                'key' => $o['id'] . ':', 'obligation_id' => $o['id'], 'schedule_id' => null,
                'label' => $rows ? 'Late charges / any part of this item' : 'This item', 'due_date' => $o['due_date'],
                'owed' => $owed, 'overdue' => (float) $o['overdue_amount'] > 0,
            ]);
        }
        // Without an installment plan the item is paid as a whole (oldest month first); its open months are shown for reference.
        $months = $target && $target['table'] === 'installment_schedules' ? [] : array_values(array_filter(
            obligation_monthly_rows($o), static fn ($s) => (float) $s['remaining_amount'] > 0.004
        ));
        $months = count($months) > 1 || ($months && $months[0]['due_date'] !== $o['due_date']) ? $months : [];
        $groups[] = ['obligation' => $o, 'penalties' => (float) $o['penalties_unpaid'], 'owed' => $owed, 'rows' => $rows, 'months' => $months];
    }

    return $groups;
}

/**
 * Read and validate the borrower's choices from the form:
 * select[key]=1, select_amount[key]=amount. Returns [rows, error|null].
 */
function payment_selections_from_input(array $input, string $borrowerId, float $paid): array
{
    $index = [];
    foreach (payable_items($borrowerId) as $group) {
        foreach ($group['rows'] as $row) {
            $index[$row['key']] = $row + ['obligation_number' => $group['obligation']['obligation_number']];
        }
    }

    $chosen = [];
    $total = 0.0;
    foreach ((array) ($input['select'] ?? []) as $key => $on) {
        if (!is_string($key) || !isset($index[$key]) || $on !== '1') {
            continue;
        }
        $raw = str_replace([',', ' ', '₱'], '', (string) ($input['select_amount'][$key] ?? ''));
        if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $raw) || (float) $raw <= 0) {
            return [[], 'Enter an amount for ' . $index[$key]['obligation_number'] . ' — ' . $index[$key]['label'] . '.'];
        }
        $amount = round((float) $raw, 2);
        if ($amount > $index[$key]['owed'] + 0.004) {
            return [[], $index[$key]['obligation_number'] . ' — ' . $index[$key]['label'] . ' only has ' . format_money($index[$key]['owed']) . ' to pay.'];
        }
        $chosen[] = ['obligation_id' => $index[$key]['obligation_id'], 'installment_schedule_id' => $index[$key]['schedule_id'], 'amount' => $amount];
        $total += $amount;
    }
    if ($total > $paid + 0.004) {
        return [[], 'You assigned ' . format_money($total) . ', which is more than the ' . format_money($paid) . ' you paid.'];
    }

    return [$chosen, null];
}

function payment_selections_save(string $paymentId, array $rows): void
{
    db_exec('DELETE FROM borrower_payment_selections WHERE borrower_payment_id = :p', [':p' => $paymentId]);
    foreach ($rows as $row) {
        db_insert('borrower_payment_selections', ['id' => new_uuid(), 'borrower_payment_id' => $paymentId] + $row + [
            'amount' => number_format($row['amount'], 2, '.', ''),
        ]);
    }
}

/**
 * The borrower's choices for a payment, with readable labels.
 */
function payment_selections(string $paymentId): array
{
    return db_all(
        'SELECT sel.*, o.obligation_number, o.description, s.installment_number, s.due_date AS installment_due
           FROM borrower_payment_selections sel
           JOIN borrower_obligations o ON o.id = sel.obligation_id
           LEFT JOIN installment_schedules s ON s.id = sel.installment_schedule_id
          WHERE sel.borrower_payment_id = :p
          ORDER BY o.due_date, s.installment_number',
        [':p' => $paymentId]
    );
}

function payment_selection_label(array $sel): string
{
    return $sel['obligation_number'] . ' — ' . ($sel['installment_number'] ? 'Installment #' . $sel['installment_number'] : 'item / late charges');
}

/**
 * On approval: apply the payment exactly as the borrower chose (each amount
 * capped at what is still owed). Returns [applied total, notes].
 */
function payment_apply_selections(string $paymentId, string $userId): array
{
    $payment = payment_find($paymentId);
    if (!$payment || $payment['status'] !== 'approved') {
        return [0.0, []];
    }
    $applied = 0.0;
    $notes = [];
    foreach (payment_selections($paymentId) as $sel) {
        $fresh = payment_find($paymentId);
        $left = round((float) $fresh['amount'] - (float) $fresh['allocated'], 2);
        $owed = (float) db_value(
            'SELECT o.outstanding_amount + COALESCE((SELECT SUM(remaining_amount) FROM applied_penalties WHERE obligation_id = o.id), 0)
               FROM borrower_obligations o WHERE o.id = :o',
            [':o' => $sel['obligation_id']]
        );
        $amount = round(min((float) $sel['amount'], $left, $owed), 2);
        if ($amount <= 0.004) {
            $notes[] = payment_selection_label($sel) . ': nothing left to pay';
            continue;
        }
        $error = payment_allocate($fresh, $sel['obligation_id'], $sel['installment_schedule_id'], $amount, 'selected', $userId);
        if ($error !== null) {
            $notes[] = payment_selection_label($sel) . ': ' . $error;
            continue;
        }
        $applied += $amount;
    }

    if ($applied > 0) {
        log_activity('allocate', 'borrower_payment', $paymentId, 'Applied ' . format_money($applied) . ' of ' . $payment['payment_number'] . ' as the borrower selected', null, null, $userId);
        notify_borrower($payment['borrower_id'], 'payment_applied', 'Payment ' . $payment['payment_number'] . ' applied',
            format_money($applied) . ' of your payment was applied to the items you chose.', 'borrower_payment', $paymentId);
    }

    return [round($applied, 2), $notes];
}
