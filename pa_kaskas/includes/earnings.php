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
const EARNINGS_COLLATION_MARKER = 'storage/upgrade_earnings_v2.done';
const EARNINGS_FEE_MARKER = 'storage/upgrade_earnings_v3.done';
const SAVINGS_GOAL_STATUSES = ['active' => 'Saving', 'used' => 'Used', 'cancelled' => 'Cancelled'];

function earnings_schema_upgrade(): void
{
    if (marker_exists(EARNINGS_UPGRADE_MARKER)) {
        earnings_collation_fix();
        earnings_fee_upgrade();
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=" . earnings_collation()
    );
    marker_write(EARNINGS_UPGRADE_MARKER);
    marker_write(EARNINGS_COLLATION_MARKER);
    earnings_fee_upgrade();
}

/**
 * Your processing fee to the borrower (additive): fixed amount or % of the
 * purchase, billed with the first installment or spread over all of them.
 */
function earnings_fee_upgrade(): void
{
    if (marker_exists(EARNINGS_FEE_MARKER)) {
        return;
    }
    db()->exec('ALTER TABLE transactions ADD COLUMN IF NOT EXISTS borrower_fee_type VARCHAR(20) NULL AFTER borrower_rate');
    db()->exec('ALTER TABLE transactions ADD COLUMN IF NOT EXISTS borrower_fee_value DECIMAL(15,4) NULL AFTER borrower_fee_type');
    db()->exec('ALTER TABLE transactions ADD COLUMN IF NOT EXISTS borrower_fee_billing VARCHAR(20) NULL AFTER borrower_fee_value');
    marker_write(EARNINGS_FEE_MARKER);
}

/**
 * The collation of the existing tables (credit_cards.id), so savings_goals
 * joins them without "Illegal mix of collations" (databases differ:
 * utf8mb4_general_ci on XAMPP, utf8mb4_unicode_ci elsewhere).
 */
function earnings_collation(): string
{
    $c = (string) db_value("SELECT COLLATION_NAME FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_cards' AND COLUMN_NAME = 'id'");

    return preg_match('/^utf8mb4_[a-z0-9_]+$/', $c) ? $c : 'utf8mb4_general_ci';
}

/** savings_goals created by the first version with a fixed collation: match the other tables. */
function earnings_collation_fix(): void
{
    if (marker_exists(EARNINGS_COLLATION_MARKER)) {
        return;
    }
    $want = earnings_collation();
    $have = (string) db_value("SELECT COLLATION_NAME FROM information_schema.COLUMNS
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'savings_goals' AND COLUMN_NAME = 'id'");
    if ($have !== '' && $have !== $want) {
        db()->exec('ALTER TABLE savings_goals CONVERT TO CHARACTER SET utf8mb4 COLLATE ' . $want);
    }
    marker_write(EARNINGS_COLLATION_MARKER);
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

/** Are the processing fee columns there yet? */
function earnings_fee_ready(): bool
{
    static $ready = null;
    if (!$ready) {
        $ready = borrowed_has_column('transactions', 'borrower_fee_value');
    }

    return $ready;
}

/**
 * Your terms to the borrower: ['rate' => % per month, 'fee_type' => fixed|percent|null,
 * 'fee_value' => float, 'fee_billing' => first|spread].
 */
function borrower_terms_of(array $t): array
{
    $keys = ['borrower_fee_type', 'borrower_fee_value', 'borrower_fee_billing'];
    if (!array_key_exists('borrower_fee_value', $t) && earnings_fee_ready() && !empty($t['id'])) {
        $t += db_row('SELECT borrower_fee_type, borrower_fee_value, borrower_fee_billing FROM transactions WHERE id = :t', [':t' => $t['id']]) ?: [];
    }
    $type = in_array($t['borrower_fee_type'] ?? null, ['fixed', 'percent'], true) ? $t['borrower_fee_type'] : null;

    return [
        'rate' => borrower_rate_of($t), 'fee_type' => $type, 'fee_value' => $type ? (float) ($t['borrower_fee_value'] ?? 0) : 0.0,
        'fee_billing' => ($t['borrower_fee_billing'] ?? null) === 'spread' ? 'spread' : 'first',
    ];
}

/** Your processing fee in pesos for a purchase amount. */
function borrower_fee_amount(array $terms, float $principal): float
{
    return match ($terms['fee_type']) {
        'fixed'   => round($terms['fee_value'], 2),
        'percent' => round($principal * $terms['fee_value'] / 100, 2),
        default   => 0.0,
    };
}

/** "₱500.00 with the first installment" / "2% of the purchase, spread" */
function borrower_fee_text(array $terms, float $principal): string
{
    if (!$terms['fee_type']) {
        return 'none';
    }

    return format_money(borrower_fee_amount($terms, $principal))
        . ($terms['fee_type'] === 'percent' ? ' (' . rtrim(rtrim(number_format($terms['fee_value'], 4), '0'), '.') . '% of the purchase)' : '')
        . ($terms['fee_billing'] === 'spread' ? ', spread over the installments' : ', with the first installment');
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
 * Add your interest and processing fee to the ledger's charge rows. Interest:
 * purchase amount x rate % x months (rows), split evenly in centavos. Fee:
 * with the first row, or split evenly. Each row keeps 'bank' (the bank's
 * amount) and gets 'fee', 'interest' and 'markup' (their sum); 'amount'
 * becomes bank + markup.
 */
function borrower_rate_rows(array $rows, float $principal, array $terms): array
{
    $n = count($rows);
    foreach ($rows as &$r) {
        $r['bank'] = (float) $r['amount'];
        $r['fee'] = $r['interest'] = $r['markup'] = 0.0;
    }
    unset($r);
    if ($n === 0) {
        return $rows;
    }
    $interest = $terms['rate'] > 0 ? installment_split((int) round($principal * 100 * $terms['rate'] / 100 * $n), $n) : array_fill(0, $n, 0);
    $feeCents = (int) round(borrower_fee_amount($terms, $principal) * 100);
    $fees = $terms['fee_billing'] === 'spread' ? installment_split($feeCents, $n) : array_merge([$feeCents], array_fill(0, $n - 1, 0));
    foreach ($rows as $i => &$r) {
        $r['interest'] = $interest[$i] / 100;
        $r['fee'] = $fees[$i] / 100;
        $r['markup'] = round($r['interest'] + $r['fee'], 2);
        $r['amount'] = round($r['bank'] + $r['markup'], 2);
    }
    unset($r);

    return $rows;
}

/**
 * What you received on a row: the part paid beyond the bank's amount (bank
 * first), your fee before your interest. Returns ['fee' => .., 'interest' => ..].
 */
function borrower_rate_earned_row(array $r, float $paid): array
{
    $beyond = max(0, $paid - (float) ($r['bank'] ?? $r['amount']));
    $fee = min((float) ($r['fee'] ?? 0), $beyond);
    $interest = min((float) ($r['interest'] ?? 0), $beyond - $fee);

    return ['fee' => round($fee, 2), 'interest' => round(max(0, $interest), 2)];
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
    $where = ['t.borrower_id IS NOT NULL', earnings_fee_ready() ? '(t.borrower_rate > 0 OR t.borrower_fee_value > 0)' : 't.borrower_rate > 0',
              "t.status NOT IN ('cancelled', 'refunded')", owner_scope('transactions', 't')];
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
        $out[] = $row + ['ledger' => $l, 'interest' => $l['interest_total'], 'fee' => $l['fee_total'], 'total' => $l['markup'],
                         'earned' => $l['markup_earned'], 'earned_interest' => $l['interest_earned'], 'earned_fee' => $l['fee_earned'],
                         'to_receive' => round($l['markup'] - $l['markup_earned'], 2)];
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
