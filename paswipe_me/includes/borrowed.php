<?php
/**
 * Borrowed transactions: a registered user (the borrower) uses a card owner's
 * credit card for a purchase.
 *
 * Nothing is duplicated:
 *   - the purchase is ONE row in transactions, on the owner's card (it is in
 *     the owner's transactions, statements and card balance exactly once);
 *   - what the borrower has to pay back is the borrower_obligations row of
 *     that transaction (a receivable of the owner, never a card charge);
 *   - repayments are borrower_payments with the borrower's choice in
 *     borrower_payment_selections; the owner confirms them on Borrower
 *     Payments → Review, which applies them to the obligation (existing flow).
 *
 * Who is the borrower: the owner's borrowers record linked to the registered
 * user's account (borrower_user_accounts). One registered user can borrow from
 * several owners - one borrowers record per owner, all linked to the same user
 * (db/upgrade_borrowed.sql lets a user have more than one link).
 *
 * Due date: the payment due date of the owner's card for the billing period
 * the purchase falls in - the recorded SOA's due date when the bank's SOA is
 * in, otherwise the card's due date rule (includes/statement_engine.php).
 */

defined('APP_ROOT') or exit('No direct access.');

const BORROWED_UPGRADE_MARKER = 'storage/upgrade_borrowed.done';

/** One-time schema change: a user may be linked to one borrower record per owner. */
function borrowed_schema_upgrade(): void
{
    if (marker_exists(BORROWED_UPGRADE_MARKER)) {
        return;
    }
    $index = static fn (string $name) => (int) db_value(
        "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'borrower_user_accounts' AND INDEX_NAME = :i",
        [':i' => $name]
    ) > 0;
    if (!$index('uq_bua_user_borrower')) {
        db()->exec('ALTER TABLE borrower_user_accounts ADD UNIQUE KEY uq_bua_user_borrower (user_id, borrower_id)');
    }
    if ($index('user_id')) {
        db()->exec('ALTER TABLE borrower_user_accounts DROP INDEX user_id');
    }
    marker_write(BORROWED_UPGRADE_MARKER);
}

/** Registered users (owner/administrator accounts) - not borrower portal accounts. */
function borrowed_registered_users_sql(): string
{
    return "(SELECT ur.user_id FROM user_roles ur WHERE ur.role IN ('owner', 'admin'))";
}

/**
 * The signed-in owner's borrower record for the registered user with this
 * email, created and linked when it does not exist yet.
 * Returns [borrower id, null] or [null, error message].
 */
function borrowed_link_user(string $email): array
{
    $email = mb_strtolower(trim($email));
    $user = db_row(
        "SELECT u.id, u.first_name, u.last_name, u.email, u.mobile FROM users u
          WHERE LOWER(u.email) = :e AND u.status = 'active' AND u.id IN " . borrowed_registered_users_sql(),
        [':e' => $email]
    );
    if (!$user) {
        return [null, 'No registered user with this email. Ask them to create an account first (Sign in → Create an account).'];
    }
    if ($user['id'] === owner_id()) {
        return [null, 'That is your own account. Leave the borrower empty for your own purchases.'];
    }

    $existing = db_value(
        'SELECT b.id FROM borrowers b JOIN borrower_user_accounts a ON a.borrower_id = b.id
          WHERE a.user_id = :u AND ' . owner_scope('borrowers', 'b') . ' ORDER BY a.activated_at LIMIT 1',
        [':u' => $user['id']]
    );
    if ($existing) {
        return [(string) $existing, null];
    }

    $borrowerId = new_uuid();
    db_transaction(static function () use ($borrowerId, $user) {
        db_insert('borrowers', [
            'id'              => $borrowerId,
            'owner_user_id'   => owner_id(),
            'borrower_number' => generate_reference('BRW', 'borrowers', 'borrower_number'),
            'first_name'      => $user['first_name'] ?: $user['email'],
            'last_name'       => $user['last_name'] ?: '',
            'primary_email'   => $user['email'],
            'primary_mobile'  => $user['mobile'],
            'status'          => 'good_standing',
            'notes'           => 'Registered user who borrows your card.',
            'created_by'      => $_SESSION['user_id'],
            'updated_by'      => $_SESSION['user_id'],
        ]);
        db_insert('borrower_user_accounts', [
            'id' => new_uuid(), 'user_id' => $user['id'], 'borrower_id' => $borrowerId, 'activated_at' => date('Y-m-d H:i:s'),
        ]);
    });
    log_activity('link_account', 'borrower', $borrowerId, 'Registered user ' . $user['email'] . ' added as a borrower of your card');

    return [$borrowerId, null];
}

/** Is this borrower record linked to a registered user's account? Returns the user id. */
function borrowed_user_of(?string $borrowerId): ?string
{
    if (!$borrowerId) {
        return null;
    }
    $userId = db_value(
        'SELECT a.user_id FROM borrower_user_accounts a WHERE a.borrower_id = :b AND a.deactivated_at IS NULL AND a.user_id IN ' . borrowed_registered_users_sql(),
        [':b' => $borrowerId]
    );

    return $userId ? (string) $userId : null;
}

/** When the borrower has to pay back a purchase on this card made on $date. */
function borrowed_due_date(string $cardId, string $date): string
{
    $card = db_row('SELECT * FROM credit_cards WHERE id = :c', [':c' => $cardId]);
    $period = card_period_containing(card_period_context($card), $date);
    if ($period['statement_id'] && $period['recorded']) {
        $due = db_value('SELECT due_date FROM credit_card_statements WHERE id = :s', [':s' => $period['statement_id']]);
        if ($due) {
            return (string) $due;   // the bank's actual due date
        }
    }
    [$due] = card_due_date($card, $period['end']);

    return $due ?? $period['end'];   // no due date rule on the card: the cutoff date
}

/**
 * Keep the "amount to repay" of a transaction in line with it: created when a
 * registered user's borrower is on the transaction, amount / date / due date
 * updated while nothing has been repaid, cancelled when the borrower is
 * removed before any repayment. Cash loans and cash advances keep their own
 * flows. Returns a message for the owner, or null. Run after saving.
 */
function borrowed_sync_obligation(string $transactionId, string $actorId): ?string
{
    $t = db_row(
        'SELECT t.*, m.merchant_name FROM transactions t LEFT JOIN merchants m ON m.id = t.merchant_id WHERE t.id = :id',
        [':id' => $transactionId]
    );
    if (!$t) {
        return null;
    }
    $o = db_row("SELECT * FROM borrower_obligations WHERE transaction_id = :t AND status <> 'cancelled' ORDER BY created_at LIMIT 1", [':t' => $t['id']]);
    $registered = borrowed_user_of($t['borrower_id']) !== null;
    $eligible = $registered && !in_array($t['payment_type'], ['cash_loan', 'cash_advance'], true)
        && !in_array($t['status'], ['cancelled', 'refunded', 'draft'], true);
    $money = static fn ($v) => number_format((float) $v, 2, '.', '');
    $description = 'Card purchase ' . $t['transaction_number'] . ($t['merchant_name'] ? ' at ' . $t['merchant_name'] : '');

    if ($o === null) {
        if (!$eligible) {
            return null;
        }
        $id = new_uuid();
        $number = generate_reference('OBL', 'borrower_obligations', 'obligation_number');
        $due = borrowed_due_date($t['credit_card_id'], $t['transaction_date']);
        db_insert('borrower_obligations', [
            'id' => $id, 'obligation_number' => $number, 'borrower_id' => $t['borrower_id'], 'transaction_id' => $t['id'],
            'description' => $description, 'original_amount' => $money($t['amount']), 'agreed_interest' => '0.00', 'agreed_fees' => '0.00',
            'total_amount_due' => $money($t['amount']), 'total_paid' => '0.00', 'outstanding_amount' => $money($t['amount']),
            'status' => 'active', 'obligation_date' => $t['transaction_date'], 'due_date' => $due,
            'notes' => 'Created automatically: the borrower used your card for this purchase.',
            'created_by' => $actorId, 'updated_by' => $actorId,
        ]);
        log_activity('create', 'obligation', $id, 'Amount to repay ' . $number . ' for ' . format_money($t['amount']) . ' from transaction ' . $t['transaction_number'], null, null, $actorId);
        penalty_assign_on_create($id);
        notify_borrower($t['borrower_id'], 'obligation_created', 'Amount to repay: ' . format_money($t['amount']),
            'You used a card for ' . $description . ' on ' . format_date($t['transaction_date']) . '. Please repay ' . format_money($t['amount']) . ' by ' . format_date($due) . '.',
            'obligation', $id);

        return 'Added ' . format_money($t['amount']) . ' to the borrower\'s amounts to repay (' . $number . '), due ' . format_date($due) . '.';
    }

    // Only obligations made by this feature follow the transaction; a hand-made one is left alone.
    if (!str_starts_with((string) $o['notes'], 'Created automatically: the borrower used your card')) {
        return null;
    }
    if ((float) $o['total_paid'] > 0.004 || db_value('SELECT COUNT(*) FROM borrower_payment_allocations WHERE obligation_id = :o', [':o' => $o['id']]) > 0) {
        return $eligible && (abs((float) $o['original_amount'] - (float) $t['amount']) > 0.004 || $o['borrower_id'] !== $t['borrower_id'])
            ? 'Repayments are already recorded on ' . $o['obligation_number'] . ', so its amount and borrower were not changed. Edit the obligation if needed.'
            : null;
    }
    if (!$eligible || $o['borrower_id'] !== $t['borrower_id']) {
        db_update('borrower_obligations', ['status' => 'cancelled', 'updated_by' => $actorId], $o['id']);
        log_activity('cancel', 'obligation', $o['id'], 'Amount to repay ' . $o['obligation_number'] . ' cancelled: the transaction no longer has this borrower', null, null, $actorId);
        $note = 'The borrower\'s amount to repay ' . $o['obligation_number'] . ' was cancelled.';

        return $eligible ? $note . ' ' . borrowed_sync_obligation($transactionId, $actorId) : $note;
    }
    $due = borrowed_due_date($t['credit_card_id'], $t['transaction_date']);
    $update = [
        'description' => $description, 'original_amount' => $money($t['amount']), 'total_amount_due' => $money($t['amount']),
        'outstanding_amount' => $money($t['amount']), 'obligation_date' => $t['transaction_date'], 'due_date' => $due,
    ];
    if (array_diff_assoc($update, array_intersect_key($o, $update))) {
        db_update('borrower_obligations', $update + ['updated_by' => $actorId], $o['id']);
        log_activity('update', 'obligation', $o['id'], 'Amount to repay ' . $o['obligation_number'] . ' follows transaction ' . $t['transaction_number'], null, $update, $actorId);

        return 'The borrower\'s amount to repay ' . $o['obligation_number'] . ' now shows ' . format_money($t['amount']) . ', due ' . format_date($due) . '.';
    }

    return null;
}

/* ------------------------------------------------------------------
 * The borrower's side: what the signed-in user borrowed from others
 * ------------------------------------------------------------------ */

/** Borrower records (of other owners) linked to the signed-in user. */
function borrowed_my_borrowers_sql(): string
{
    $me = db()->quote((string) ($_SESSION['user_id'] ?? ''));

    return "(SELECT mba.borrower_id FROM borrower_user_accounts mba JOIN borrowers mb ON mb.id = mba.borrower_id
              WHERE mba.user_id = $me AND mba.deactivated_at IS NULL AND (mb.owner_user_id IS NULL OR mb.owner_user_id <> $me))";
}

/** Status of an amount to repay: paid, overdue, partially paid, due soon or upcoming. */
function borrowed_status_sql(string $o = 'o'): string
{
    return "(CASE WHEN $o.outstanding_amount <= 0.004 THEN 'paid'
                  WHEN $o.due_date IS NOT NULL AND $o.due_date < CURDATE() THEN 'overdue'
                  WHEN $o.total_paid > 0 THEN 'partially_paid'
                  WHEN $o.due_date IS NOT NULL AND $o.due_date <= CURDATE() + INTERVAL 7 DAY THEN 'due'
                  ELSE 'upcoming' END)";
}

/** Repayments the borrower recorded that the owner has not confirmed yet. */
function borrowed_pending_sql(string $o = 'o'): string
{
    return "(SELECT COALESCE(SUM(s.amount), 0) FROM borrower_payment_selections s JOIN borrower_payments p ON p.id = s.borrower_payment_id
              WHERE s.obligation_id = $o.id AND p.status IN ('submitted', 'under_review', 'correction_required'))";
}

/**
 * Everything the signed-in user borrowed (only their own items - never the
 * owner's other records). With $id, that one item.
 */
function borrowed_items(?string $id = null): array
{
    return db_all(
        "SELECT o.id, o.obligation_number, o.description, o.obligation_date, o.due_date, o.total_amount_due, o.total_paid,
                o.outstanding_amount, o.status AS obligation_status, o.borrower_id,
                t.transaction_number, t.transaction_date, t.amount AS transaction_amount, t.notes AS transaction_notes,
                m.merchant_name, c.issuer, c.last_four,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email) AS lender_name,
                " . borrowed_status_sql() . ' AS repay_status, ' . borrowed_pending_sql() . " AS pending
           FROM borrower_obligations o
           JOIN borrowers b ON b.id = o.borrower_id
           LEFT JOIN users u ON u.id = b.owner_user_id
           LEFT JOIN transactions t ON t.id = o.transaction_id
           LEFT JOIN credit_cards c ON c.id = t.credit_card_id
           LEFT JOIN merchants m ON m.id = t.merchant_id
          WHERE o.borrower_id IN " . borrowed_my_borrowers_sql() . " AND o.status <> 'cancelled'" . ($id !== null ? ' AND o.id = :id' : '') . '
          ORDER BY o.outstanding_amount <= 0.004, o.due_date IS NULL, o.due_date, o.obligation_date DESC',
        $id !== null ? [':id' => $id] : []
    );
}

/** Totals for the borrower's dashboard. */
function borrowed_summary(): array
{
    $rows = borrowed_items();
    $sum = ['count' => count($rows), 'borrowed' => 0.0, 'repaid' => 0.0, 'outstanding' => 0.0, 'pending' => 0.0,
            'overdue' => 0, 'overdue_amount' => 0.0, 'upcoming' => 0, 'next_due' => null, 'open' => 0];
    foreach ($rows as $r) {
        $sum['borrowed'] += (float) $r['total_amount_due'];
        $sum['repaid'] += (float) $r['total_paid'];
        $sum['outstanding'] += (float) $r['outstanding_amount'];
        $sum['pending'] += (float) $r['pending'];
        if ($r['repay_status'] === 'paid') {
            continue;
        }
        $sum['open']++;
        if ($r['repay_status'] === 'overdue') {
            $sum['overdue']++;
            $sum['overdue_amount'] += (float) $r['outstanding_amount'];
        } elseif ($r['due_date']) {
            $sum['upcoming']++;
            $sum['next_due'] = $sum['next_due'] === null || $r['due_date'] < $sum['next_due'] ? $r['due_date'] : $sum['next_due'];
        }
    }

    return $sum;
}

/** Repayments the signed-in user recorded for one item (all statuses: the history). */
function borrowed_repayments(string $obligationId): array
{
    return db_all(
        "SELECT p.id, p.payment_number, p.payment_date, p.status, p.reference_number, p.notes, p.submitted_at, p.approved_at, p.rejection_reason,
                s.amount AS amount_for_item, p.amount,
                (SELECT COALESCE(SUM(a.allocated_amount), 0) FROM borrower_payment_allocations a WHERE a.borrower_payment_id = p.id AND a.obligation_id = :o2) AS applied
           FROM borrower_payment_selections s JOIN borrower_payments p ON p.id = s.borrower_payment_id
          WHERE s.obligation_id = :o AND p.borrower_id IN " . borrowed_my_borrowers_sql() . '
          ORDER BY p.payment_date DESC, p.submitted_at DESC',
        [':o' => $obligationId, ':o2' => $obligationId]
    );
}

function borrowed_status_badge(string $status): string
{
    return status_badge(match ($status) {
        'due' => 'due soon',
        default => $status,
    }, ['paid' => 'success', 'overdue' => 'danger', 'partially_paid' => 'warning', 'due soon' => 'warning', 'upcoming' => 'info']);
}

/** Does the signed-in user have anything borrowed (shows the menu item)? */
function borrowed_has_items(): bool
{
    static $has = null;

    return $has ??= is_logged_in() && (int) db_value('SELECT COUNT(*) FROM borrower_obligations o WHERE o.borrower_id IN ' . borrowed_my_borrowers_sql()) > 0;
}

/**
 * Re-derive the repay-by dates of a card's open borrowed purchases, e.g. after
 * the bank's SOA (with its actual due date) was recorded or the card's
 * billing settings changed. The borrower is told when a date moves.
 */
function borrowed_refresh_due_dates(string $cardId): int
{
    $moved = 0;
    foreach (db_all(
        "SELECT o.id, o.borrower_id, o.due_date, o.obligation_number, t.transaction_date FROM borrower_obligations o JOIN transactions t ON t.id = o.transaction_id
          WHERE t.credit_card_id = :c AND o.status NOT IN ('cancelled', 'paid', 'written_off') AND o.outstanding_amount > 0
            AND o.notes LIKE 'Created automatically: the borrower used your card%'",
        [':c' => $cardId]
    ) as $o) {
        $due = borrowed_due_date($cardId, $o['transaction_date']);
        if ($due !== $o['due_date']) {
            db_update('borrower_obligations', ['due_date' => $due], $o['id']);
            notify_borrower($o['borrower_id'], 'due_date_changed', 'New repay-by date: ' . format_date($due),
                'The card\'s payment due date for ' . $o['obligation_number'] . ' is ' . format_date($due) . '. Please repay by then.', 'obligation', $o['id']);
            $moved++;
        }
    }

    return $moved;
}
