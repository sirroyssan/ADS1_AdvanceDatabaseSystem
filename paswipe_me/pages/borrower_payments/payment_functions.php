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
          WHERE p.id = :id",
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

        // The installment (if chosen) receives the balance part only.
        if ($scheduleId !== null && $toBalance > 0.004) {
            $schedule = db_row(
                'SELECT s.* FROM installment_schedules s JOIN installment_plans p ON p.id = s.installment_plan_id
                  WHERE s.id = :s AND p.transaction_id <=> :t FOR UPDATE',
                [':s' => $scheduleId, ':t' => $obligation['transaction_id']]
            );
            if (!$schedule) {
                throw new DomainException('The installment must belong to the obligation\'s transaction.');
            }
            $paid = (float) $schedule['amount_paid'] + $toBalance;
            db_update('installment_schedules', [
                'amount_paid'      => number_format($paid, 2, '.', ''),
                'remaining_amount' => number_format(max(0, (float) $schedule['amount_due'] - $paid), 2, '.', ''),
                'status'           => schedule_status_after_payment((float) $schedule['amount_due'], $paid, $schedule['due_date']),
                'paid_at'          => $paid >= (float) $schedule['amount_due'] - 0.004 ? date('Y-m-d H:i:s') : null,
            ], $schedule['id']);
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
