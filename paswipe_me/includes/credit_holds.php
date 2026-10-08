<?php
/**
 * Credit held on a card by cash loans.
 *
 * A bank cash loan is charged against the card's credit limit. While a loan
 * is accepted, disbursed or active, its unpaid PRINCIPAL is "held" and the
 * card's available_credit is reduced by that amount. Each installment paid
 * (or waived) releases its principal part again. A completed, cancelled or
 * rejected loan holds nothing.
 *
 *   held = loan principal − principal already paid
 *   loan principal = accepted amount (or approved amount when not filled in)
 *
 * Every change to a loan or its schedule runs through cash_loan_sync_hold(),
 * which compares the hold before and after the change and moves only the
 * difference on credit_cards.available_credit, so manual corrections made on
 * the card page are kept.
 */

defined('APP_ROOT') or exit('No direct access.');

const CASH_LOAN_HOLD_STATUSES = ['accepted', 'disbursed', 'active'];

/**
 * Current hold of one loan, read from the database.
 *
 * @return array{card_id: ?string, amount: float, principal: float, paid: float}
 */
function cash_loan_hold(string $loanId): array
{
    $loan = db_row('SELECT credit_card_id, status, accepted_amount, approved_amount FROM cash_loans WHERE id = :id', [':id' => $loanId]);

    if (!$loan) {
        return ['card_id' => null, 'amount' => 0.0, 'principal' => 0.0, 'paid' => 0.0];
    }

    $principal = (float) $loan['accepted_amount'] > 0 ? (float) $loan['accepted_amount'] : (float) $loan['approved_amount'];

    // Principal part of what was paid: whole principal of settled rows,
    // otherwise the paid share of the row (payments cover principal and interest proportionally).
    $paid = (float) db_value(
        "SELECT COALESCE(SUM(CASE
                    WHEN status IN ('paid', 'waived') THEN principal_amount
                    WHEN amount_due > 0 THEN LEAST(principal_amount, amount_paid * principal_amount / amount_due)
                    ELSE 0 END), 0)
           FROM cash_loan_schedules WHERE cash_loan_id = :id",
        [':id' => $loanId]
    );

    $amount = in_array($loan['status'], CASH_LOAN_HOLD_STATUSES, true) ? max(0, round($principal - $paid, 2)) : 0.0;

    return ['card_id' => $loan['credit_card_id'], 'amount' => $amount, 'principal' => $principal, 'paid' => round($paid, 2)];
}

/**
 * Run $change and move the card's available credit by the change in hold.
 * Throws DomainException (and rolls everything back) when the card does not
 * have enough available credit for a new or larger loan.
 *
 * For a new loan, pass the new id: the "before" hold is then zero.
 */
function cash_loan_sync_hold(string $loanId, callable $change): mixed
{
    return db_transaction(function () use ($loanId, $change) {
        $before = cash_loan_hold($loanId);
        $result = $change();
        $after = cash_loan_hold($loanId);

        $deltas = [];
        if ($before['card_id']) {
            $deltas[$before['card_id']] = ($deltas[$before['card_id']] ?? 0) + $before['amount'];
        }
        if ($after['card_id']) {
            $deltas[$after['card_id']] = ($deltas[$after['card_id']] ?? 0) - $after['amount'];
        }

        foreach ($deltas as $cardId => $delta) {
            $delta = round($delta, 2);
            if (abs($delta) < 0.005) {
                continue;
            }

            $card = db_row('SELECT id, card_nickname, last_four, credit_limit, available_credit FROM credit_cards WHERE id = :id FOR UPDATE', [':id' => $cardId]);
            $new = (float) $card['available_credit'] + $delta;

            if ($new < -0.004) {
                throw new DomainException(
                    'Not enough available credit on ' . $card['card_nickname'] . ' •••• ' . $card['last_four'] . ': the loan needs '
                    . format_money(-$delta) . ' but only ' . format_money($card['available_credit']) . ' is available.'
                );
            }
            // A release can never push available credit above the limit.
            $new = min((float) $card['credit_limit'], max(0, $new));

            db_update('credit_cards', ['available_credit' => number_format($new, 2, '.', '')], $card['id']);
            log_activity(
                'credit_hold',
                'credit_card',
                $card['id'],
                ($delta < 0 ? 'Cash loan used ' : 'Cash loan released ') . format_money(abs($delta)) . ' of available credit',
                ['available_credit' => $card['available_credit']],
                ['available_credit' => number_format($new, 2, '.', '')]
            );
        }

        return $result;
    });
}

/**
 * Cash loans currently holding credit on a card (for the card page).
 */
function card_cash_loan_holds(string $cardId): array
{
    $loans = db_all(
        "SELECT id, loan_reference, status FROM cash_loans WHERE credit_card_id = :id AND status IN ('accepted', 'disbursed', 'active') ORDER BY created_at",
        [':id' => $cardId]
    );

    foreach ($loans as &$loan) {
        $loan += cash_loan_hold($loan['id']);
    }

    return $loans;
}
