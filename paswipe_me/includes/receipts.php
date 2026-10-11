<?php
/**
 * Receiving a borrower's payment in person (staff or owner) and its
 * acknowledgement receipt.
 *
 * One confirmation saves, in ONE database transaction:
 *   - for each card purchase paid: a confirmed repayment of that purchase
 *     (borrower_payments.transaction_id, status approved) - the purchase itself
 *     is never copied or changed;
 *   - for the obligations paid: one approved payment, applied right away to the
 *     items chosen (borrower_payment_selections -> allocations: late charges,
 *     interest, principal; installments and statuses follow);
 *   - the receipt (payment_receipts + payment_receipt_items) with a snapshot of
 *     each item's balance before and after.
 * Any failure rolls everything back. The money received must be put on items in
 * full and can never exceed what is owed. A receipt number is unique and a
 * payment belongs to one receipt only; printing again never records anything.
 */

defined('APP_ROOT') or exit('No direct access.');

require_once APP_ROOT . '/includes/payment_form.php';

/** What the borrower owes now (all open items), keyed by kind:id, plus the total. */
function receipt_balances(string $borrowerId): array
{
    $by = [];
    $total = 0.0;
    foreach (portal_all_items($borrowerId, false) as $i) {
        $by[$i['kind'] . ':' . $i['id']] = $i;
        $total += $i['total'];
    }

    return [$by, round($total, 2)];
}

/**
 * Validate a staff receipt form. Returns [data, purchases [txn => amount],
 * selections [obligation rows], errors].
 */
function receive_validate(array $borrower, array $groups, array $input): array
{
    $methods = portal_payment_method_options($borrower['id']);
    $fields = receive_fields($methods);
    [$data, $errors] = validate_fields($fields, $input);
    if (!isset($errors['payment_date']) && $data['payment_date'] > date('Y-m-d')) {
        $errors['payment_date'] = 'The payment date cannot be in the future.';
    }
    if (!isset($errors['payment_method_id']) && $data['payment_method_id'] !== null && !array_key_exists($data['payment_method_id'], $methods)) {
        $errors['payment_method_id'] = 'Choose one of the listed payment methods.';
    }
    $purchases = [];
    $selections = [];
    if (!$errors) {
        [$purchases, $error] = payment_purchase_choices($input, $groups);
        if ($error === null && array_filter($groups, static fn ($g) => $g['kind'] === 'obligation')) {
            [$selections, $error] = payment_selections_from_input($input, $borrower['id'], (float) $data['amount']);
        }
        $assigned = round(array_sum($purchases) + array_sum(array_column($selections, 'amount')), 2);
        $owed = round(array_sum(array_column($groups, 'owed')), 2);
        if ($error === null && (float) $data['amount'] > $owed + 0.004) {
            $error = 'The amount tendered (' . format_money($data['amount']) . ') is more than the borrower owes (' . format_money($owed) . ').';
        } elseif ($error === null && abs($assigned - (float) $data['amount']) > 0.004) {
            $error = 'Put the whole amount tendered on the items being paid: ' . format_money($assigned) . ' of ' . format_money($data['amount']) . ' is assigned.';
        }
        if ($error !== null) {
            $errors['selection'] = $error;
        }
    }

    return [$data, $purchases, $selections, $errors];
}

function receive_fields(array $methods): array
{
    return [
        'amount'            => ['label' => 'Amount tendered', 'type' => 'decimal', 'required' => true, 'min_exclusive' => 0,
                                'help' => 'The money actually received from the borrower.'],
        'payment_date'      => ['label' => 'Payment date', 'type' => 'date', 'required' => true, 'initial' => date('Y-m-d')],
        'payment_method_id' => ['label' => 'Payment method', 'type' => 'select', 'options' => $methods, 'empty_label' => $methods ? 'Select payment method' : '— None set up —'],
        'reference_number'  => ['label' => 'Reference number', 'type' => 'text', 'max' => 150, 'help' => 'GCash / bank reference. Leave blank for cash.'],
        'notes'             => ['label' => 'Remarks', 'type' => 'textarea', 'rows' => 2],
    ];
}

/** Save a payment received in person and issue its receipt. Returns the receipt id. */
function receive_save(array $borrower, array $user, array $data, array $purchases, array $selections, array $groups): string
{
    return db_transaction(static function () use ($borrower, $user, $data, $purchases, $selections, $groups) {
        // One payment at a time per borrower: balances are read and written under this lock.
        db_value('SELECT id FROM borrowers WHERE id = :b FOR UPDATE', [':b' => $borrower['id']]);
        [$before, $previous] = receipt_balances($borrower['id']);
        $now = date('Y-m-d H:i:s');
        $common = [
            'payment_method_id' => $data['payment_method_id'], 'payment_date' => $data['payment_date'],
            'reference_number' => $data['reference_number'],
            'notes' => trim('Received in person by ' . user_name_of($user['id']) . '. ' . ($data['notes'] ?? '')),
        ];
        $paymentIds = [];

        foreach ($purchases as $txnId => $amount) {
            $p = db_row(borrowed_select_sql() . ' WHERE t.id = :t AND t.borrower_id = :b', [':t' => $txnId, ':b' => $borrower['id']]);
            if (!$p) {
                throw new DomainException('A card purchase was not found for this borrower.');
            }
            $paymentIds[] = borrowed_record_repayment($p, ['amount' => number_format($amount, 2, '.', '')] + $common, $user['id'], true);
        }

        if ($selections) {
            $id = new_uuid();
            $amount = round(array_sum(array_column($selections, 'amount')), 2);
            db_insert('borrower_payments', ['id' => $id, 'payment_number' => generate_reference('PAY', 'borrower_payments', 'payment_number'),
                'borrower_id' => $borrower['id'], 'amount' => number_format($amount, 2, '.', '')] + $common + [
                'status' => 'approved', 'allocation_status' => 'unallocated', 'submitted_at' => $now,
                'reviewed_at' => $now, 'reviewed_by' => $user['id'], 'approved_at' => $now,
            ]);
            payment_log(['id' => $id, 'status' => null], 'approved', 'received', 'Received in person and confirmed by ' . user_name_of($user['id']), $user['id']);
            payment_selections_save($id, $selections);
            [$applied, $notes] = payment_apply_selections($id, $user['id']);
            if (abs($applied - $amount) > 0.004) {
                throw new DomainException('The payment could not be applied in full' . ($notes ? ': ' . implode('; ', $notes) : '.'));
            }
            $paymentIds[] = $id;
        }

        // Each item's balance before and after, for the receipt.
        [$after, $remaining] = receipt_balances($borrower['id']);
        $lines = [];
        foreach ($groups as $g) {
            $paid = $g['kind'] === 'purchase'
                ? ($purchases[$g['id']] ?? 0.0)
                : array_sum(array_column(array_filter($selections, static fn ($s) => $s['obligation_id'] === $g['id']), 'amount'));
            if ($paid <= 0) {
                continue;
            }
            $key = $g['kind'] . ':' . $g['id'];
            $lines[] = [
                'kind' => $g['kind'], 'id' => $g['id'], 'type' => $before[$key]['type'] ?? '', 'ref' => $before[$key]['ref'] ?? '', 'title' => $before[$key]['title'] ?? $g['title'],
                'original' => $before[$key]['amount'] ?? null, 'before' => $before[$key]['total'] ?? null,
                'paid' => round($paid, 2), 'after' => $after[$key]['total'] ?? 0.0,
            ];
        }

        $receiptId = new_uuid();
        $method = $data['payment_method_id'] ? db_value('SELECT display_name FROM payment_methods WHERE id = :m', [':m' => $data['payment_method_id']]) : null;
        db_insert('payment_receipts', [
            'id' => $receiptId, 'receipt_number' => generate_reference('AR', 'payment_receipts', 'receipt_number'),
            'owner_user_id' => $borrower['owner_user_id'], 'borrower_id' => $borrower['id'], 'received_by' => $user['id'], 'received_at' => $now,
            'payment_date' => $data['payment_date'], 'amount_tendered' => number_format((float) $data['amount'], 2, '.', ''),
            'previous_balance' => number_format($previous, 2, '.', ''), 'remaining_balance' => number_format(max(0, $remaining), 2, '.', ''),
            'payment_method_id' => $data['payment_method_id'], 'method_label' => $method ?: 'Cash',
            'reference_number' => $data['reference_number'], 'remarks' => $data['notes'],
            'line_items' => json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        foreach ($paymentIds as $pid) {
            db_insert('payment_receipt_items', ['id' => new_uuid(), 'receipt_id' => $receiptId, 'borrower_payment_id' => $pid]);
        }
        log_activity('receive_payment', 'payment_receipt', $receiptId, 'Received ' . format_money($data['amount']) . ' from ' . borrower_name($borrower)
            . ' (' . count($paymentIds) . ' payment record(s))', null, ['payments' => $paymentIds, 'lines' => $lines]);

        return $receiptId;
    });
}

function receipt_find(string $id): ?array
{
    return is_valid_uuid($id) ? db_row(
        'SELECT r.*, b.first_name, b.last_name, b.borrower_number FROM payment_receipts r JOIN borrowers b ON b.id = r.borrower_id
          WHERE r.id = :id AND ' . owner_scope('payment_receipts', 'r'),
        [':id' => $id]
    ) : null;
}

/** Payments printed on a receipt. */
function receipt_payments(string $receiptId): array
{
    return db_all(
        'SELECT p.id, p.payment_number, p.amount, p.status, p.transaction_id FROM payment_receipt_items i JOIN borrower_payments p ON p.id = i.borrower_payment_id
          WHERE i.receipt_id = :r ORDER BY p.payment_number',
        [':r' => $receiptId]
    );
}

/**
 * After a payment on a receipt is corrected or voided: the receipt becomes
 * void once none of its payments counts any more; otherwise the change is
 * noted on it ("AMENDED"). The receipt's original figures are never rewritten.
 */
function receipt_note_change(string $paymentId, string $note, string $userId): void
{
    $receipt = db_row('SELECT r.* FROM payment_receipt_items i JOIN payment_receipts r ON r.id = i.receipt_id WHERE i.borrower_payment_id = :p FOR UPDATE', [':p' => $paymentId]);
    if (!$receipt || $receipt['status'] === 'void') {
        return;
    }
    $open = (int) db_value("SELECT COUNT(*) FROM payment_receipt_items i JOIN borrower_payments p ON p.id = i.borrower_payment_id
                             WHERE i.receipt_id = :r AND p.status <> 'cancelled'", [':r' => $receipt['id']]);
    if ($open === 0) {
        db_update('payment_receipts', ['status' => 'void', 'void_reason' => $note, 'voided_at' => date('Y-m-d H:i:s'), 'voided_by' => $userId], $receipt['id']);
        return;
    }
    $line = date('M j, Y g:i A') . ' — ' . $note;
    db_update('payment_receipts', ['amended_note' => trim(($receipt['amended_note'] ? $receipt['amended_note'] . "\n" : '') . $line)], $receipt['id']);
}
