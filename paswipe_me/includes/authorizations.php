<?php
/**
 * Authorized changes to confirmed records (staff -> card owner approval).
 *
 * Staff cannot edit or delete a confirmed card transaction or borrower payment.
 * They file a request (authorization_requests) with the action, a reason and -
 * for an edit - the corrected values. The card owner of that record decides:
 *   - in their own signed-in session (Authorization Requests), or
 *   - on the spot, by entering their own sign-in and password in the request
 *     form; the server checks it with password_verify() against the stored hash.
 * The approver must be the owner of the record's books (owner_user_id of the
 * request, which is the record's owner) and can never be the requester.
 * Only then is the change applied - in one database transaction, through the
 * same rules the owner's own screens use - and the request keeps the original
 * values, the new values, who asked, who decided, when and the result.
 * "Delete" never erases financial history: a transaction is voided (status
 * cancelled, out of the card's used credit), a payment is voided (its
 * allocations are reversed, balances restored, its receipt marked void).
 */

defined('APP_ROOT') or exit('No direct access.');

require_once APP_ROOT . '/pages/transactions/transaction_functions.php';
require_once APP_ROOT . '/pages/borrower_payments/payment_functions.php';
require_once APP_ROOT . '/includes/receipts.php';

const AUTH_RECORD_TYPES = ['transaction' => 'Card transaction', 'borrower_payment' => 'Borrower payment'];
const AUTH_ACTIONS = ['edit' => 'Edit', 'delete' => 'Delete (void)'];
const AUTH_REASON_MIN = 10;
const AUTH_MAX_FAILED = 5;   // wrong owner passwords per requester per 15 minutes
const AUTH_MESSAGE = 'Administrator authorization is required to modify or delete this record. Please contact the card owner or authorized administrator for approval.';

/**
 * A record that can be changed through a request, found within the signed-in
 * user's books only. Returns ['record', 'ref', 'owner', 'amount', 'details' (label => html), 'fields'] or null.
 */
function auth_record(string $type, string $id): ?array
{
    if ($type === 'transaction') {
        $t = transaction_find($id);
        if (!$t || in_array($t['status'], TXN_LOCKED_STATUSES, true)) {
            return null;
        }
        $owner = db_value('SELECT owner_user_id FROM credit_cards WHERE id = :c', [':c' => $t['credit_card_id']]);

        return [
            'record' => $t, 'ref' => $t['transaction_number'], 'owner' => $owner, 'amount' => (float) $t['amount'],
            'details' => [
                'Reference'   => e($t['transaction_number']),
                'Card'        => e($t['card_nickname'] . ' •••• ' . $t['last_four']),
                'Date'        => e(format_date($t['transaction_date'])),
                'Amount'      => '<strong>' . e(format_money($t['amount'])) . '</strong>',
                'Merchant'    => show($t['merchant_name']),
                'Borrower'    => $t['borrower_id'] ? e(trim($t['first_name'] . ' ' . $t['last_name'])) : '<span class="text-muted">Owner\'s own</span>',
                'Status'      => badge($t['status']),
                'Recorded by' => show($t['created_by_name']),
            ],
            'fields' => [
                'transaction_date' => ['label' => 'Transaction date', 'type' => 'date', 'required' => true],
                'amount'           => ['label' => 'Amount', 'type' => 'decimal', 'required' => true, 'min_exclusive' => 0],
                'reference_number' => ['label' => 'Reference number', 'type' => 'text', 'max' => 150],
                'receipt_number'   => ['label' => 'Receipt number', 'type' => 'text', 'max' => 150],
                'notes'            => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 2],
            ],
        ];
    }
    if ($type === 'borrower_payment') {
        $p = payment_find($id);
        if (!$p || $p['status'] === 'cancelled') {
            return null;
        }
        $receipt = db_value('SELECT r.receipt_number FROM payment_receipt_items i JOIN payment_receipts r ON r.id = i.receipt_id WHERE i.borrower_payment_id = :p', [':p' => $p['id']]);

        return [
            'record' => $p, 'ref' => $p['payment_number'], 'owner' => db_value('SELECT owner_user_id FROM borrowers WHERE id = :b', [':b' => $p['borrower_id']]),
            'amount' => (float) $p['amount'],
            'details' => [
                'Payment no.'     => e($p['payment_number']),
                'Receipt no.'     => show($receipt ?: null),
                'Borrower'        => e(trim($p['first_name'] . ' ' . $p['last_name']) . ' (' . $p['borrower_number'] . ')'),
                'Payment date'    => e(format_date($p['payment_date'])),
                'Amount tendered' => '<strong>' . e(format_money($p['amount'])) . '</strong>',
                'Applied'         => e(format_money($p['allocated'])),
                'Method'          => show($p['method_name']) . ($p['reference_number'] ? ' · ' . e($p['reference_number']) : ''),
                'Status'          => badge($p['status']),
            ],
            'fields' => [
                'amount'            => ['label' => 'Amount tendered', 'type' => 'decimal', 'required' => true, 'min_exclusive' => 0],
                'payment_date'      => ['label' => 'Payment date', 'type' => 'date', 'required' => true],
                'payment_method_id' => ['label' => 'Payment method', 'type' => 'select', 'options' => portal_payment_method_options($p['borrower_id'], $p['payment_method_id']), 'empty_label' => '— Not specified —'],
                'reference_number'  => ['label' => 'Reference number', 'type' => 'text', 'max' => 150],
                'notes'             => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 2],
            ],
        ];
    }

    return null;
}

/** The corrected values of an edit request: only fields that change. Returns [changes, errors]. */
function auth_edit_changes(string $type, array $r, array $input): array
{
    [$data, $errors] = validate_fields($r['fields'], $input);
    $record = $r['record'];
    $changes = [];
    foreach ($data as $k => $v) {
        $old = $record[$k] ?? null;
        $same = in_array($k, ['amount'], true) ? abs((float) $old - (float) $v) < 0.005 : (string) $old === (string) $v;
        if (!$same) {
            $changes[$k] = $v;
        }
    }
    if (!$errors && !$changes) {
        $errors['_form'] = 'Change at least one value, or cancel.';
    }
    $dateKey = $type === 'transaction' ? 'transaction_date' : 'payment_date';
    if (isset($changes[$dateKey]) && $changes[$dateKey] > date('Y-m-d') && $type === 'borrower_payment') {
        $errors[$dateKey] = 'The payment date cannot be in the future.';
    }
    if ($type === 'transaction' && isset($changes['amount'])) {
        if ((int) db_value("SELECT COUNT(*) FROM installment_plans WHERE transaction_id = :t AND status <> 'cancelled'", [':t' => $record['id']]) > 0) {
            $errors['amount'] = 'This is an installment purchase: its amount is corrected by the owner in the Installment module (with its schedule).';
        }
        $repaid = (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM borrower_payments WHERE transaction_id = :t AND status = 'approved'", [':t' => $record['id']]);
        if ((float) $changes['amount'] < $repaid - 0.004) {
            $errors['amount'] = 'The borrower already repaid ' . format_money($repaid) . ' on this purchase.';
        }
    }

    return [$changes, $errors];
}

/** Why a record cannot be voided right now, or null. */
function auth_delete_problem(string $type, array $r): ?string
{
    if ($type === 'transaction') {
        $t = $r['record'];
        if ((int) db_value("SELECT COUNT(*) FROM borrower_payments WHERE transaction_id = :t AND status NOT IN ('cancelled', 'rejected')", [':t' => $t['id']]) > 0) {
            return 'This purchase has borrower repayments. Void those payments first.';
        }
        if ((int) db_value("SELECT COUNT(*) FROM borrower_obligations WHERE transaction_id = :t AND status <> 'cancelled' AND total_paid > 0", [':t' => $t['id']]) > 0) {
            return 'An obligation of this transaction has payments. Void those payments first.';
        }
    }

    return null;
}

/** File a request. Returns the request id. */
function auth_create(string $type, array $r, string $action, string $reason, ?array $changes, array $user): string
{
    $id = new_uuid();
    db_insert('authorization_requests', [
        'id' => $id, 'request_number' => generate_reference('AUTH', 'authorization_requests', 'request_number'),
        'owner_user_id' => $r['owner'], 'record_type' => $type, 'record_id' => $r['record']['id'], 'record_ref' => $r['ref'],
        'action' => $action, 'reason' => $reason,
        'original_values' => json_encode(array_intersect_key($r['record'], $r['fields'] + ['status' => true]), JSON_UNESCAPED_UNICODE),
        'proposed_values' => $changes !== null ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
        'requested_by' => $user['id'], 'status' => 'pending',
    ]);
    log_activity('authorization_request', $type, $r['record']['id'], 'Requested to ' . $action . ' ' . $r['ref'] . ': ' . $reason, null, $changes);

    return $id;
}

/**
 * Check an approver's own sign-in and password (never stored or shown).
 * The approver must own the record's books and must not be the requester.
 * Returns [user|null, error|null]; failures are logged and limited.
 */
function auth_verify_approver(string $login, string $password, string $ownerId, string $requesterId): array
{
    $failed = (int) db_value(
        "SELECT COUNT(*) FROM activity_logs WHERE action = 'authorization_failed' AND actor_user_id = :u AND created_at > NOW() - INTERVAL 15 MINUTE",
        [':u' => $requesterId]
    );
    if ($failed >= AUTH_MAX_FAILED) {
        return [null, 'Too many failed authorization attempts. Wait 15 minutes, or ask the owner to approve the request from their own account.'];
    }
    $user = $login === '' ? null : db_row(
        "SELECT * FROM users WHERE (LOWER(email) = LOWER(:e) OR username = :u) AND status = 'active' LIMIT 1",
        [':e' => $login, ':u' => $login]
    );
    $ok = $user && password_verify($password, (string) $user['password_hash'])
        && array_intersect(ADMIN_ROLES, fetch_user_roles($user['id']))
        && hash_equals((string) $ownerId, (string) $user['id']) && $user['id'] !== $requesterId;
    if (!$ok) {
        log_activity('authorization_failed', 'user', $user['id'] ?? null, 'Failed owner authorization for a change request (sign-in: ' . mb_substr($login, 0, 60) . ')');

        return [null, 'Authorization failed: the sign-in and password must be those of the card owner of this record.'];
    }
    $user['roles'] = fetch_user_roles($user['id']);

    return [$user, null];
}

/**
 * Approve a pending request and apply it. $approver must be the record's owner.
 * Returns null on success or the error (the request is then marked failed and nothing changes).
 */
function auth_approve(string $requestId, array $approver, string $method, ?string $note = null): ?string
{
    try {
        $result = db_transaction(static function () use ($requestId, $approver, $method, $note) {
            $req = db_row('SELECT * FROM authorization_requests WHERE id = :id FOR UPDATE', [':id' => $requestId]);
            if (!$req || $req['status'] !== 'pending') {
                throw new DomainException('This request was already decided.');
            }
            if (!hash_equals((string) $req['owner_user_id'], (string) $approver['id']) || $req['requested_by'] === $approver['id'] || !user_is_admin($approver)) {
                throw new DomainException('Only the card owner of this record can approve it, and never the person who asked.');
            }
            $result = auth_apply($req, $approver);
            db_update('authorization_requests', [
                'status' => 'approved', 'decided_by' => $approver['id'], 'decided_at' => date('Y-m-d H:i:s'),
                'approval_method' => $method, 'decision_note' => $note, 'result' => $result,
            ], $req['id']);
            log_activity('authorization_approve', $req['record_type'], $req['record_id'], 'Approved ' . $req['request_number'] . ' (' . $req['action'] . ' ' . $req['record_ref'] . '): ' . $result,
                json_decode((string) $req['original_values'], true), json_decode((string) $req['proposed_values'], true), $approver['id']);
            notify_user($req['requested_by'], 'authorization_approved', 'Request ' . $req['request_number'] . ' approved', $result, 'authorization_request', $req['id']);

            return $result;
        });

        return null;
    } catch (DomainException | PDOException $e) {
        $message = $e instanceof PDOException ? db_error_message($e) : $e->getMessage();
        // The change was rolled back; keep the outcome for the audit trail.
        db_exec("UPDATE authorization_requests SET result = :r WHERE id = :id AND status = 'pending'", [':r' => 'Not applied: ' . $message, ':id' => $requestId]);
        log_activity('authorization_failed_apply', 'authorization_request', $requestId, 'Approval could not be applied: ' . $message, null, null, $approver['id']);

        return $message;
    }
}

function auth_reject(array $req, array $approver, string $note): void
{
    db_update('authorization_requests', ['status' => 'rejected', 'decided_by' => $approver['id'], 'decided_at' => date('Y-m-d H:i:s'),
        'approval_method' => 'session', 'decision_note' => $note, 'result' => 'Rejected - nothing was changed.'], $req['id']);
    log_activity('authorization_reject', $req['record_type'], $req['record_id'], 'Rejected ' . $req['request_number'] . ': ' . $note, null, null, $approver['id']);
    notify_user($req['requested_by'], 'authorization_rejected', 'Request ' . $req['request_number'] . ' rejected', $note, 'authorization_request', $req['id']);
}

/** Apply an approved request (inside the caller's transaction). Returns a result text. */
function auth_apply(array $req, array $approver): string
{
    $changes = json_decode((string) $req['proposed_values'], true) ?: [];
    if ($req['record_type'] === 'transaction') {
        $t = db_row('SELECT * FROM transactions WHERE id = :id FOR UPDATE', [':id' => $req['record_id']]);
        if (!$t || in_array($t['status'], TXN_LOCKED_STATUSES, true)) {
            throw new DomainException('The transaction no longer exists or is already cancelled.');
        }
        $r = ['record' => $t];
        if ($req['action'] === 'delete') {
            return transaction_void($t, $approver);
        }
        [, $errors] = auth_edit_changes('transaction', ['record' => $t, 'fields' => auth_record_fields('transaction')], $changes + $t);
        if ($errors) {
            throw new DomainException(reset($errors));
        }
        card_usage_apply([$t['credit_card_id']], static fn () => db_update('transactions', $changes + ['updated_by' => $approver['id']], $t['id']));
        borrowed_sync_penalty_payments($t['id']);

        return 'Transaction ' . $t['transaction_number'] . ' corrected: ' . auth_changes_text($t, $changes) . '.';
    }

    $p = db_row('SELECT * FROM borrower_payments WHERE id = :id FOR UPDATE', [':id' => $req['record_id']]);
    if (!$p || $p['status'] === 'cancelled') {
        throw new DomainException('The payment no longer exists or is already void.');
    }
    if ($req['action'] === 'delete') {
        return payment_void($p, $req['reason'] . ' (' . $req['request_number'] . ')', $approver);
    }
    if (isset($changes['amount'])) {
        $error = payment_override_amount(payment_find($p['id']) ?? $p, (string) $changes['amount'], 'Authorized correction: ' . $req['reason'], $approver);
        if ($error !== null) {
            throw new DomainException($error);
        }
    }
    $other = array_diff_key($changes, ['amount' => true]);
    if ($other) {
        if (isset($other['payment_date']) && $other['payment_date'] > date('Y-m-d')) {
            throw new DomainException('The payment date cannot be in the future.');
        }
        db_update('borrower_payments', $other, $p['id']);
        payment_log($p, $p['status'], 'corrected', 'Corrected with the owner\'s authorization: ' . auth_changes_text($p, $other) . '. Reason: ' . $req['reason'], $approver['id']);
    }
    if ($p['transaction_id']) {
        borrowed_sync_penalty_payments($p['transaction_id']);
    }
    receipt_note_change($p['id'], 'Payment ' . $p['payment_number'] . ' corrected with the owner\'s authorization (' . $req['request_number'] . '): ' . auth_changes_text($p, $changes), $approver['id']);

    return 'Payment ' . $p['payment_number'] . ' corrected: ' . auth_changes_text($p, $changes) . '.';
}

/** Editable fields of a record type (for re-validating at approval time). */
function auth_record_fields(string $type): array
{
    return $type === 'transaction' ? [
        'transaction_date' => ['label' => 'Transaction date', 'type' => 'date', 'required' => true],
        'amount'           => ['label' => 'Amount', 'type' => 'decimal', 'required' => true, 'min_exclusive' => 0],
        'reference_number' => ['label' => 'Reference number', 'type' => 'text', 'max' => 150],
        'receipt_number'   => ['label' => 'Receipt number', 'type' => 'text', 'max' => 150],
        'notes'            => ['label' => 'Notes', 'type' => 'textarea'],
    ] : [];
}

/** "amount ₱500.00 → ₱450.00, date ..." */
function auth_changes_text(array $old, array $changes): string
{
    $parts = [];
    foreach ($changes as $k => $v) {
        $from = $old[$k] ?? null;
        $fmt = static fn ($x) => $x === null || $x === '' ? '(empty)' : (in_array($k, ['amount'], true) ? format_money($x) : (str_ends_with($k, '_date') ? format_date($x) : excerpt((string) $x, 40)));
        $parts[] = humanize($k) . ' ' . $fmt($from) . ' → ' . $fmt($v);
    }

    return implode(', ', $parts);
}

function auth_find(string $id): ?array
{
    return is_valid_uuid($id) ? db_row('SELECT r.* FROM authorization_requests r WHERE r.id = :id AND ' . owner_scope('authorization_requests', 'r'), [':id' => $id]) : null;
}

function auth_status_badge(string $status): string
{
    return status_badge($status, ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'failed' => 'danger', 'cancelled' => 'muted']);
}

/**
 * Void a transaction (the owner's "Delete" for a confirmed one, directly or
 * through an approved request): status cancelled, out of the card's used
 * credit, its plan and unpaid obligations cancelled. The record is kept.
 * Must run inside a transaction; throws DomainException when it cannot.
 */
function transaction_void(array $t, array $user): string
{
    if ($problem = auth_delete_problem('transaction', ['record' => $t])) {
        throw new DomainException($problem);
    }
    card_usage_apply([$t['credit_card_id']], static function () use ($t, $user) {
        db_update('transactions', ['status' => 'cancelled', 'updated_by' => $user['id']], $t['id']);
        db_exec("UPDATE installment_plans SET status = 'cancelled' WHERE transaction_id = :t", [':t' => $t['id']]);
        db_exec("UPDATE borrower_obligations SET status = 'cancelled' WHERE transaction_id = :t AND total_paid = 0 AND status <> 'cancelled'", [':t' => $t['id']]);
    });

    return 'Transaction ' . $t['transaction_number'] . ' (' . format_money($t['amount']) . ') was voided: status cancelled, removed from the card\'s used credit. The record is kept.';
}

/**
 * Void a payment (the owner's "Delete", directly or through an approved
 * request): everything it paid is given back newest first (balances,
 * installments, penalties restored), it becomes cancelled and stays in the
 * history; its receipt is marked void or amended. Must run inside a transaction.
 */
function payment_void(array $p, string $reason, array $user): string
{
    foreach (db_all('SELECT * FROM borrower_payment_allocations WHERE borrower_payment_id = :p ORDER BY created_at DESC, id DESC FOR UPDATE', [':p' => $p['id']]) as $a) {
        payment_reverse_allocation($a, (float) $a['allocated_amount']);
    }
    db_update('borrower_payments', ['status' => 'cancelled', 'allocation_status' => 'unallocated'], $p['id']);
    payment_log($p, 'cancelled', 'voided', 'Voided by ' . user_name_of($user['id']) . ': ' . $reason, $user['id']);
    if ($p['transaction_id']) {
        borrowed_sync_penalty_payments($p['transaction_id']);
    }
    receipt_note_change($p['id'], 'Payment ' . $p['payment_number'] . ' (' . format_money($p['amount']) . ') voided: ' . $reason, $user['id']);
    notify_borrower($p['borrower_id'], 'payment_voided', 'Payment ' . $p['payment_number'] . ' voided',
        'Your payment of ' . format_money($p['amount']) . ' on ' . format_date($p['payment_date']) . ' was voided. Reason: ' . $reason, 'borrower_payment', $p['id']);
    log_activity('void', 'borrower_payment', $p['id'], 'Voided payment ' . $p['payment_number'] . ' of ' . format_money($p['amount']) . ': ' . $reason, ['status' => $p['status']], ['status' => 'cancelled'], $user['id']);

    return 'Payment ' . $p['payment_number'] . ' (' . format_money($p['amount']) . ') was voided; what it paid is owed again and its receipt is marked void when no payment on it counts.';
}
