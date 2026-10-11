<?php
/**
 * Borrower Payments module (tables: borrower_payments, borrower_payment_allocations,
 * payment_verifications, payment_verification_logs, payment_attachments).
 *
 * Workflow (schema note #3: a payment is not a ledger entry until approved):
 *   submitted -> under_review -> approved | rejected | correction_required
 *   correction_required -> (edited) -> submitted
 *   any open status -> cancelled
 * Only APPROVED payments can be allocated to obligations. Allocations update
 * the obligation's total_paid/outstanding inside one database transaction;
 * the schema trigger blocks allocating more than the payment amount.
 * No delete: payments are financial history. Cancel or reject instead.
 */

defined('APP_ROOT') or exit('No direct access.');

const PAYMENT_EDITABLE_STATUSES = ['submitted', 'correction_required'];
const PAYMENT_FINAL_STATUSES = ['approved', 'rejected', 'cancelled'];
const PAYMENT_TRANSITIONS = [
    'submitted'           => ['under_review', 'approved', 'rejected', 'correction_required', 'cancelled'],
    'under_review'        => ['approved', 'rejected', 'correction_required', 'cancelled'],
    'correction_required' => ['under_review', 'cancelled'],
];

function payment_fields(?array $current = null): array
{
    [$methodOptions, $methodData] = payment_method_options(false, $current['payment_method_id'] ?? null);

    return [
        'borrower_id'       => ['label' => 'Borrower', 'type' => 'select', 'options' => borrower_options($current['borrower_id'] ?? null), 'required' => true, 'empty_label' => 'Select borrower'],
        'payment_method_id' => ['label' => 'Payment method', 'type' => 'select', 'options' => $methodOptions, 'option_data' => $methodData,
                                'attrs' => ['data-depends-on' => 'f_borrower_id', 'data-depends-always' => 'owner'], 'empty_label' => '— Not specified —',
                                'help' => 'The borrower\'s own accounts and the owner\'s receiving accounts are listed.'],
        'payment_date'      => ['label' => 'Payment date', 'type' => 'date', 'required' => true, 'initial' => date('Y-m-d')],
        'amount'            => ['label' => 'Amount', 'type' => 'decimal', 'required' => true, 'min_exclusive' => 0],
        'reference_number'  => ['label' => 'Reference number', 'type' => 'text', 'max' => 150, 'help' => 'e.g. GCash reference no.'],
        'notes'             => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 3],
    ];
}

function payment_validate(array $fields, array $input, ?string $id = null, ?string $borrowerId = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);
    $borrowerId = $data['borrower_id'] ?? $borrowerId;

    if ($data['payment_date'] && $data['payment_date'] > date('Y-m-d')) {
        $errors['payment_date'] = 'Payment date cannot be in the future.';
    }
    if ($data['payment_method_id'] && $borrowerId) {
        $owner = db_value('SELECT borrower_id FROM payment_methods WHERE id = :id', [':id' => $data['payment_method_id']]);
        if ($owner !== null && $owner !== $borrowerId) {
            $errors['payment_method_id'] = 'This payment method belongs to another borrower.';
        }
    }

    return [$data, $errors];
}

function payment_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        "SELECT p.*, b.first_name, b.last_name, b.borrower_number, pm.display_name AS method_name, pm.method_type,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', ru.first_name, ru.last_name)), ''), ru.email) AS reviewer_name,
                (SELECT COALESCE(SUM(a.allocated_amount), 0) FROM borrower_payment_allocations a WHERE a.borrower_payment_id = p.id) AS allocated
           FROM borrower_payments p
           JOIN borrowers b ON b.id = p.borrower_id
           LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
           LEFT JOIN users ru ON ru.id = p.reviewed_by
          WHERE p.id = :id AND " . owner_scope('borrower_payments', 'p'),
        [':id' => $id]
    );
}

function allocation_status_for(float $amount, float $allocated): string
{
    if ($allocated <= 0.004) {
        return 'unallocated';
    }
    if ($allocated > $amount + 0.004) {
        return 'overallocated';
    }

    return abs($allocated - $amount) <= 0.004 ? 'fully_allocated' : 'partially_allocated';
}

/**
 * Record a review decision: payment status, verification (one per payment) and a log row.
 * Must run inside a database transaction.
 */
function payment_record_decision(array $payment, string $decision, ?string $reason, array $checks, string $userId): void
{
    $now = date('Y-m-d H:i:s');

    $update = ['status' => $decision, 'reviewed_at' => $now, 'reviewed_by' => $userId];
    if ($decision === 'approved') {
        $update['approved_at'] = $now;
    } elseif ($decision === 'rejected') {
        $update += ['rejected_at' => $now, 'rejection_reason' => $reason];
    } elseif ($decision === 'correction_required') {
        $update['correction_reason'] = $reason;
    }
    db_update('borrower_payments', $update, $payment['id']);

    // payment_verifications has UNIQUE(borrower_payment_id): keep the latest decision.
    db_exec(
        'INSERT INTO payment_verifications
            (id, borrower_payment_id, reviewed_by, reviewed_at, decision, reason, verified_amount,
             reference_verified, proof_verified, payment_date_verified)
         VALUES (UUID(), :pid, :uid, :at, :decision, :reason, :amount, :ref, :proof, :date)
         ON DUPLICATE KEY UPDATE reviewed_by = VALUES(reviewed_by), reviewed_at = VALUES(reviewed_at),
            decision = VALUES(decision), reason = VALUES(reason), verified_amount = VALUES(verified_amount),
            reference_verified = VALUES(reference_verified), proof_verified = VALUES(proof_verified),
            payment_date_verified = VALUES(payment_date_verified)',
        [
            ':pid' => $payment['id'], ':uid' => $userId, ':at' => $now, ':decision' => $decision, ':reason' => $reason,
            ':amount' => $checks['verified_amount'], ':ref' => $checks['reference_verified'],
            ':proof' => $checks['proof_verified'], ':date' => $checks['payment_date_verified'],
        ]
    );

    payment_log($payment, $decision, 'review', $reason, $userId);
}

function payment_log(array $payment, string $toStatus, string $action, ?string $reason, string $userId): void
{
    db_insert('payment_verification_logs', [
        'id' => new_uuid(), 'borrower_payment_id' => $payment['id'], 'from_status' => $payment['status'],
        'to_status' => $toStatus, 'action' => $action, 'reason' => $reason, 'performed_by' => $userId,
    ]);
}

/**
 * Apply part of an approved payment to an obligation, strictly in this order
 * (includes/penalties.php):
 *   1. unpaid penalties of the obligation, oldest first  -> allocation_type "penalty"
 *   2. its interest and fees (agreed_interest + agreed_fees) -> "interest"
 *   3. principal                                        -> "principal"
 * Interest + principal reduce the obligation balance (and the chosen
 * installment, if any); the penalty part reduces applied_penalties.
 * $type is kept for compatibility and ignored. Returns an error or null.
 */
function payment_allocate(array $payment, string $obligationId, ?string $scheduleId, float $amount, string $type, string $userId): ?string
{
    try {
        return payment_allocate_locked($payment, $obligationId, $scheduleId, $amount, $userId);
    } catch (DomainException $e) {
        return $e->getMessage(); // rolled back
    }
}

function payment_allocate_locked(array $payment, string $obligationId, ?string $scheduleId, float $amount, string $userId): ?string
{
    return db_transaction(function () use ($payment, $obligationId, $scheduleId, $amount, $userId) {
        $fresh = db_row('SELECT id, amount, status FROM borrower_payments WHERE id = :id FOR UPDATE', [':id' => $payment['id']]);
        if (!$fresh || $fresh['status'] !== 'approved') {
            return 'Only approved payments can be allocated.';
        }

        $allocated = (float) db_value('SELECT COALESCE(SUM(allocated_amount), 0) FROM borrower_payment_allocations WHERE borrower_payment_id = :id', [':id' => $payment['id']]);
        $unallocated = (float) $fresh['amount'] - $allocated;

        $obligation = db_row(
            "SELECT * FROM borrower_obligations WHERE id = :id AND borrower_id = :b AND status NOT IN ('cancelled', 'written_off') FOR UPDATE",
            [':id' => $obligationId, ':b' => $payment['borrower_id']]
        );
        if (!$obligation) {
            return 'Choose an open obligation of this borrower.';
        }
        // An installment must be one of this obligation's own (its purchase's plan).
        if ($scheduleId !== null && !db_value(
            'SELECT s.id FROM installment_schedules s JOIN installment_plans ip ON ip.id = s.installment_plan_id
              WHERE s.id = :s AND ip.transaction_id = :t',
            [':s' => $scheduleId, ':t' => (string) $obligation['transaction_id']]
        )) {
            return 'Choose an installment of this obligation.';
        }
        $penalties = db_all(
            'SELECT * FROM applied_penalties WHERE obligation_id = :o AND remaining_amount > 0 ORDER BY applied_date, created_at FOR UPDATE',
            [':o' => $obligation['id']]
        );
        $penaltyDue = array_sum(array_map(static fn ($p) => (float) $p['remaining_amount'], $penalties));
        $maximum = $penaltyDue + (float) $obligation['outstanding_amount'];

        if ($amount <= 0) {
            return 'Enter an amount greater than zero.';
        }
        if ($amount > $unallocated + 0.004) {
            return 'Only ' . format_money($unallocated) . ' of this payment is still unallocated.';
        }
        if ($maximum <= 0.004) {
            return 'This obligation has nothing left to pay.';
        }
        if ($amount > $maximum + 0.004) {
            return 'The obligation only has ' . format_money($maximum) . ' left (' . format_money($penaltyDue) . ' penalties + ' . format_money($obligation['outstanding_amount']) . ' balance).';
        }

        [$toPenalty, $toInterest, $toPrincipal] = allocation_split($obligation, $amount);
        $toBalance = $toInterest + $toPrincipal;

        // 1. Penalties, oldest first.
        $left = $toPenalty;
        foreach ($penalties as $p) {
            if ($left <= 0.004) {
                break;
            }
            $part = min($left, (float) $p['remaining_amount']);
            db_update('applied_penalties', [
                'paid_amount'      => number_format((float) $p['paid_amount'] + $part, 2, '.', ''),
                'remaining_amount' => number_format((float) $p['remaining_amount'] - $part, 2, '.', ''),
            ], $p['id']);
            $left -= $part;
        }

        foreach (['penalty' => $toPenalty, 'interest' => $toInterest, 'principal' => $toPrincipal] as $kind => $part) {
            if ($part > 0.004) {
                db_insert('borrower_payment_allocations', [
                    'id' => new_uuid(), 'borrower_payment_id' => $payment['id'], 'obligation_id' => $obligation['id'],
                    'installment_schedule_id' => $kind === 'penalty' ? null : $scheduleId,
                    'allocated_amount' => number_format($part, 2, '.', ''), 'allocation_type' => $kind,
                ]);
            }
        }

        $totalPaid = (float) $obligation['total_paid'] + $toBalance;
        $outstanding = max(0, (float) $obligation['total_amount_due'] - $totalPaid);
        $isPaid = $outstanding <= 0.004 && $penaltyDue - $toPenalty <= 0.004;
        db_update('borrower_obligations', [
            'total_paid'         => number_format($totalPaid, 2, '.', ''),
            'outstanding_amount' => number_format($outstanding, 2, '.', ''),
            'overdue_amount'     => number_format(min((float) $obligation['overdue_amount'], $outstanding), 2, '.', ''),
            'status'             => $isPaid ? 'paid' : 'partially_paid',
            'completed_at'       => $isPaid ? date('Y-m-d H:i:s') : null,
            'updated_by'         => $userId,
        ], $obligation['id']);

        db_update('borrower_payments', ['allocation_status' => allocation_status_for((float) $fresh['amount'], $allocated + $amount)], $payment['id']);

        // The installment schedule (purchase plan or cash loan) follows the payment automatically.
        schedule_sync_obligation($obligation['id'], payment_find($payment['id']));

        return null;
    });
}

/**
 * Open obligations of a borrower with their installment schedules (for the allocation form).
 *
 * @return array{0: array, 1: array, 2: array} [obligation options, schedule options, schedule option data]
 */
function payment_allocation_choices(string $borrowerId): array
{
    $obligations = db_options(
        "SELECT o.id, CONCAT(o.obligation_number, ' — balance ₱', FORMAT(o.outstanding_amount, 2),
                       IF(pen.unpaid > 0, CONCAT(' + penalties ₱', FORMAT(pen.unpaid, 2)), ''),
                       IFNULL(CONCAT(' — due ', DATE_FORMAT(o.due_date, '%b %e, %Y')), ''))
           FROM borrower_obligations o
           LEFT JOIN (SELECT obligation_id, SUM(remaining_amount) AS unpaid FROM applied_penalties GROUP BY obligation_id) pen ON pen.obligation_id = o.id
          WHERE o.borrower_id = :b AND o.status NOT IN ('cancelled', 'written_off') AND (o.outstanding_amount > 0 OR pen.unpaid > 0)
          ORDER BY o.due_date IS NULL, o.due_date, o.obligation_date",
        [':b' => $borrowerId]
    );

    $rows = db_all(
        "SELECT s.id, o.id AS obligation_id,
                CONCAT('Installment ', s.installment_number, ' — due ', DATE_FORMAT(s.due_date, '%b %e, %Y'), ' — ₱', FORMAT(s.remaining_amount, 2), ' left') AS label
           FROM borrower_obligations o
           JOIN installment_plans p ON p.transaction_id = o.transaction_id
           JOIN installment_schedules s ON s.installment_plan_id = p.id
          WHERE o.borrower_id = :b AND o.status NOT IN ('cancelled', 'paid', 'written_off')
            AND s.status NOT IN ('paid', 'waived', 'cancelled')
          ORDER BY s.installment_number",
        [':b' => $borrowerId]
    );

    $scheduleOptions = [];
    $scheduleData = [];
    foreach ($rows as $row) {
        $scheduleOptions[$row['id']] = $row['label'];
        $scheduleData[$row['id']] = ['parent' => $row['obligation_id']];
    }

    return [$obligations, $scheduleOptions, $scheduleData];
}

/**
 * OWNER-ONLY amount override, allowed in any status except cancelled -
 * also after approval and allocation. Payments are never deleted.
 *
 * Raising the amount leaves the difference unallocated (allocate it as
 * usual). Lowering it below what is already applied takes the excess back
 * from the newest allocations first, in reverse payment order (principal,
 * then interest, then penalties), restoring the obligation balance, the
 * installment and the penalties it had paid. Everything is recorded in
 * payment_verification_logs and activity_logs with the reason, and the
 * borrower is notified. Returns an error message or null.
 */
function payment_override_amount(array $payment, string $rawAmount, string $reason, array $user): ?string
{
    if (!user_is_admin($user)) {
        return 'Only the owner can override a payment amount.';
    }
    $reason = trim($reason);
    if (mb_strlen($reason) < PENALTY_REASON_MIN) {
        return 'Enter a reason of at least ' . PENALTY_REASON_MIN . ' characters.';
    }
    $raw = str_replace([',', ' ', '₱'], '', $rawAmount);
    if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $raw) || (float) $raw <= 0) {
        return 'Enter a new amount greater than zero.';
    }
    $new = round((float) $raw, 2);

    try {
        return db_transaction(function () use ($payment, $new, $reason, $user) {
            $fresh = db_row('SELECT * FROM borrower_payments WHERE id = :id FOR UPDATE', [':id' => $payment['id']]);
            if ($fresh['status'] === 'cancelled') {
                return 'A cancelled payment cannot be changed.';
            }
            $old = (float) $fresh['amount'];
            if (abs($old - $new) < 0.005) {
                return 'The new amount is the same as the current amount.';
            }

            $allocations = db_all(
                "SELECT * FROM borrower_payment_allocations WHERE borrower_payment_id = :p
                  ORDER BY created_at DESC, FIELD(allocation_type, 'principal', 'interest', 'installment', 'penalty'), id DESC FOR UPDATE",
                [':p' => $fresh['id']]
            );
            $allocated = array_sum(array_map(static fn ($a) => (float) $a['allocated_amount'], $allocations));
            $excess = round($allocated - $new, 2);
            $reversed = 0.0;

            foreach ($allocations as $a) {
                if ($excess <= 0.004) {
                    break;
                }
                $take = min($excess, (float) $a['allocated_amount']);
                payment_reverse_allocation($a, $take);
                $excess -= $take;
                $reversed += $take;
            }

            db_update('borrower_payments', [
                'amount'            => number_format($new, 2, '.', ''),
                'allocation_status' => allocation_status_for($new, $allocated - $reversed),
            ], $fresh['id']);

            $note = 'Amount overridden by the owner from ' . format_money($old) . ' to ' . format_money($new)
                . ($reversed > 0 ? ' (' . format_money($reversed) . ' taken back from allocations)' : '') . '. Reason: ' . $reason;
            payment_log($fresh, $fresh['status'], 'amount_override', $note, $user['id']);
            log_activity('amount_override', 'borrower_payment', $fresh['id'], $fresh['payment_number'] . ': ' . $note,
                ['amount' => $fresh['amount']], ['amount' => number_format($new, 2, '.', ''), 'reversed' => $reversed, 'reason' => $reason]);
            notify_borrower($fresh['borrower_id'], 'payment_override', 'Payment ' . $fresh['payment_number'] . ' amount changed',
                'The owner changed the amount of your payment from ' . format_money($old) . ' to ' . format_money($new) . '. Reason: ' . $reason,
                'borrower_payment', $fresh['id']);

            return null;
        });
    } catch (PDOException $e) {
        return db_error_message($e);
    }
}

/**
 * Take $take back from one allocation row and undo its effect.
 * Must run inside the caller's transaction.
 */
function payment_reverse_allocation(array $a, float $take): void
{
    $o = db_row('SELECT * FROM borrower_obligations WHERE id = :id FOR UPDATE', [':id' => $a['obligation_id']]);

    if ($a['allocation_type'] === 'penalty') {
        // Give back to the penalties it paid, newest first (they were paid oldest first).
        $left = $take;
        foreach (db_all('SELECT * FROM applied_penalties WHERE obligation_id = :o AND paid_amount > 0 ORDER BY applied_date DESC, created_at DESC FOR UPDATE', [':o' => $o['id']]) as $p) {
            if ($left <= 0.004) {
                break;
            }
            $part = min($left, (float) $p['paid_amount']);
            db_update('applied_penalties', [
                'paid_amount'      => number_format((float) $p['paid_amount'] - $part, 2, '.', ''),
                'remaining_amount' => number_format((float) $p['remaining_amount'] + $part, 2, '.', ''),
            ], $p['id']);
            $left -= $part;
        }
    } else {
        // Interest / principal (or older "installment" rows) were part of the obligation balance.
        $totalPaid = max(0, (float) $o['total_paid'] - $take);
        db_update('borrower_obligations', [
            'total_paid'         => number_format($totalPaid, 2, '.', ''),
            'outstanding_amount' => number_format(max(0, (float) $o['total_amount_due'] - $totalPaid), 2, '.', ''),
        ], $o['id']);

    }

    // Shrink or remove the allocation row (allocations are working data; the
    // payment itself and its history stay).
    $remaining = round((float) $a['allocated_amount'] - $take, 2);
    if ($remaining <= 0.004) {
        db_exec('DELETE FROM borrower_payment_allocations WHERE id = :id', [':id' => $a['id']]);
    } else {
        db_update('borrower_payment_allocations', ['allocated_amount' => number_format($remaining, 2, '.', '')], $a['id']);
    }

    // The schedule (and the automatic card payment) follow the payments.
    schedule_sync_obligation($o['id'], payment_find($a['borrower_payment_id']));

    // Re-open the obligation if something is owed again.
    $o = db_row('SELECT * FROM borrower_obligations WHERE id = :id', [':id' => $o['id']]);
    $owed = (float) $o['outstanding_amount'] + penalty_unpaid_total($o['id']);
    if ($owed > 0.004 && in_array($o['status'], ['paid', 'partially_paid'], true)) {
        db_update('borrower_obligations', ['status' => (float) $o['total_paid'] > 0.004 ? 'partially_paid' : 'active', 'completed_at' => null], $o['id']);
    }
}
