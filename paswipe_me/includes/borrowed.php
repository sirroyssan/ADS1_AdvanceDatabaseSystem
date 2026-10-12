<?php
/**
 * Borrowed transactions: someone else (the borrower) uses a card owner's
 * credit card for a purchase.
 *
 * The transaction is the single source of truth - nothing is copied:
 *   - the purchase is ONE row in transactions, on the owner's card, with the
 *     borrower in transactions.borrower_id (the owner's borrowers record). It
 *     counts once in the owner's transactions, statements and card balance;
 *   - the borrower sees that same row in their account (Amounts to Repay /
 *     portal Card purchases): every transaction whose borrower is linked to
 *     their sign-in account (borrower_user_accounts);
 *   - repayments are borrower_payments with transaction_id = the purchase:
 *     "submitted" = reported by the borrower, "approved" = confirmed by the
 *     owner. Only confirmed repayments reduce what is owed;
 *   - late penalties are applied_penalties with transaction_id = the purchase
 *     (obligation_id NULL), charged from the card owner's penalty rules for the
 *     card's bank, once per rule and period. They never touch the card balance.
 * No obligation is created. A transaction that already has an obligation from
 * the older workflow keeps being tracked by that obligation (shown as such),
 * so nothing is counted twice.
 *
 * Repay-by date of a charge: the payment due date of the statement period the
 * charge belongs to - the recorded SOA's due date, else (only when the card has
 * a cutoff day and a due-date rule) the date the card's rule gives. Otherwise
 * "not available yet" - a date is never guessed. An installment purchase is
 * repaid installment by installment, each by its own statement's due date.
 */

defined('APP_ROOT') or exit('No direct access.');

const BORROWED_UPGRADE_MARKER = 'storage/upgrade_borrowed.done';
const BORROWED_UPGRADE_V2_MARKER = 'storage/upgrade_borrowed_v2.done';
/** Repayments the owner has not confirmed yet ("reported"). */
const BORROWED_REPORTED_STATUSES = ['submitted', 'under_review', 'correction_required'];
const BORROWED_AUTO_NOTE = 'Created automatically: the borrower used your card';

/* ------------------------------------------------------------------
 * Schema (db/upgrade_borrowed.sql) and the move away from obligations
 * ------------------------------------------------------------------ */

function borrowed_schema_upgrade(): void
{
    if (!marker_exists(BORROWED_UPGRADE_MARKER)) {
        // A user may be linked to one borrower record per card owner.
        if (!borrowed_has_index('borrower_user_accounts', 'uq_bua_user_borrower')) {
            db()->exec('ALTER TABLE borrower_user_accounts ADD UNIQUE KEY uq_bua_user_borrower (user_id, borrower_id)');
        }
        if (borrowed_has_index('borrower_user_accounts', 'user_id')) {
            db()->exec('ALTER TABLE borrower_user_accounts DROP INDEX user_id');
        }
        marker_write(BORROWED_UPGRADE_MARKER);
    }
    if (!marker_exists(BORROWED_UPGRADE_V2_MARKER)) {
        @set_time_limit(300);
        borrowed_schema_v2();
        borrowed_retire_auto_obligations();
        marker_write(BORROWED_UPGRADE_V2_MARKER);
    }
}

function borrowed_has_index(string $table, string $index): bool
{
    return (int) db_value('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i',
        [':t' => $table, ':i' => $index]) > 0;
}

function borrowed_has_column(string $table, string $column): bool
{
    return (int) db_value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
        [':t' => $table, ':c' => $column]) > 0;
}

/**
 * Repayments and penalties can point at the transaction itself:
 * borrower_payments.transaction_id, applied_penalties.transaction_id (and its
 * obligation_id may be empty). The tables changed are copied first
 * (backup_borrowed_<table>, inside the database).
 */
function borrowed_schema_v2(): void
{
    foreach (['borrower_obligations', 'borrower_payments', 'borrower_payment_selections', 'borrower_payment_allocations', 'applied_penalties'] as $table) {
        $copy = 'backup_borrowed_' . $table;
        if (!(int) db_value('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t', [':t' => $copy])) {
            db()->exec('CREATE TABLE ' . db_identifier($copy) . ' LIKE ' . db_identifier($table));
            db()->exec('INSERT INTO ' . db_identifier($copy) . ' SELECT * FROM ' . db_identifier($table));
        }
    }
    $id = db_row("SELECT CHARACTER_SET_NAME AS cs, COLLATION_NAME AS co FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'id'");
    $type = 'CHAR(36)' . ($id && $id['cs'] ? ' CHARACTER SET ' . $id['cs'] . ' COLLATE ' . $id['co'] : '');

    foreach (['borrower_payments' => 'bp', 'applied_penalties' => 'ap'] as $table => $short) {
        if (!borrowed_has_column($table, 'transaction_id')) {
            db()->exec("ALTER TABLE $table ADD COLUMN transaction_id $type NULL");
        }
        if (!borrowed_has_index($table, "idx_{$short}_transaction")) {
            db()->exec("CREATE INDEX idx_{$short}_transaction ON $table (transaction_id)");
        }
        try {
            if (!(int) db_value("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
                                  AND COLUMN_NAME = 'transaction_id' AND REFERENCED_TABLE_NAME = 'transactions'", [':t' => $table])) {
                db()->exec("ALTER TABLE $table ADD CONSTRAINT fk_{$short}_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id)");
            }
        } catch (PDOException $e) {
            error_log('[' . APP_NAME . '] Borrowed upgrade: foreign key on ' . $table . '.transaction_id not added: ' . $e->getMessage());
        }
    }
    // A penalty of a transaction has no obligation.
    $col = db_row("SELECT IS_NULLABLE AS n, CHARACTER_SET_NAME AS cs, COLLATION_NAME AS co FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applied_penalties' AND COLUMN_NAME = 'obligation_id'");
    if ($col && $col['n'] === 'NO') {
        db()->exec('ALTER TABLE applied_penalties MODIFY obligation_id CHAR(36)' . ($col['cs'] ? ' CHARACTER SET ' . $col['cs'] . ' COLLATE ' . $col['co'] : '') . ' NULL');
    }
}

/**
 * The previous version created an obligation for every borrowed purchase.
 * They are retired (status cancelled, kept for history): their repayments and
 * penalties are moved onto the transaction, where they keep counting.
 */
function borrowed_retire_auto_obligations(): void
{
    db_transaction(static function () {
        foreach (db_all("SELECT * FROM borrower_obligations WHERE notes LIKE :n AND status <> 'cancelled' AND transaction_id IS NOT NULL",
            [':n' => BORROWED_AUTO_NOTE . '%']) as $o) {
            // Payments made only for this obligation (chosen or allocated) now belong to the purchase.
            db_exec(
                'UPDATE borrower_payments p SET p.transaction_id = :t
                  WHERE p.transaction_id IS NULL
                    AND (p.id IN (SELECT s.borrower_payment_id FROM borrower_payment_selections s WHERE s.obligation_id = :o1)
                         OR p.id IN (SELECT a.borrower_payment_id FROM borrower_payment_allocations a WHERE a.obligation_id = :o2))
                    AND NOT EXISTS (SELECT 1 FROM borrower_payment_selections x WHERE x.borrower_payment_id = p.id AND x.obligation_id <> :o3)
                    AND NOT EXISTS (SELECT 1 FROM borrower_payment_allocations y WHERE y.borrower_payment_id = p.id AND y.obligation_id <> :o4)',
                [':t' => $o['transaction_id'], ':o1' => $o['id'], ':o2' => $o['id'], ':o3' => $o['id'], ':o4' => $o['id']]
            );
            db_exec("UPDATE borrower_payments SET allocation_status = 'fully_allocated' WHERE transaction_id = :t AND status = 'approved'", [':t' => $o['transaction_id']]);
            db_exec('UPDATE applied_penalties SET transaction_id = :t WHERE obligation_id = :o', [':t' => $o['transaction_id'], ':o' => $o['id']]);
            db_update('borrower_obligations', [
                'status' => 'cancelled',
                'notes'  => trim((string) $o['notes'] . "\nRetired " . date('Y-m-d') . ': the purchase is now tracked on its transaction (no separate obligation).'),
            ], $o['id']);
            log_activity('cancel', 'obligation', $o['id'], 'Retired ' . $o['obligation_number'] . ': borrowed purchases are tracked on the transaction itself',
                null, null, (string) (db_value('SELECT owner_user_id FROM borrowers WHERE id = :b', [':b' => $o['borrower_id']]) ?: SYSTEM_ACTOR));
        }
    });
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


/* ------------------------------------------------------------------
 * Due dates, balances and status of one borrowed purchase
 * ------------------------------------------------------------------ */

/** Card row and its billing periods (cached per request). */
function borrowed_card_context(string $cardId): ?array
{
    static $cache = [];
    if (!array_key_exists($cardId, $cache)) {
        $card = db_row('SELECT * FROM credit_cards WHERE id = :c', [':c' => $cardId]);
        $cache[$cardId] = $card ? card_period_context($card) : null;
    }

    return $cache[$cardId];
}

/**
 * Repay-by date of a charge billed on $date: the due date of the statement
 * period it belongs to. ['date' => ?string, 'source' => 'statement'|'card rule'|null,
 * 'statement' => month label, 'statement_id' => ?string]
 */
function borrowed_due_for(string $cardId, string $date): array
{
    $ctx = borrowed_card_context($cardId);
    if ($ctx === null) {
        return ['date' => null, 'source' => null, 'statement' => null, 'statement_id' => null];
    }

    return borrowed_due_of_period($ctx, card_period_containing($ctx, $date));
}

/** Payment due date of one billing period of a card (same rules as borrowed_due_for). */
function borrowed_due_of_period(array $ctx, array $period): array
{
    $card = $ctx['card'];
    $out = ['date' => null, 'source' => null, 'statement' => statement_month_label($period['end']), 'statement_id' => $period['statement_id']];
    if ($period['statement_id'] && $period['recorded']) {
        $due = db_value('SELECT due_date FROM credit_card_statements WHERE id = :s', [':s' => $period['statement_id']]);
        if ($due) {
            return ['date' => (string) $due, 'source' => 'statement'] + $out;
        }
    }
    if ((int) $card['billing_cycle_day'] > 0) {
        [$due, $known] = card_due_date($card, $period['end']);
        if ($known && $due) {
            return ['date' => $due, 'source' => 'card rule'] + $out;
        }
    }

    return $out;   // not enough information: no date is invented
}

/** Active rules of the card owner for the card's bank (same matching as obligations). */
function borrowed_rules_for_card(string $cardId): array
{
    $card = db_row('SELECT owner_user_id, issuer FROM credit_cards WHERE id = :c', [':c' => $cardId]);
    if (!$card) {
        return [];
    }
    $rules = db_all(
        'SELECT r.*, GREATEST(COALESCE(r.effective_from, DATE(r.updated_at)), DATE(r.updated_at)) AS charges_from
           FROM penalty_rules r
          WHERE r.is_active = 1 AND r.applies_to_issuer IS NOT NULL AND r.owner_user_id = :o ORDER BY r.name',
        [':o' => $card['owner_user_id']]
    );

    return array_values(array_filter($rules, static fn ($r) => same_bank($r['applies_to_issuer'], $card['issuer'])));
}

/** The obligation that still tracks a purchase from the older workflow, if any. */
function borrowed_legacy_obligation(string $transactionId): ?array
{
    return db_row(
        "SELECT o.*, (SELECT COALESCE(SUM(ap.remaining_amount), 0) FROM applied_penalties ap WHERE ap.obligation_id = o.id) AS penalties_unpaid,
                (SELECT COALESCE(SUM(ap.amount), 0) FROM applied_penalties ap WHERE ap.obligation_id = o.id) AS penalties_charged
           FROM borrower_obligations o WHERE o.transaction_id = :t AND o.status NOT IN ('cancelled') ORDER BY o.created_at LIMIT 1",
        [':t' => $transactionId]
    ) ?: null;
}

/**
 * Everything about what the borrower owes for one purchase, computed from the
 * transaction, its installment schedule, its confirmed / reported repayments
 * and its penalties. $t needs id, amount, credit_card_id, transaction_date.
 */
/**
 * Due this period of an obligation-tracked purchase: its overdue months plus the
 * current month of its monthly schedule (plus unpaid penalties); the whole
 * outstanding when it has no monthly schedule.
 */
function borrowed_obligation_due_now(array $o, string $today, float $outstanding, float $penalties): array
{
    [$rows] = obligation_schedule_view($o);
    $rows = array_values(array_filter($rows, static fn ($r) => !in_array($r['status'], ['cancelled', 'waived'], true)));
    $monthEnd = date('Y-m-t', strtotime($today));
    if (count($rows) < 2) {
        // One amount: due this month when its due date is this month or earlier (no date: due now).
        $dueMonth = !$o['due_date'] || $o['due_date'] <= $monthEnd ? $outstanding : min($penalties, $outstanding);

        return ['due_now' => $outstanding, 'due_month' => round($dueMonth, 2), 'current' => null, 'installments' => 0, 'monthly' => null];
    }
    $due = 0.0;
    $month = 0.0;
    $current = null;
    foreach ($rows as $i => $r) {
        $left = (float) $r['remaining_amount'];
        if ($left <= 0.004) {
            continue;
        }
        if ($r['due_date'] <= $monthEnd) {
            $month += $left;
        }
        if ($r['due_date'] < $today) {
            $due += $left;
        } elseif ($current === null) {
            $current = $i;
            $due += $left;
        }
    }
    $current ??= array_key_first(array_filter($rows, static fn ($r) => (float) $r['remaining_amount'] > 0.004));

    return [
        'due_now' => round(min($due + $penalties, $outstanding), 2), 'due_month' => round(min($month + $penalties, $outstanding), 2),
        'installments' => count($rows), 'monthly' => (float) $rows[0]['amount_due'],
        'current' => $current === null ? null : ['n' => $current + 1, 'amount' => (float) $rows[$current]['amount_due'], 'remaining' => (float) $rows[$current]['remaining_amount'],
                                                 'due' => ['date' => $rows[$current]['due_date'], 'source' => 'obligation', 'statement' => null]],
    ];
}

function borrowed_ledger(array $t, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $money = static fn (float $v) => round($v, 2);

    $legacy = borrowed_legacy_obligation($t['id']);
    if ($legacy !== null) {
        $remaining = (float) $legacy['outstanding_amount'];
        $penalties = (float) $legacy['penalties_unpaid'];
        $status = match (true) {
            $remaining + $penalties <= 0.004 => 'repaid',
            $legacy['due_date'] && $legacy['due_date'] < $today => 'overdue',
            (float) $legacy['total_paid'] > 0 => 'partially_repaid',
            default => 'pending',
        };

        return [
            'mode' => 'obligation', 'obligation' => $legacy, 'amount' => (float) $t['amount'], 'responsible' => (float) $legacy['total_amount_due'],
            'repaid' => (float) $legacy['total_paid'], 'reported' => 0.0, 'remaining' => $remaining, 'penalties' => (float) $legacy['penalties_charged'],
            'penalty_paid' => $money((float) $legacy['penalties_charged'] - $penalties), 'penalty_unpaid' => $penalties,
            'outstanding' => $money($remaining + $penalties), 'rows' => [], 'next_due' => $legacy['due_date'] ? ['date' => $legacy['due_date'], 'source' => 'obligation', 'statement' => null] : null,
            'status' => $status, 'overdue_amount' => $status === 'overdue' ? $remaining : 0.0, 'review' => null, 'unknown_due' => !$legacy['due_date'],
            'terms_missing' => false,
        ] + borrowed_obligation_due_now($legacy, $today, $money($remaining + $penalties), $penalties);
    }

    // What the borrower is responsible for: each installment billed (installment purchase) or the purchase itself.
    $rows = [];
    $plan = db_row("SELECT id, installment_amount FROM installment_plans WHERE transaction_id = :t AND status <> 'cancelled'", [':t' => $t['id']]);
    $ctx = borrowed_card_context($t['credit_card_id']);
    if ($plan && $ctx) {
        $schedule = db_all("SELECT id, installment_number, due_date, amount_due, status FROM installment_schedules
                             WHERE installment_plan_id = :p AND status NOT IN ('cancelled', 'waived') ORDER BY installment_number", [':p' => $plan['id']]);
        // Each installment is repaid by the due date of the statement that bills it (from the transaction date).
        $billing = installment_billing($ctx, $t['transaction_date'], (int) max(array_column($schedule, 'installment_number') ?: [0]));
        foreach ($schedule as $s) {
            $billed = $billing[(int) $s['installment_number']];
            $rows[] = ['schedule_id' => $s['id'], 'label' => 'Installment ' . $s['installment_number'], 'charge_date' => $billed['date'],
                       'amount' => (float) $s['amount_due'], 'due' => borrowed_due_of_period($ctx, $billed['period'])];
        }
    }
    // An installment purchase whose plan is not entered yet: the monthly amounts and due
    // dates are unknown, so nothing is due yet (never the full purchase amount at once).
    $termsMissing = !$rows && ($t['payment_type'] ?? null) === 'installment';
    if (!$rows && !$termsMissing) {
        $rows[] = ['schedule_id' => null, 'label' => 'Purchase', 'charge_date' => $t['transaction_date'], 'amount' => (float) $t['amount'],
                   'due' => borrowed_due_for($t['credit_card_id'], $t['transaction_date'])];
    }
    $responsible = $termsMissing ? (float) $t['amount'] : $money(array_sum(array_column($rows, 'amount')));

    $pay = db_row(
        "SELECT COALESCE(SUM(IF(status = 'approved', amount, 0)), 0) AS confirmed,
                COALESCE(SUM(IF(status IN ('" . implode("', '", BORROWED_REPORTED_STATUSES) . "'), amount, 0)), 0) AS reported
           FROM borrower_payments WHERE transaction_id = :t",
        [':t' => $t['id']]
    );
    $repaid = (float) $pay['confirmed'];
    $penalties = (float) db_value('SELECT COALESCE(SUM(amount), 0) FROM applied_penalties WHERE transaction_id = :t', [':t' => $t['id']]);

    // Confirmed repayments cover penalties first, then the charges oldest first (same order as obligations).
    $penaltyPaid = min($repaid, $penalties);
    $left = $repaid - $penaltyPaid;
    $overdue = 0.0;
    $nextDue = null;
    $unknown = false;
    foreach ($rows as $i => $r) {
        $paid = min($left, $r['amount']);
        $left -= $paid;
        $rows[$i]['paid'] = $money($paid);
        $rows[$i]['remaining'] = $money($r['amount'] - $paid);
        $rows[$i]['overdue'] = $rows[$i]['remaining'] > 0.004 && $r['due']['date'] !== null && $r['due']['date'] < $today;
        if ($rows[$i]['remaining'] > 0.004) {
            if ($rows[$i]['overdue']) {
                $overdue += $rows[$i]['remaining'];
            }
            if ($r['due']['date'] === null) {
                $unknown = true;
            } elseif ($nextDue === null || $r['due']['date'] < $nextDue['date']) {
                $nextDue = $r['due'];
            }
        }
    }
    // Due now: what is overdue plus the installment of the current billing period (the next unpaid one).
    $dueNow = 0.0;
    $current = null;
    foreach ($rows as $i => $r) {
        if ($rows[$i]['remaining'] <= 0.004) {
            continue;
        }
        if ($rows[$i]['overdue']) {
            $dueNow += $rows[$i]['remaining'];
        } elseif ($current === null) {
            $current = $i;
            $dueNow += $rows[$i]['remaining'];
        }
    }
    if ($current === null) {
        foreach ($rows as $i => $r) {
            if ($rows[$i]['remaining'] > 0.004) {
                $current = $i;   // everything left is overdue: the oldest one
                break;
            }
        }
    }
    // Due this month: everything unpaid that falls due by the end of this month (overdue included).
    $monthEnd = date('Y-m-t', strtotime($today));
    $dueMonth = 0.0;
    foreach ($rows as $r) {
        if ($r['remaining'] > 0.004 && $r['due']['date'] !== null && $r['due']['date'] <= $monthEnd) {
            $dueMonth += $r['remaining'];
        }
    }
    $principalPaid = $termsMissing ? $money($repaid - $penaltyPaid) : $money($repaid - $penaltyPaid - max(0, $left));
    $remaining = $money($responsible - $principalPaid);
    $penaltyUnpaid = $money($penalties - $penaltyPaid);
    $outstanding = $money($remaining + $penaltyUnpaid);

    $status = match (true) {
        $outstanding <= 0.004 => 'repaid',
        $termsMissing => 'terms_missing',
        $overdue > 0.004 => 'overdue',
        $repaid > 0.004 => 'partially_repaid',
        default => 'pending',
    };
    $review = null;
    if ($status === 'overdue' && !borrowed_rules_for_card($t['credit_card_id'])) {
        $card = db_row('SELECT issuer FROM credit_cards WHERE id = :c', [':c' => $t['credit_card_id']]);
        $review = 'Overdue, but no active penalty rule is set for ' . ($card['issuer'] ?? 'this bank') . ' - no penalty was charged. Review it, or add the bank\'s rule in Penalty Rules.';
    }

    return [
        'mode' => 'transaction', 'obligation' => null, 'amount' => (float) $t['amount'], 'responsible' => $responsible,
        'repaid' => $money($repaid), 'reported' => $money((float) $pay['reported']), 'remaining' => $remaining,
        'penalties' => $money($penalties), 'penalty_paid' => $money($penaltyPaid), 'penalty_unpaid' => $penaltyUnpaid, 'outstanding' => $outstanding,
        'rows' => $rows, 'next_due' => $nextDue, 'status' => $status, 'overdue_amount' => $money($overdue), 'review' => $review, 'unknown_due' => $unknown || $termsMissing,
        'due_now' => $termsMissing ? 0.0 : $money(min($dueNow + $penaltyUnpaid, $outstanding)),
        'due_month' => $termsMissing ? 0.0 : $money(min($dueMonth + $penaltyUnpaid, $outstanding)), 'current' => $current === null ? null : $rows[$current] + ['n' => $current + 1],
        'installments' => $plan ? count($rows) : 0, 'monthly' => $plan ? (float) $plan['installment_amount'] : null, 'terms_missing' => $termsMissing,
    ];
}

/**
 * Charge the penalties a purchase is due under the card's rules, each rule
 * once per period (penalty_key) - like obligations, but on the transaction.
 * Returns the number of new penalties.
 */
function borrowed_penalty_run(array $t, ?string $today = null): int
{
    $today ??= date('Y-m-d');
    if (empty($t['borrower_id']) || in_array($t['status'], ['cancelled', 'refunded'], true)) {
        return 0;
    }
    $rules = borrowed_rules_for_card($t['credit_card_id']);
    if (!$rules) {
        return 0;   // flagged for review instead (borrowed_ledger)
    }

    $created = db_transaction(static function () use ($t, $rules, $today) {
        db_value('SELECT id FROM transactions WHERE id = :id FOR UPDATE', [':id' => $t['id']]);   // one run at a time per purchase
        $ledger = borrowed_ledger($t, $today);
        if ($ledger['mode'] !== 'transaction' || $ledger['status'] !== 'overdue') {
            return 0;
        }
        $existing = [];
        foreach (db_all('SELECT penalty_rule_id, installment_schedule_id, applied_date FROM applied_penalties WHERE transaction_id = :t', [':t' => $t['id']]) as $p) {
            $existing[penalty_key($t['id'], $p['penalty_rule_id'], $p['installment_schedule_id'], $p['applied_date'])] = true;
        }
        $created = 0;
        foreach ($rules as $rule) {
            foreach ($ledger['rows'] as $row) {
                if (!$row['overdue']) {
                    continue;
                }
                $base = penalty_base($rule['basis'], $row['remaining'], $row['amount'], $row['amount']);
                if ($base <= 0.004 && $rule['calculation_type'] === 'percentage') {
                    continue;
                }
                foreach (penalty_charge_dates($row['due']['date'], $rule, $today) as $n => $date) {
                    $key = penalty_key($t['id'], $rule['id'], $row['schedule_id'], $date);
                    $amount = round(penalty_rule_amount($rule, $base), 2);
                    if (isset($existing[$key]) || $amount <= 0) {
                        continue;
                    }
                    db_insert('applied_penalties', [
                        'id' => new_uuid(), 'obligation_id' => null, 'transaction_id' => $t['id'], 'installment_schedule_id' => $row['schedule_id'],
                        'penalty_rule_id' => $rule['id'], 'applied_date' => $date,
                        'overdue_days' => (int) ((strtotime($date) - strtotime($row['due']['date'])) / 86400),
                        'calculation_base' => number_format($base, 2, '.', ''), 'amount' => number_format($amount, 2, '.', ''),
                        'paid_amount' => '0.00', 'remaining_amount' => number_format($amount, 2, '.', ''),
                        'notes' => 'Late repayment penalty #' . ($n + 1) . ' (' . $rule['name'] . ') on ' . $row['label'] . ' due ' . format_date($row['due']['date']) . '.',
                    ]);
                    $existing[$key] = true;
                    $created++;
                }
            }
        }

        return $created;
    });

    if ($created > 0) {
        borrowed_sync_penalty_payments($t['id']);
        $owner = (string) (db_value('SELECT owner_user_id FROM credit_cards WHERE id = :c', [':c' => $t['credit_card_id']]) ?: SYSTEM_ACTOR);
        log_activity('auto_penalty', 'transaction', $t['id'], 'Charged ' . $created . ' late repayment penalt' . ($created === 1 ? 'y' : 'ies') . ' on borrowed purchase ' . $t['transaction_number'],
            null, null, $owner);
        notify_borrower($t['borrower_id'], 'penalty_charged', 'Late charge on ' . $t['transaction_number'],
            'A late charge was added because the repayment of ' . $t['transaction_number'] . ' is past its due date. Please repay as soon as possible.', 'borrowed_purchase', $t['id']);
    }

    return $created;
}

/** Daily: penalties of every overdue borrowed purchase (all owners). */
function borrowed_penalty_run_all(?string $today = null): int
{
    $created = 0;
    foreach (db_all("SELECT * FROM transactions WHERE borrower_id IS NOT NULL AND status NOT IN ('cancelled', 'refunded')") as $t) {
        try {
            $created += borrowed_penalty_run($t, $today);
        } catch (PDOException $e) {
            error_log('[' . APP_NAME . '] Borrowed penalty run for ' . $t['id'] . ': ' . $e->getMessage());
        }
    }

    return $created;
}

/** Keep paid / remaining of a purchase's penalties in line with its confirmed repayments (penalties first). */
function borrowed_sync_penalty_payments(string $transactionId): void
{
    $repaid = (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM borrower_payments WHERE transaction_id = :t AND status = 'approved'", [':t' => $transactionId]);
    foreach (db_all('SELECT id, amount, paid_amount FROM applied_penalties WHERE transaction_id = :t ORDER BY applied_date, created_at', [':t' => $transactionId]) as $p) {
        $paid = round(min($repaid, (float) $p['amount']), 2);
        $repaid -= $paid;
        if (abs($paid - (float) $p['paid_amount']) >= 0.005) {
            db_update('applied_penalties', ['paid_amount' => number_format($paid, 2, '.', ''),
                'remaining_amount' => number_format((float) $p['amount'] - $paid, 2, '.', '')], $p['id']);
        }
    }
}

/* ------------------------------------------------------------------
 * Repayments of a purchase (borrower_payments.transaction_id)
 * ------------------------------------------------------------------ */

/** Repayment history of a purchase, newest first. */
function borrowed_repayments(string $transactionId): array
{
    return db_all(
        "SELECT p.*, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email) AS reviewer
           FROM borrower_payments p LEFT JOIN users u ON u.id = p.reviewed_by
          WHERE p.transaction_id = :t ORDER BY p.payment_date DESC, p.submitted_at DESC",
        [':t' => $transactionId]
    );
}

/**
 * Save a repayment of a purchase. $confirmed: recorded by the card owner (money
 * received) - counts at once; otherwise reported by the borrower and counted
 * once the owner confirms it. Returns the payment id.
 */
function borrowed_record_repayment(array $t, array $data, string $userId, bool $confirmed): string
{
    $id = new_uuid();
    $number = generate_reference('PAY', 'borrower_payments', 'payment_number');
    $now = date('Y-m-d H:i:s');
    db_transaction(static function () use ($id, $number, $now, $t, $data, $userId, $confirmed) {
        db_insert('borrower_payments', [
            'id' => $id, 'payment_number' => $number, 'borrower_id' => $t['borrower_id'], 'transaction_id' => $t['id'],
            'payment_date' => $data['payment_date'], 'amount' => $data['amount'], 'reference_number' => $data['reference_number'] ?? null,
            'notes' => $data['notes'] ?? null, 'payment_method_id' => $data['payment_method_id'] ?? null, 'submitted_at' => $now,
            'status' => $confirmed ? 'approved' : 'submitted', 'allocation_status' => $confirmed ? 'fully_allocated' : 'unallocated',
        ] + ($confirmed ? ['approved_at' => $now, 'reviewed_at' => $now, 'reviewed_by' => $userId] : []));
        db_insert('payment_verification_logs', [
            'id' => new_uuid(), 'borrower_payment_id' => $id, 'from_status' => null, 'to_status' => $confirmed ? 'approved' : 'submitted',
            'action' => $confirmed ? 'recorded' : 'submitted',
            'reason' => $confirmed ? 'Repayment received, recorded by the card owner' : 'Repayment reported by the borrower', 'performed_by' => $userId,
        ]);
    });
    if ($confirmed) {
        borrowed_sync_penalty_payments($t['id']);
        notify_borrower($t['borrower_id'], 'payment_approved', 'Repayment received: ' . format_money($data['amount']),
            'The card owner recorded your repayment of ' . format_money($data['amount']) . ' for ' . $t['transaction_number'] . '.', 'borrowed_purchase', $t['id']);
    } else {
        notify_lender($t['borrower_id'], 'payment_submitted', 'Repayment to confirm: ' . $number,
            'A repayment of ' . format_money($data['amount']) . ' (paid ' . format_date($data['payment_date']) . ') was reported for ' . $t['transaction_number'] . '. Please confirm it.',
            'transaction', $t['id']);
    }
    log_activity($confirmed ? 'record_repayment' : 'report_repayment', 'transaction', $t['id'],
        ($confirmed ? 'Recorded repayment ' : 'Reported repayment ') . $number . ' of ' . format_money($data['amount']) . ' for ' . $t['transaction_number']);

    return $id;
}

/** After the owner confirmed, rejected or cancelled a repayment of a purchase. */
function borrowed_after_decision(array $payment, string $decision): void
{
    if (empty($payment['transaction_id'])) {
        return;
    }
    if ($decision === 'approved') {
        // Applied to the purchase itself: never allocated to an obligation.
        db_update('borrower_payments', ['allocation_status' => 'fully_allocated'], $payment['id']);
    }
    borrowed_sync_penalty_payments($payment['transaction_id']);
}

/* ------------------------------------------------------------------
 * Lists: the borrower's purchases, an owner's borrowed purchases
 * ------------------------------------------------------------------ */

/** SELECT of borrowed purchases with what the pages show (no other card data). */
function borrowed_select_sql(): string
{
    return "SELECT t.id, t.transaction_number, t.transaction_date, t.amount, t.status, t.payment_type, t.notes, t.borrower_id, t.credit_card_id,
                   m.merchant_name, c.issuer, c.card_nickname, c.last_four,
                   b.first_name, b.last_name, b.borrower_number,
                   COALESCE(NULLIF(TRIM(CONCAT_WS(' ', ou.first_name, ou.last_name)), ''), ou.email) AS lender_name
              FROM transactions t
              JOIN credit_cards c ON c.id = t.credit_card_id
              JOIN borrowers b ON b.id = t.borrower_id
              LEFT JOIN users ou ON ou.id = c.owner_user_id
              LEFT JOIN merchants m ON m.id = t.merchant_id";
}

/** Attach the ledger to each row (and charge any penalty that is due). */
function borrowed_with_ledgers(array $rows): array
{
    foreach ($rows as &$row) {
        borrowed_penalty_run($row);
        $row['ledger'] = borrowed_ledger($row);
    }
    unset($row);

    return $rows;
}

/**
 * The signed-in user's borrowed purchases (only purchases assigned to them -
 * never the card owner's other records). With $id, that one purchase.
 */
function borrowed_my_purchases(?string $id = null): array
{
    return borrowed_with_ledgers(db_all(
        borrowed_select_sql() . " WHERE t.borrower_id IN " . borrowed_my_borrowers_sql() . " AND t.status NOT IN ('cancelled', 'refunded')"
            . ($id !== null ? ' AND t.id = :id' : '') . ' ORDER BY t.transaction_date DESC, t.created_at DESC',
        $id !== null ? [':id' => $id] : []
    ));
}

/** Borrowed purchases on the signed-in owner's cards (optionally of one borrower / one transaction). */
function borrowed_owner_purchases(?string $borrowerId = null, ?string $transactionId = null): array
{
    $where = ['t.borrower_id IS NOT NULL', "t.status NOT IN ('cancelled', 'refunded')", owner_scope('transactions', 't')];
    $params = [];
    if ($borrowerId !== null) {
        $where[] = 't.borrower_id = :b';
        $params[':b'] = $borrowerId;
    }
    if ($transactionId !== null) {
        $where[] = 't.id = :id';
        $params[':id'] = $transactionId;
    }

    return borrowed_with_ledgers(db_all(borrowed_select_sql() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY t.transaction_date DESC', $params));
}

/** Totals of a list of purchases with ledgers. */
function borrowed_totals(array $rows): array
{
    $sum = ['count' => count($rows), 'responsible' => 0.0, 'repaid' => 0.0, 'reported' => 0.0, 'remaining' => 0.0, 'penalties' => 0.0,
            'outstanding' => 0.0, 'overdue' => 0, 'overdue_amount' => 0.0, 'open' => 0, 'upcoming' => 0, 'next_due' => null, 'review' => 0];
    foreach ($rows as $r) {
        $l = $r['ledger'];
        $sum['responsible'] += $l['responsible'];
        $sum['repaid'] += $l['repaid'];
        $sum['reported'] += $l['reported'];
        $sum['remaining'] += $l['remaining'];
        $sum['penalties'] += $l['penalty_unpaid'];
        $sum['outstanding'] += $l['outstanding'];
        $sum['review'] += $l['review'] || $l['terms_missing'] ? 1 : 0;
        if ($l['status'] === 'repaid') {
            continue;
        }
        $sum['open']++;
        if ($l['status'] === 'overdue') {
            $sum['overdue']++;
            $sum['overdue_amount'] += $l['overdue_amount'];
        }
        if ($l['next_due'] && $l['next_due']['date'] >= date('Y-m-d')) {
            $sum['upcoming']++;
            $sum['next_due'] = $sum['next_due'] === null || $l['next_due']['date'] < $sum['next_due'] ? $l['next_due']['date'] : $sum['next_due'];
        }
    }

    return $sum;
}

function borrowed_status_badge(string $status): string
{
    return status_badge(match ($status) {
        'repaid' => 'fully repaid',
        'partially_repaid' => 'partially repaid',
        'pending' => 'pending repayment',
        'terms_missing' => 'installment terms not set',
        default => $status,
    }, ['fully repaid' => 'success', 'overdue' => 'danger', 'partially repaid' => 'warning', 'pending repayment' => 'info', 'installment terms not set' => 'warning']);
}

/** Repay-by text of a ledger: date and where it comes from, or why there is none. */
function borrowed_due_text(array $ledger, bool $short = false): string
{
    if ($ledger['status'] === 'repaid') {
        return 'Fully repaid';
    }
    $due = $ledger['next_due'];
    if ($ledger['terms_missing'] ?? false) {
        return $short ? 'Not available yet' : 'Not available yet - the installment plan (months and monthly amount) is not entered';
    }
    if ($due === null) {
        return $short ? 'Not available yet' : 'Not available yet - the card\'s statement / due date is not set';
    }
    if ($short) {
        return format_date($due['date']) . ($due['statement'] ? ' · ' . $due['statement'] : '');
    }

    return format_date($due['date']) . match ($due['source']) {
        'statement' => ' (' . $due['statement'] . ' statement due date)',
        'card rule' => ' (' . $due['statement'] . ' statement, from the card\'s due date rule)',
        default => '',
    };
}

/** Does the signed-in user have borrowed purchases (shows the menu item)? */
function borrowed_has_items(): bool
{
    static $has = null;

    return $has ??= is_logged_in() && (int) db_value(
        "SELECT COUNT(*) FROM transactions t WHERE t.borrower_id IN " . borrowed_my_borrowers_sql() . " AND t.status NOT IN ('cancelled', 'refunded')"
    ) > 0;
}

/** Message after saving a transaction with a borrower. */
function borrowed_after_save(string $transactionId): ?string
{
    $t = db_row('SELECT t.borrower_id, b.first_name, b.last_name FROM transactions t JOIN borrowers b ON b.id = t.borrower_id WHERE t.id = :id', [':id' => $transactionId]);
    if (!$t) {
        return null;
    }
    $linked = (int) db_value('SELECT COUNT(*) FROM borrower_user_accounts WHERE borrower_id = :b AND deactivated_at IS NULL', [':b' => $t['borrower_id']]) > 0;

    return $linked
        ? 'It now shows in ' . borrower_name($t) . '\'s account with its repay-by date.'
        : 'It shows on ' . borrower_name($t) . '\'s borrower page. Link their sign-in account there so they can see it in their own account.';
}

/** Borrower records (of other owners) linked to the signed-in user (registered or portal account). */
function borrowed_my_borrowers_sql(): string
{
    $me = db()->quote((string) ($_SESSION['user_id'] ?? ''));

    return "(SELECT mba.borrower_id FROM borrower_user_accounts mba JOIN borrowers mb ON mb.id = mba.borrower_id
              WHERE mba.user_id = $me AND mba.deactivated_at IS NULL AND (mb.owner_user_id IS NULL OR mb.owner_user_id <> $me))";
}


/**
 * Who may open the borrower pages: registered users (their own layout) and
 * borrower portal accounts (portal layout). Returns [user, layout].
 */
function borrowed_require_user(): array
{
    $user = require_role(array_merge(ADMIN_ROLES, ['borrower']));
    if (user_is_admin($user)) {
        return [require_admin(), 'admin'];
    }
    penalty_run_if_due();

    return [$user, 'portal'];
}

/**
 * The figures of one borrowed purchase: original amount, repaid, remaining,
 * penalty and total outstanding, repay-by date and status (both sides).
 */
/** "Installment 3 of 12" for the current installment, or why there is none. */
function borrowed_installment_text(array $l): ?string
{
    if ($l['terms_missing']) {
        return 'Installment terms not set yet';
    }
    if ($l['installments'] < 1 || $l['current'] === null) {
        return null;
    }

    return 'Installment ' . $l['current']['n'] . ' of ' . $l['installments'];
}

/** List cell: the original amount and, for an installment purchase, n × monthly amount. */
function borrowed_amount_cell(array $row, array $l): string
{
    $html = e(format_money($row['amount']));
    if ($l['installments'] > 0) {
        $html .= '<br><span class="text-muted">' . e($l['installments'] . ' × ' . format_money($l['monthly'])) . '</span>';
    } elseif ($l['terms_missing']) {
        $html .= '<br><span class="text-muted">installment, terms not set</span>';
    }

    return $html;
}

/** List cell: what is due now (overdue + current installment) and which installment it is. */
function borrowed_due_now_cell(array $l): string
{
    if ($l['status'] === 'repaid') {
        return e(format_money(0));
    }
    $text = borrowed_installment_text($l);

    return '<strong>' . e(format_money($l['due_now'])) . '</strong>' . ($text ? '<br><span class="text-muted">' . e($text) . '</span>' : '');
}

function borrowed_render_ledger(array $row, array $l, bool $owner = false): string
{
    $details = [
        'Original transaction amount' => e(format_money($row['amount'])) . ($l['installments'] > 0 || $l['terms_missing'] ? ' <span class="text-muted">(charged to the card in installments)</span>' : ''),
    ];
    if ($l['installments'] > 0) {
        $plan = db_row("SELECT p.* FROM installment_plans p WHERE p.transaction_id = :t AND p.status <> 'cancelled'", [':t' => $row['id']]);
        $details['Installment plan'] = e($l['installments'] . ' monthly installments of ' . format_money($l['monthly'])) . ($plan ? ' <span class="text-muted">(' . e(installment_terms_text($plan)) . ')</span>' : '');
        if ($plan && ((float) $plan['interest_amount'] > 0 || (float) $plan['fees_amount'] > 0)) {
            $details['Interest and fees'] = e(format_money($plan['interest_amount'])) . ' interest · ' . e(format_money($plan['fees_amount'])) . ' fees';
        }
        if ($l['current'] !== null && $l['status'] !== 'repaid') {
            $details['Current installment'] = e(borrowed_installment_text($l)) . ': ' . e(format_money($l['current']['amount']))
                . ($l['current']['due']['date'] ? ' · repay by ' . e(format_date($l['current']['due']['date'])) : '');
        }
    }
    if ($l['mode'] === 'obligation') {
        $details['Tracked as'] = 'obligation ' . e($l['obligation']['obligation_number']) . ' <span class="text-muted">(older workflow: repayments are posted to it)</span>';
    }
    if (abs($l['responsible'] - (float) $row['amount']) > 0.004) {
        $details['To repay (with installment charges)'] = e(format_money($l['responsible']));
    }
    $details += [
        'Repaid (confirmed)'       => e(format_money($l['repaid'])),
        'Reported, not confirmed'  => $l['reported'] > 0 ? e(format_money($l['reported'])) . ' <span class="text-muted">(counts once the card owner confirms it)</span>' : e(format_money(0)),
        'Remaining'                => e(format_money($l['remaining'])),
        'Late penalty'             => $l['penalties'] > 0 ? e(format_money($l['penalty_unpaid'])) . ' unpaid <span class="text-muted">of ' . e(format_money($l['penalties'])) . ' charged (card owner\'s penalty rules; not a card charge)</span>' : e(format_money(0)),
        'Total outstanding'        => '<strong>' . e(format_money($l['outstanding'])) . '</strong>' . ($l['installments'] > 0 ? ' <span class="text-muted">(all remaining installments)</span>' : ''),
        'Due this period'          => $l['terms_missing'] ? '<span class="text-muted">Not available yet</span>' : '<strong>' . e(format_money($l['status'] === 'repaid' ? 0 : $l['due_now'])) . '</strong>'
                                      . ($l['overdue_amount'] > 0 ? ' <span class="text-danger">(includes ' . e(format_money($l['overdue_amount'])) . ' overdue)</span>' : ''),
        'Repay by'                 => e(borrowed_due_text($l)),
        'Status'                   => borrowed_status_badge($l['status']),
    ];
    $html = render_details($details);
    if ($l['terms_missing']) {
        $html .= '<div class="alert alert--warning">' . icon('alert') . '<span>This is an installment purchase, but its installment plan is not entered yet, so the monthly amount and due dates are not known. '
            . ($owner ? 'Nothing is shown as due until you <a data-modal-form href="' . e(url('pages/installment_plans/create.php', ['transaction_id' => $row['id']])) . '">add its installment plan</a>.'
                      : 'Nothing is due until the card owner enters the plan.') . '</span></div>';
    }
    if ($l['review']) {
        $html .= '<div class="alert alert--warning">' . icon('alert') . '<span>' . e($l['review']) . '</span></div>';
    }
    if (count($l['rows']) > 1) {
        $html .= '<div class="table-wrap"><table class="table table--cards table--compact"><thead><tr><th scope="col">Charge</th><th scope="col" class="num">Amount</th>'
            . '<th scope="col" class="num">Repaid</th><th scope="col" class="num">Remaining</th><th scope="col">Repay by</th><th scope="col">Status</th></tr></thead><tbody>';
        foreach ($l['rows'] as $i => $r) {
            $state = match (true) {
                $r['remaining'] <= 0.004 => 'paid',
                $r['overdue'] => 'overdue',
                $r['paid'] > 0.004 => 'partially paid',
                default => 'pending',
            };
            $html .= '<tr' . ($l['current'] !== null && $l['current']['n'] === $i + 1 && $state !== 'paid' ? ' class="is-current"' : '') . '><td data-label="Charge">' . e($r['label']) . ($l['installments'] > 0 ? ' of ' . e($l['installments']) : '') . ' <span class="text-muted">' . e($r['due']['statement'] ?? '') . '</span></td>'
                . '<td data-label="Amount" class="num">' . e(format_money($r['amount'])) . '</td>'
                . '<td data-label="Repaid" class="num">' . e(format_money($r['paid'])) . '</td>'
                . '<td data-label="Remaining" class="num">' . e(format_money($r['remaining'])) . '</td>'
                . '<td data-label="Repay by">' . ($r['due']['date'] ? e(format_date($r['due']['date'])) : '<span class="text-muted">not available yet</span>') . '</td>'
                . '<td data-label="Status">' . status_badge($state, ['paid' => 'success', 'overdue' => 'danger', 'partially paid' => 'warning', 'pending' => 'info']) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
    }

    return $html;
}

/** Repayment history table (amount, date, reported / confirmed / rejected). $actions(row) adds buttons. */
function borrowed_render_repayments(array $repayments, ?callable $actions = null): string
{
    if (!$repayments) {
        return '<p class="empty-inline">No repayments recorded yet.</p>';
    }
    $label = static fn (string $s) => match ($s) {
        'approved' => 'confirmed', 'submitted', 'under_review' => 'reported', default => $s,
    };
    $html = '<div class="table-wrap"><table class="table table--cards table--compact"><thead><tr><th scope="col">Date</th><th scope="col" class="num">Amount</th>'
        . '<th scope="col">Status</th>' . ($actions ? '<th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>' : '') . '</tr></thead><tbody>';
    foreach ($repayments as $r) {
        $html .= '<tr><td data-label="Date">' . e(format_date($r['payment_date'])) . ' <span class="text-muted">' . e($r['payment_number'])
            . ($r['reference_number'] ? ' · ref ' . e($r['reference_number']) : '') . '</span>'
            . ($r['notes'] ? '<br><span class="text-muted">' . e(excerpt($r['notes'], 80)) . '</span>' : '')
            . ($r['status'] === 'rejected' && $r['rejection_reason'] ? '<br><span class="text-danger">' . e($r['rejection_reason']) . '</span>' : '') . '</td>'
            . '<td data-label="Amount" class="num">' . e(format_money($r['amount'])) . '</td>'
            . '<td data-label="Status">' . status_badge($label($r['status']), ['confirmed' => 'success', 'reported' => 'warning', 'rejected' => 'danger', 'cancelled' => 'muted'])
            . ($r['status'] === 'approved' && $r['approved_at'] ? '<br><span class="text-muted">' . e(format_date($r['approved_at'])) . '</span>' : '') . '</td>'
            . ($actions ? '<td class="actions no-print">' . $actions($r) . '</td>' : '') . '</tr>';
    }

    return $html . '</tbody></table></div>';
}

/** Days before the repay-by date when the borrower is reminded. */
const BORROWED_REMIND_DAYS = 3;

/**
 * Daily: remind borrowers of the actual statement payment due date of their
 * purchases - once a few days before it, and once when it is missed. Each
 * reminder is sent once per purchase and due date (the notification itself is
 * the record: type + purchase + date in the title).
 */
function borrowed_send_reminders(?string $today = null): int
{
    $today ??= date('Y-m-d');
    $sent = 0;
    foreach (db_all(borrowed_select_sql() . " WHERE t.borrower_id IS NOT NULL AND t.status NOT IN ('cancelled', 'refunded')
                      AND EXISTS (SELECT 1 FROM borrower_user_accounts a WHERE a.borrower_id = t.borrower_id AND a.deactivated_at IS NULL)") as $t) {
        $l = borrowed_ledger($t, $today);
        if ($l['mode'] !== 'transaction' || $l['status'] === 'repaid') {
            continue;
        }
        foreach ($l['rows'] as $row) {
            $due = $row['due']['date'];
            if ($due === null || $row['remaining'] <= 0.004) {
                continue;
            }
            $kind = match (true) {
                $due < $today => 'repayment_overdue',
                $due <= date('Y-m-d', strtotime($today . ' +' . BORROWED_REMIND_DAYS . ' days')) => 'repayment_reminder',
                default => null,
            };
            if ($kind === null) {
                continue;
            }
            $what = ($t['merchant_name'] ?: ($t['notes'] ? excerpt($t['notes'], 40) : $t['transaction_number'])) . (count($l['rows']) > 1 ? ' (' . $row['label'] . ')' : '');
            $title = ($kind === 'repayment_overdue' ? 'Overdue since ' : 'Repayment due ') . format_date($due) . ': ' . $what;
            $exists = (int) db_value(
                'SELECT COUNT(*) FROM notifications n JOIN borrower_user_accounts a ON a.user_id = n.user_id AND a.borrower_id = :b
                  WHERE n.notification_type = :k AND n.entity_id = :t AND n.title = :title',
                [':b' => $t['borrower_id'], ':k' => $kind, ':t' => $t['id'], ':title' => mb_substr($title, 0, 255)]
            );
            if ($exists) {
                continue;
            }
            notify_borrower($t['borrower_id'], $kind, $title,
                ($kind === 'repayment_overdue'
                    ? 'The repayment of ' . $what . ' was due on ' . format_date($due) . ' (' . $row['due']['statement'] . ' statement of ' . $t['lender_name'] . '\'s card). '
                    : 'Please repay ' . $what . ' to ' . $t['lender_name'] . ' by ' . format_date($due) . ', the card\'s ' . $row['due']['statement'] . ' statement payment due date. ')
                . format_money($row['remaining']) . ' is left on it.',
                'borrowed_purchase', $t['id']);
            $sent++;
        }
    }

    return $sent;
}

/**
 * Tell the borrowers of a card purchase what the card owner changed: the
 * person it was assigned to by mistake that it is no longer theirs, a newly
 * assigned person that it is now theirs to repay, otherwise field by field
 * (amount, date, type, status) plus $note (e.g. new installment terms).
 */
function borrowed_notify_update(array $before, string $transactionId, string $note = ''): void
{
    $after = db_row('SELECT * FROM transactions WHERE id = :id', [':id' => $transactionId]);
    if (!$after) {
        return;
    }
    $ref = $after['transaction_number'];
    $old = $before['borrower_id'] ?? null;
    $new = $after['borrower_id'];
    if ($old && $old !== $new) {
        notify_borrower($old, 'item_removed', 'Removed: ' . $ref,
            'Card purchase ' . $ref . ' (' . format_money($before['amount']) . ') is no longer recorded as yours to repay.', null, null);
    }
    if ($new && $old !== $new) {
        notify_borrower($new, 'purchase_assigned', 'New item: ' . $ref,
            'Card purchase ' . $ref . ' of ' . format_money($after['amount']) . ' (' . format_date($after['transaction_date']) . ') was recorded as yours to repay.'
            . ($note !== '' ? ' ' . $note : ''), 'borrowed_purchase', $transactionId);
    } elseif ($new) {
        notify_borrower_changes($new, $ref, $before, $after, PURCHASE_NOTICE_FIELDS, 'borrowed_purchase', $transactionId, $note);
    }
}

/** What a borrower sees of the installment plan of their purchase. */
const PLAN_NOTICE_FIELDS = [
    'number_of_installments' => ['Installments', 'number'],
    'installment_amount'     => ['Monthly amount', 'money'],
    'first_due_date'         => ['First due date', 'date'],
    'end_date'               => ['Last due date', 'date'],
    'frequency'              => ['Frequency', 'status'],
];

/** Tell the borrower of a purchase what changed in its installment plan. */
function borrowed_notify_plan(array $before, string $planId, string $note = ''): void
{
    $after = db_row(
        'SELECT p.*, t.borrower_id, t.transaction_number, t.id AS txn_id FROM installment_plans p JOIN transactions t ON t.id = p.transaction_id WHERE p.id = :p',
        [':p' => $planId]
    );
    if ($after && $after['borrower_id']) {
        notify_borrower_changes($after['borrower_id'], $after['transaction_number'] . ' installments', $before, $after, PLAN_NOTICE_FIELDS, 'borrowed_purchase', $after['txn_id'], $note);
    }
}
