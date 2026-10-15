<?php
/**
 * Your rate to the borrower (earnings) and saving goals.
 *
 * A card purchase lent to a borrower may carry the card owner's own rate,
 * transactions.borrower_rate (% per month on the purchase amount, add-on):
 * the bank's installments stay as they are (what the card charges, on the
 * statements and the card's used credit), and the borrower repays each
 * installment plus an equal share of
 *     purchase amount x rate % x number of months
 * (a straight purchase: one month). That share is the owner's interest.
 *
 * Earned only when the borrower pays: each confirmed repayment on an
 * installment covers the bank's part first, then the owner's interest, so
 * what reaches the bank schedule is unchanged and interest is counted only
 * from money actually received.
 *
 * Saving goals (savings_goals): an amount to set aside (e.g. a card's annual
 * fee) that the interest earned on a card - or on all cards - counts toward
 * from the goal's start date. A goal only tracks; it never moves money.
 *
 * Schema (additive, db/upgrade_earnings.sql):
 *   transactions.borrower_rate DECIMAL(8,4) NULL
 *   savings_goals (new table)
 */

defined('APP_ROOT') or exit('No direct access.');

const EARNINGS_UPGRADE_MARKER = 'storage/upgrade_earnings.done';
const SAVINGS_GOAL_STATUSES = ['active' => 'Saving', 'used' => 'Used', 'cancelled' => 'Cancelled'];

function earnings_schema_upgrade(): void
{
    if (marker_exists(EARNINGS_UPGRADE_MARKER)) {
        return;
    }
    db()->exec('ALTER TABLE transactions ADD COLUMN IF NOT EXISTS borrower_rate DECIMAL(8,4) NULL AFTER borrower_id');
    db()->exec(
        "CREATE TABLE IF NOT EXISTS savings_goals (
            id CHAR(36) NOT NULL PRIMARY KEY,
            owner_user_id CHAR(36) NOT NULL,
            credit_card_id CHAR(36) NULL,
            name VARCHAR(150) NOT NULL,
            target_amount DECIMAL(15,2) NOT NULL,
            start_date DATE NOT NULL,
            target_date DATE NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            used_at DATETIME NULL,
            notes TEXT NULL,
            created_by CHAR(36) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_savings_goals_owner (owner_user_id),
            KEY idx_savings_goals_card (credit_card_id),
            CONSTRAINT chk_savings_goal_amount CHECK (target_amount > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    marker_write(EARNINGS_UPGRADE_MARKER);
}

/** Is the borrower_rate column there yet (before the upgrade ran)? */
function earnings_ready(): bool
{
    static $ready = null;
    if ($ready === null || $ready === false) {
        $ready = borrowed_has_column('transactions', 'borrower_rate');
    }

    return $ready;
}

/** The purchase's rate to the borrower (% per month), or 0. */
function borrower_rate_of(array $t): float
{
    if (array_key_exists('borrower_rate', $t)) {
        return (float) $t['borrower_rate'];
    }
    if (!earnings_ready() || empty($t['id'])) {
        return 0.0;
    }

    return (float) db_value('SELECT borrower_rate FROM transactions WHERE id = :t', [':t' => $t['id']]);
}

/** "1.25% per month" */
function borrower_rate_text(float $rate): string
{
    return rtrim(rtrim(number_format($rate, 4), '0'), '.') . '% per month';
}

/**
 * Add the owner's interest to the ledger's charge rows: purchase amount x
 * rate % x months (rows), split evenly in centavos. Each row keeps 'bank'
 * (the bank's amount) and gets 'markup'; 'amount' becomes their sum.
 */
function borrower_rate_rows(array $rows, float $principal, float $rate): array
{
    $n = count($rows);
    foreach ($rows as &$r) {
        $r['bank'] = (float) $r['amount'];
        $r['markup'] = 0.0;
    }
    unset($r);
    if ($rate <= 0 || $n === 0) {
        return $rows;
    }
    $shares = installment_split((int) round($principal * 100 * $rate / 100 * $n), $n);
    foreach ($rows as $i => &$r) {
        $r['markup'] = $shares[$i] / 100;
        $r['amount'] = round($r['bank'] + $r['markup'], 2);
    }
    unset($r);

    return $rows;
}

/** Interest received on a row: what was paid beyond the bank's part (bank first). */
function borrower_rate_earned_row(array $r, float $paid): float
{
    return round(max(0, min((float) ($r['markup'] ?? 0), $paid - (float) ($r['bank'] ?? $r['amount']))), 2);
}

/* ------------------------------------------------------------------
 * Earnings of the signed-in owner
 * ------------------------------------------------------------------ */

/**
 * Lent purchases with a rate on the owner's cards (optionally one card),
 * each with: interest (expected), earned (received), to_receive.
 */
function earnings_purchases(?string $cardId = null): array
{
    if (!earnings_ready()) {
        return [];
    }
    $where = ['t.borrower_id IS NOT NULL', 't.borrower_rate > 0', "t.status NOT IN ('cancelled', 'refunded')", owner_scope('transactions', 't')];
    $params = [];
    if ($cardId) {
        $where[] = 't.credit_card_id = :c';
        $params[':c'] = $cardId;
    }
    $rows = db_all(borrowed_select_sql() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY t.transaction_date DESC', $params);
    $out = [];
    foreach ($rows as $row) {
        $l = borrowed_ledger($row);
        if ($l['mode'] !== 'transaction') {
            continue;   // older purchases tracked by an obligation keep that obligation's agreed interest
        }
        $out[] = $row + ['ledger' => $l, 'interest' => $l['markup'], 'earned' => $l['markup_earned'], 'to_receive' => round($l['markup'] - $l['markup_earned'], 2)];
    }

    return $out;
}

/**
 * Interest received by month (Y-m => amount): the repayments are replayed in
 * date order, so each month gets the interest its repayments covered.
 */
function earnings_by_month(array $purchases): array
{
    $months = [];
    foreach ($purchases as $p) {
        $dates = array_column(db_all(
            "SELECT DISTINCT payment_date FROM borrower_payments WHERE transaction_id = :t AND status = 'approved' ORDER BY payment_date",
            [':t' => $p['id']]
        ), 'payment_date');
        $before = 0.0;
        foreach ($dates as $date) {
            $upTo = borrowed_ledger($p, null, $date)['markup_earned'];
            if ($upTo - $before >= 0.005) {
                $key = substr($date, 0, 7);
                $months[$key] = round(($months[$key] ?? 0) + $upTo - $before, 2);
            }
            $before = $upTo;
        }
    }
    krsort($months);

    return $months;
}

/** Interest received on the owner's purchases (one card or all) from $from to today. */
function earnings_since(array $purchases, ?string $cardId, string $from): float
{
    $sum = 0.0;
    foreach ($purchases as $p) {
        if ($cardId !== null && $p['credit_card_id'] !== $cardId) {
            continue;
        }
        $before = borrowed_ledger($p, null, date('Y-m-d', strtotime($from . ' -1 day')))['markup_earned'];
        $sum += $p['earned'] - $before;
    }

    return round($sum, 2);
}

/* ------------------------------------------------------------------
 * Saving goals
 * ------------------------------------------------------------------ */

function savings_goal_fields(): array
{
    return [
        'name'           => ['label' => 'Goal', 'type' => 'text', 'max' => 150, 'required' => true, 'placeholder' => 'e.g. BDO annual fee'],
        'credit_card_id' => ['label' => 'Interest from', 'type' => 'select', 'options' => credit_card_options(), 'empty_label' => '— All my cards —',
                             'help' => 'The interest your borrowers pay on purchases with this card counts toward the goal.'],
        'target_amount'  => ['label' => 'Amount to save', 'type' => 'decimal', 'required' => true, 'min' => 0.01],
        'start_date'     => ['label' => 'Count from', 'type' => 'date', 'required' => true, 'initial' => date('Y-m-d'),
                             'help' => 'Interest received from this date counts.'],
        'target_date'    => ['label' => 'Needed by', 'type' => 'date', 'help' => 'e.g. the month the annual fee is billed.'],
        'notes'          => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 2, 'full' => true],
    ];
}

/** The owner's goals with progress (saved, percent). */
function savings_goals_with_progress(array $purchases): array
{
    if (!earnings_ready()) {
        return [];
    }
    $goals = db_all(
        "SELECT g.*, c.card_nickname, c.last_four FROM savings_goals g LEFT JOIN credit_cards c ON c.id = g.credit_card_id
          WHERE g.owner_user_id = :o ORDER BY FIELD(g.status, 'active', 'used', 'cancelled'), g.target_date IS NULL, g.target_date, g.created_at",
        [':o' => owner_id()]
    );
    foreach ($goals as &$g) {
        $g['saved'] = earnings_since($purchases, $g['credit_card_id'], $g['start_date']);
        $g['percent'] = min(100, round($g['saved'] / max(0.01, (float) $g['target_amount']) * 100, 1));
    }
    unset($g);

    return $goals;
}

function savings_goal_find(string $id): ?array
{
    if (!is_valid_uuid($id) || !earnings_ready()) {
        return null;
    }

    return db_row('SELECT * FROM savings_goals WHERE id = :id AND owner_user_id = :o', [':id' => $id, ':o' => owner_id()]);
}
