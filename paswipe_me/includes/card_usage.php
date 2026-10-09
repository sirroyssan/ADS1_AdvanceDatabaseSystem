<?php
/**
 * Credit card usage - the ONE place where a card's used credit, available
 * credit and utilization are calculated. Every page (dashboard, card list,
 * card details, reports, cash loans) reads these functions; nothing else
 * computes "used" on its own.
 *
 * Used credit is calculated from the records stored for the card:
 *
 *     used = opening balance
 *          + purchases            (transactions: posted, active, completed, disputed, refunded)
 *          + cash loans           (principal of accepted, disbursed, active and completed loans)
 *          + cash advances        (amount of active and completed advances)
 *          + interest and fees    (loan interest/fees billed to the card, advance fees and
 *                                  interest, installment plan interest/fees, bank charges)
 *          − card payments        (credit_card_payments)
 *          − credits and refunds  (refunded transactions, waived installments)
 *
 *     available   = credit limit − used
 *     utilization = used ÷ credit limit × 100
 *
 * Counted once: a transaction with payment type "cash loan" / "cash advance"
 * whose reference number equals a cash loan's loan reference (or a cash
 * advance's reference number) on the same card is the SAME charge, so only
 * the cash loan / cash advance record is counted.
 *
 * Cash loan interest and fees: the part of the total repayment above the
 * principal is billed to the card in the monthly installments, so it uses
 * credit. Processing and other fees use credit only when they were NOT taken
 * out of the cash received (cash received lower than the principal means the
 * bank already deducted them from the proceeds).
 *
 * credit_cards.available_credit is kept as a stored copy of the calculated
 * value (card_usage_sync), so the schema view vw_credit_card_utilization and
 * phpMyAdmin show the same figures. The stored copy is clamped to the
 * schema's CHECK range (0 … credit limit); the pages show the real figure,
 * including an over-limit amount.
 */

defined('APP_ROOT') or exit('No direct access.');

/** Transaction statuses that are a real charge on the card (draft and cancelled are not). */
const CARD_TXN_CHARGED = ['posted', 'active', 'completed', 'disputed', 'refunded'];
/** Cash loan statuses that use the card's credit (offered, cancelled and rejected do not). */
const CASH_LOAN_CHARGED = ['accepted', 'disbursed', 'active', 'completed'];
/** Cash advance statuses that use the card's credit. */
const CASH_ADVANCE_CHARGED = ['active', 'completed'];
/** bank_charges.charge_type used for the balance a card already had before it was tracked. */
const OPENING_BALANCE_TYPE = 'Opening balance';

/** Figures returned by card_usage(), in display order. */
const CARD_USAGE_PARTS = [
    'opening_balance' => 'Opening balance',
    'purchases'       => 'Total purchases',
    'cash_loans'      => 'Cash loans (principal)',
    'cash_advances'   => 'Cash advances',
    'interest_fees'   => 'Interest / fees charged',
    'payments'        => 'Payments',
    'credits'         => 'Credits / refunds',
];

function sql_in_list(array $values): string
{
    return implode(', ', array_map(static fn ($v) => "'" . str_replace("'", "''", $v) . "'", $values));
}

/**
 * Cash loan principal: accepted amount, else approved amount, else offered amount.
 */
function cash_loan_principal(array $loan): float
{
    foreach (['accepted_amount', 'approved_amount', 'offered_amount'] as $column) {
        if ((float) ($loan[$column] ?? 0) > 0) {
            return (float) $loan[$column];
        }
    }

    return 0.0;
}

/**
 * What a cash loan charges to its card: principal + interest/fees billed to the card.
 *
 * @return array{principal: float, interest: float, fees: float, fees_deducted: float, total: float, counts: bool}
 */
function cash_loan_card_charge(array $loan): array
{
    $principal = cash_loan_principal($loan);
    $interest  = max(0, (float) $loan['total_repayment'] - $principal);
    $fees      = (float) $loan['processing_fee'] + (float) $loan['other_fees'];
    $cash      = (float) $loan['actual_cash_received'];
    $deducted  = $cash > 0 ? min($fees, max(0, $principal - $cash)) : 0.0;
    $onCard    = max(0, $fees - $deducted);

    return [
        'principal'     => round($principal, 2),
        'interest'      => round($interest, 2),
        'fees'          => round($onCard, 2),
        'fees_deducted' => round($deducted, 2),
        'total'         => round($principal + $interest + $onCard, 2),
        'counts'        => in_array($loan['status'], CASH_LOAN_CHARGED, true),
    ];
}

/**
 * Derived table: one row per card with every usage part and `used`.
 * Join it with:  LEFT JOIN (<card_usage_sql()>) cu ON cu.credit_card_id = cc.id
 */
function card_usage_sql(): string
{
    $txnCharged  = sql_in_list(CARD_TXN_CHARGED);
    $loanCharged = sql_in_list(CASH_LOAN_CHARGED);
    $advCharged  = sql_in_list(CASH_ADVANCE_CHARGED);
    $opening     = sql_in_list([OPENING_BALANCE_TYPE]);
    $principal   = 'COALESCE(NULLIF(l.accepted_amount, 0), NULLIF(l.approved_amount, 0), NULLIF(l.offered_amount, 0), 0)';

    // A cash-type transaction that points to a cash loan / advance record is the same charge.
    $notLinked = "NOT (
            (t.payment_type = 'cash_loan' AND EXISTS (SELECT 1 FROM cash_loans xl WHERE xl.credit_card_id = t.credit_card_id AND xl.loan_reference = t.reference_number))
         OR (t.payment_type = 'cash_advance' AND EXISTS (SELECT 1 FROM cash_advances xa WHERE xa.credit_card_id = t.credit_card_id AND xa.reference_number = t.reference_number)))";

    $parts = [
        // Purchases (cash-type transactions without a loan/advance record are counted as cash).
        "SELECT t.credit_card_id, CASE t.payment_type WHEN 'cash_loan' THEN 'cash_loans' WHEN 'cash_advance' THEN 'cash_advances' ELSE 'purchases' END AS part, t.amount
           FROM transactions t WHERE t.status IN ($txnCharged) AND $notLinked",
        // Refunded transactions give the amount back.
        "SELECT t.credit_card_id, 'credits', t.amount FROM transactions t WHERE t.status = 'refunded' AND $notLinked",
        // Installment plan interest and fees are billed to the card (not for a borrower's
        // share of a cash loan/advance: that plan is the borrower's repayment, not the bank's).
        "SELECT t.credit_card_id, 'interest_fees', p.interest_amount + p.fees_amount
           FROM installment_plans p JOIN transactions t ON t.id = p.transaction_id
          WHERE t.status IN ($txnCharged) AND t.status <> 'refunded' AND p.status <> 'cancelled' AND $notLinked",
        "SELECT t.credit_card_id, 'credits', s.remaining_amount
           FROM installment_schedules s JOIN installment_plans p ON p.id = s.installment_plan_id JOIN transactions t ON t.id = p.transaction_id
          WHERE s.status = 'waived' AND t.status IN ($txnCharged) AND t.status <> 'refunded' AND p.status <> 'cancelled' AND $notLinked",
        // Cash loans: principal, then interest and fees billed to the card.
        "SELECT l.credit_card_id, 'cash_loans', $principal FROM cash_loans l WHERE l.status IN ($loanCharged)",
        "SELECT l.credit_card_id, 'interest_fees',
                GREATEST(l.total_repayment - $principal, 0)
              + GREATEST(l.processing_fee + l.other_fees - IF(l.actual_cash_received > 0, GREATEST($principal - l.actual_cash_received, 0), 0), 0)
           FROM cash_loans l WHERE l.status IN ($loanCharged)",
        "SELECT l.credit_card_id, 'credits', s.remaining_amount
           FROM cash_loan_schedules s JOIN cash_loans l ON l.id = s.cash_loan_id
          WHERE s.status = 'waived' AND l.status IN ($loanCharged)",
        // Cash advances.
        "SELECT a.credit_card_id, 'cash_advances', a.amount FROM cash_advances a WHERE a.status IN ($advCharged)",
        "SELECT a.credit_card_id, 'interest_fees', a.fee + a.interest FROM cash_advances a WHERE a.status IN ($advCharged)",
        // Bank charges (the opening balance is kept as its own part).
        "SELECT b.credit_card_id, IF(b.charge_type IN ($opening), 'opening_balance', 'interest_fees'), b.amount FROM bank_charges b",
        // Payments to the bank.
        "SELECT cp.credit_card_id, 'payments', cp.amount FROM credit_card_payments cp",
    ];

    $sums = [];
    foreach (array_keys(CARD_USAGE_PARTS) as $part) {
        $sums[] = "SUM(CASE WHEN u.part = '$part' THEN u.amount ELSE 0 END) AS $part";
    }

    return 'SELECT u.credit_card_id, ' . implode(', ', $sums) . ",
                   SUM(CASE WHEN u.part IN ('payments', 'credits') THEN -u.amount ELSE u.amount END) AS used
              FROM (" . implode("\n UNION ALL ", $parts) . ') u
             GROUP BY u.credit_card_id';
}

/**
 * SELECT columns for a list joined with card_usage_sql() as $u on cards $c:
 * used_credit, remaining_credit, utilization (2 decimals).
 */
function card_usage_columns(string $c = 'cc', string $u = 'cu'): string
{
    return "COALESCE($u.used, 0) AS used_credit,
            $c.credit_limit - COALESCE($u.used, 0) AS remaining_credit,
            IF($c.credit_limit > 0, ROUND(COALESCE($u.used, 0) / $c.credit_limit * 100, 2), 0) AS utilization";
}

function card_usage_join(string $c = 'cc', string $u = 'cu'): string
{
    return 'LEFT JOIN (' . card_usage_sql() . ") $u ON $u.credit_card_id = $c.id";
}

/**
 * Full usage of one card.
 *
 * @return array<string, float|bool> parts of CARD_USAGE_PARTS + credit_limit, used, available, utilization, over_limit
 */
function card_usage(string $cardId): array
{
    $row = db_row(
        'SELECT cc.credit_limit, cu.* FROM credit_cards cc ' . card_usage_join() . ' WHERE cc.id = :id',
        [':id' => $cardId]
    ) ?: ['credit_limit' => 0];

    $usage = ['credit_limit' => (float) $row['credit_limit']];
    foreach (array_keys(CARD_USAGE_PARTS) as $part) {
        $usage[$part] = round((float) ($row[$part] ?? 0), 2);
    }
    $usage['used']        = round((float) ($row['used'] ?? 0), 2);
    $usage['available']   = round($usage['credit_limit'] - $usage['used'], 2);
    $usage['utilization'] = $usage['credit_limit'] > 0 ? round($usage['used'] / $usage['credit_limit'] * 100, 2) : 0.0;
    $usage['over_limit']  = $usage['available'] < -0.004;

    return $usage;
}

/**
 * Store the calculated available credit on the card rows (clamped to the
 * schema's 0 … limit CHECK) and log every change.
 */
function card_usage_sync(array $cardIds): void
{
    foreach (array_unique(array_filter($cardIds)) as $cardId) {
        $card = db_row('SELECT id, credit_limit, available_credit FROM credit_cards WHERE id = :id', [':id' => $cardId]);
        if (!$card) {
            continue;
        }
        $usage = card_usage($cardId);
        $stored = number_format(min((float) $card['credit_limit'], max(0, $usage['available'])), 2, '.', '');

        if ($stored !== number_format((float) $card['available_credit'], 2, '.', '')) {
            db_update('credit_cards', ['available_credit' => $stored], $cardId);
            log_activity('credit_recalc', 'credit_card', $cardId,
                'Available credit recalculated: used ' . format_money($usage['used']) . ' of ' . format_money($usage['credit_limit']),
                ['available_credit' => $card['available_credit']], ['available_credit' => $stored]);
        }
    }
}

/**
 * Run $change in one transaction with the card rows locked, then store the
 * new available credit. With $guard, a change that pushes a card over its
 * limit is refused (DomainException, everything rolled back).
 */
function card_usage_apply(array $cardIds, callable $change, bool $guard = false): mixed
{
    $cardIds = array_values(array_unique(array_filter($cardIds)));
    sort($cardIds);

    return db_transaction(function () use ($cardIds, $change, $guard) {
        $before = [];
        foreach ($cardIds as $cardId) {
            db_value('SELECT id FROM credit_cards WHERE id = :id FOR UPDATE', [':id' => $cardId]);
            $before[$cardId] = card_usage($cardId)['used'];
        }

        $result = $change();

        foreach ($cardIds as $cardId) {
            $after = card_usage($cardId);
            $added = $after['used'] - $before[$cardId];
            if ($guard && $added > 0.004 && $after['over_limit']) {
                $card = db_row('SELECT card_nickname, last_four FROM credit_cards WHERE id = :id', [':id' => $cardId]);
                throw new DomainException(
                    'Not enough available credit on ' . $card['card_nickname'] . ' •••• ' . $card['last_four'] . ': this needs '
                    . format_money($added) . ' but only ' . format_money(max(0, $after['credit_limit'] - $before[$cardId])) . ' is available.'
                );
            }
        }

        card_usage_sync($cardIds);

        return $result;
    });
}

/**
 * Opening balance (bank_charges row of type OPENING_BALANCE_TYPE) of a card.
 */
function card_opening_balance(string $cardId): ?array
{
    return db_row(
        'SELECT * FROM bank_charges WHERE credit_card_id = :id AND charge_type = :t ORDER BY created_at LIMIT 1',
        [':id' => $cardId, ':t' => OPENING_BALANCE_TYPE]
    );
}

function card_set_opening_balance(string $cardId, string $amount, ?string $date = null): void
{
    $row = card_opening_balance($cardId);
    if ($row) {
        if (number_format((float) $row['amount'], 2, '.', '') !== number_format((float) $amount, 2, '.', '')) {
            db_update('bank_charges', ['amount' => $amount], $row['id']);
        }
        return;
    }

    db_insert('bank_charges', [
        'id'           => new_uuid(),
        'credit_card_id' => $cardId,
        'charge_date'  => $date ?: date('Y-m-d'),
        'charge_type'  => OPENING_BALANCE_TYPE,
        'amount'       => $amount,
        'description'  => 'Balance already used on the card before it was recorded in ' . APP_NAME . '.',
    ]);
}

/**
 * One-time upgrade for cards saved before used credit was calculated: the
 * balance typed into "available credit" that is not explained by recorded
 * transactions becomes the card's opening balance, so no figure is lost.
 * Cards that already have an opening balance row are skipped (one query).
 */
function card_usage_ensure_opening_balances(): void
{
    $cards = db_all(
        'SELECT cc.id, cc.credit_limit, cc.available_credit, cc.opening_date FROM credit_cards cc
          WHERE NOT EXISTS (SELECT 1 FROM bank_charges b WHERE b.credit_card_id = cc.id AND b.charge_type = :t)',
        [':t' => OPENING_BALANCE_TYPE]
    );

    foreach ($cards as $card) {
        card_usage_apply([$card['id']], function () use ($card) {
            $typedUsed = (float) $card['credit_limit'] - (float) $card['available_credit'];
            $opening = max(0, round($typedUsed - card_usage($card['id'])['used'], 2));
            card_set_opening_balance($card['id'], number_format($opening, 2, '.', ''), $card['opening_date']);
            log_activity('opening_balance', 'credit_card', $card['id'], 'Opening balance set to ' . format_money($opening) . ' from the card\'s stored available credit');
        });
    }
}

/**
 * Record a payment to the bank for a loan / installment plan installment, so
 * it lowers the card's used credit. The reference ties it to its source.
 */
function record_installment_card_payment(string $cardId, string $amount, string $reference, string $type, string $note): void
{
    db_insert('credit_card_payments', [
        'id'               => new_uuid(),
        'credit_card_id'   => $cardId,
        'payment_date'     => date('Y-m-d'),
        'amount'           => $amount,
        'payment_type'     => $type,
        'reference_number' => $reference,
        'notes'            => $note,
    ]);
}

/**
 * Small usage summary used on the card page, the cash loan page and the dashboard.
 */
function render_card_usage_figures(array $usage): string
{
    $pct = min(100, max(0, $usage['utilization']));

    return '<div class="figure"><p class="figure__label">Credit limit</p><p class="figure__value">' . e(format_money($usage['credit_limit'])) . '</p></div>'
        . '<div class="figure"><p class="figure__label">Used credit</p><p class="figure__value">' . e(format_money($usage['used'])) . '</p></div>'
        . '<div class="figure"><p class="figure__label">Available credit</p><p class="figure__value' . ($usage['over_limit'] ? ' text-danger' : '') . '">'
            . e(format_money($usage['available'])) . '</p>' . ($usage['over_limit'] ? '<p class="figure__note text-danger">Over the limit</p>' : '') . '</div>'
        . '<div class="figure"><p class="figure__label">Utilization</p><p class="figure__value">' . e(number_format($usage['utilization'], 2)) . '%</p>'
            . '<progress class="meter" max="100" value="' . e($pct) . '">' . e(number_format($usage['utilization'], 2)) . '%</progress></div>';
}

/**
 * Same columns as the schema view vw_credit_card_utilization (active cards),
 * but calculated from the card's records. Used by the dashboard and reports.
 */
function card_utilization_sql(): string
{
    return "SELECT cc.id, cc.card_nickname, cc.issuer, cc.last_four, cc.credit_limit,
                   COALESCE(cu.used, 0) AS outstanding,
                   cc.credit_limit - COALESCE(cu.used, 0) AS available_credit,
                   IF(cc.credit_limit > 0, ROUND(COALESCE(cu.used, 0) / cc.credit_limit * 100, 2), 0) AS utilization_percentage
              FROM credit_cards cc " . card_usage_join() . "
             WHERE cc.status = 'active'";
}
