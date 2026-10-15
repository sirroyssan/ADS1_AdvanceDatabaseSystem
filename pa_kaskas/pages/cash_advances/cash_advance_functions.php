<?php
/**
 * Cash Advances module (table: cash_advances) - cash withdrawn on a card.
 * No delete: status 'cancelled' voids a record while keeping history.
 */

defined('APP_ROOT') or exit('No direct access.');

function cash_advance_fields(?array $current = null): array
{
    return [
        'credit_card_id'   => ['label' => 'Credit card', 'type' => 'select', 'options' => credit_card_options($current['credit_card_id'] ?? null, false), 'required' => true, 'empty_label' => 'Select card'],
        'advance_date'     => ['label' => 'Advance date', 'type' => 'date', 'required' => true, 'initial' => date('Y-m-d'),
                               'help' => 'It goes on the statement whose period has this date (cut-off) automatically.'],
        'reference_number' => ['label' => 'Reference number', 'type' => 'text', 'max' => 150],
        'channel'          => ['label' => 'Channel', 'type' => 'text', 'max' => 100, 'help' => 'e.g. ATM, over the counter.'],
        'borrower_id'      => ['label' => 'Borrower', 'type' => 'select', 'options' => borrower_options($current['borrower_id'] ?? null), 'empty_label' => '— For me (no borrower) —',
                               'help' => 'Who got the cash. They repay the total amount in one payment by the card\'s due date (never in installments); late charges follow your penalty rules. A wrong borrower can be changed until they report or pay a repayment.'],
        'status'           => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_CASH_ADVANCE_STATUS), 'required' => true, 'initial' => 'active'],
        'amount'           => ['label' => 'Amount withdrawn', 'type' => 'decimal', 'required' => true, 'min' => 0, 'section' => 'Amounts'],
        'fee'              => ['label' => 'Fee', 'type' => 'decimal', 'min' => 0, 'default' => '0.00'],
        'interest'         => ['label' => 'Interest', 'type' => 'decimal', 'min' => 0, 'default' => '0.00'],
        'total_amount'     => ['label' => 'Total amount', 'type' => 'decimal', 'min' => 0, 'help' => 'Leave blank for amount + fee + interest.',
                               'attrs' => ['data-sum' => 'f_amount,f_fee,f_interest']],
        'notes'            => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 3, 'section' => 'Other'],
    ];
}

/**
 * Validate. Returns [data, errors]; data['borrower_id'] is not a column (the
 * borrower is kept on the linked transaction - cash_advance_sync_borrower) and
 * data['statement_id'] is set from the date: the recorded SOA whose period has it.
 */
function cash_advance_validate(array $fields, array $input, ?string $id = null): array
{
    [$data, $errors] = validate_fields($fields, $input, $id);

    if ($data['total_amount'] === null && !isset($errors['amount'], $errors['fee'], $errors['interest'])) {
        $data['total_amount'] = number_format((float) $data['amount'] + (float) $data['fee'] + (float) $data['interest'], 2, '.', '');
    }
    if (array_key_exists('borrower_id', $data) && $data['borrower_id'] !== null && !owns('borrowers', $data['borrower_id'])) {
        $errors['borrower_id'] = 'Choose one of your borrowers.';
    }
    $cardId = $data['credit_card_id'] ?? ($id ? db_value('SELECT credit_card_id FROM cash_advances WHERE id = :id', [':id' => $id]) : null);
    if (!$errors && $cardId && !empty($data['advance_date'])) {
        $data['statement_id'] = cash_advance_statement_for($cardId, $data['advance_date']);
    }

    return [$data, $errors];
}

/** The recorded statement (SOA) whose period has this date, or null (then it is billed on the upcoming one). */
function cash_advance_statement_for(string $cardId, string $date): ?string
{
    $card = db_row('SELECT * FROM credit_cards WHERE id = :c', [':c' => $cardId]);

    return $card ? (card_period_containing(card_period_context($card), $date)['statement_id'] ?? null) : null;
}

function cash_advance_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        'SELECT a.*, c.card_nickname, c.last_four, s.statement_date
           FROM cash_advances a
           JOIN credit_cards c ON c.id = a.credit_card_id
           LEFT JOIN credit_card_statements s ON s.id = a.statement_id
          WHERE a.id = :id AND ' . owner_scope('cash_advances', 'a'),
        [':id' => $id]
    );
}

/* ------------------------------------------------------------------
 * Shares given to borrowers earlier ("Give to a borrower", now removed:
 * cash advances are records only, never paid in installments). Existing
 * shares are still shown and paid on their obligations.
 *
 * The schema has no borrower column on cash_advances, so each share is
 * stored with existing tables:
 *   transactions        payment_type 'cash_advance', borrower_id, same card and
 *                       the advance's reference_number (the link; card usage
 *                       counts the withdrawal once, from cash_advances)
 *   installment_plans + installment_schedules   the monthly repayment
 *   borrower_obligations  amount given + fee + monthly interest
 * Interest is add-on monthly interest: amount × rate % × months.
 * ------------------------------------------------------------------ */

function cash_advance_shares(array $advance): array
{
    if (!$advance['reference_number']) {
        return [];
    }

    return db_all(
        "SELECT t.id AS transaction_id, t.transaction_number, t.transaction_date, t.amount, t.status AS transaction_status,
                b.id AS borrower_id, b.first_name, b.last_name, b.borrower_number,
                o.id AS obligation_id, o.obligation_number, o.total_amount_due, o.outstanding_amount, o.status AS obligation_status,
                p.number_of_installments, p.installment_amount
           FROM transactions t
           JOIN borrowers b ON b.id = t.borrower_id
           LEFT JOIN borrower_obligations o ON o.transaction_id = t.id AND o.status <> 'cancelled'
           LEFT JOIN installment_plans p ON p.transaction_id = t.id
          WHERE t.payment_type = 'cash_advance' AND t.credit_card_id = :c AND t.reference_number = :r AND t.status <> 'cancelled'
          ORDER BY t.transaction_date, t.created_at",
        [':c' => $advance['credit_card_id'], ':r' => $advance['reference_number']]
    );
}

function cash_advance_given_total(array $advance): float
{
    return array_sum(array_map(static fn ($s) => $s['obligation_id'] ? (float) $s['amount'] : 0.0, cash_advance_shares($advance)));
}

/* ------------------------------------------------------------------
 * The advance's borrower (the Borrower field): the whole advance is theirs.
 * Stored like a card purchase repaid to the owner - a transactions row
 * (payment_type 'cash_advance', same card and reference number, amount = the
 * advance's total) and no obligation. The borrower repays it in one payment
 * by the card's due date - never in installments; late charges follow the
 * card bank's penalty rules (Penalties panel on the cash advance page).
 * ------------------------------------------------------------------ */

/** The advance's borrower transaction (no obligation), or null. */
function cash_advance_direct_share(array $advance): ?array
{
    if (!$advance['reference_number']) {
        return null;
    }

    return db_row(
        "SELECT t.*, b.first_name, b.middle_name, b.last_name, b.suffix, b.borrower_number, p.id AS plan_id, p.number_of_installments, p.installment_amount,
                p.interest_amount AS plan_interest, p.fees_amount AS plan_fees, p.total_repayment
           FROM transactions t
           JOIN borrowers b ON b.id = t.borrower_id
           LEFT JOIN installment_plans p ON p.transaction_id = t.id AND p.status <> 'cancelled'
          WHERE t.payment_type = 'cash_advance' AND t.credit_card_id = :c AND t.reference_number = :r AND t.status <> 'cancelled'
            AND NOT EXISTS (SELECT 1 FROM borrower_obligations o WHERE o.transaction_id = t.id AND o.status <> 'cancelled')
          ORDER BY t.created_at LIMIT 1",
        [':c' => $advance['credit_card_id'], ':r' => $advance['reference_number']]
    );
}

/** Repayments reported or confirmed on the borrower's transaction. */
function cash_advance_share_repayments(string $transactionId): int
{
    return (int) db_value("SELECT COUNT(*) FROM borrower_payments WHERE transaction_id = :t AND status NOT IN ('rejected', 'cancelled')", [':t' => $transactionId]);
}

/**
 * After the advance was saved (inside the save transaction): create, update or
 * cancel the borrower's transaction so it follows the Borrower field, the date
 * and the total. Throws DomainException when a change would lose repayments.
 * Returns a note for the flash message, or ''.
 */
function cash_advance_sync_borrower(array $advance, ?string $borrowerId, array $user): string
{
    $share = cash_advance_direct_share($advance);
    $wanted = $advance['status'] !== 'cancelled' ? $borrowerId : null;
    $total = number_format((float) $advance['total_amount'], 2, '.', '');

    if ($share === null) {
        if ($wanted === null) {
            return '';
        }
        if (cash_advance_given_total($advance) > 0) {
            throw new DomainException('Part of this cash advance was already given with "Give to a borrower"; it cannot also have a borrower.');
        }
        $id = new_uuid();
        db_insert('transactions', [
            'id' => $id, 'transaction_number' => generate_reference('TXN', 'transactions', 'transaction_number'), 'credit_card_id' => $advance['credit_card_id'],
            'borrower_id' => $wanted, 'transaction_date' => $advance['advance_date'], 'posting_date' => $advance['advance_date'],
            'transaction_type' => 'card_user', 'payment_type' => 'cash_advance', 'status' => 'active',
            'amount' => $total, 'currency' => 'PHP', 'reference_number' => $advance['reference_number'],
            'notes' => 'Cash advance ' . $advance['reference_number'] . ($advance['channel'] ? ' (' . $advance['channel'] . ')' : ''),
            'created_by' => $user['id'], 'updated_by' => $user['id'],
        ]);
        notify_borrower($wanted, 'cash_advance', 'Cash advance: ' . format_money($total),
            'A cash advance of ' . format_money($total) . ' (' . format_date($advance['advance_date']) . ') was recorded for you. See it under Card Purchases for when to repay it.',
            'borrowed_purchase', $id);

        return 'The borrower now owes ' . format_money($total) . ' on this cash advance.';
    }

    $used = cash_advance_share_repayments($share['id']);
    if ($wanted === null) {
        if ($used > 0) {
            throw new DomainException(borrower_name($share) . ' already reported or paid repayments on this cash advance, so it stays theirs. Reject or cancel those repayments first.');
        }
        db_update('transactions', ['status' => 'cancelled', 'updated_by' => $user['id']], $share['id']);
        if ($share['plan_id']) {
            db_update('installment_plans', ['status' => 'cancelled'], $share['plan_id']);
        }
        notify_borrower($share['borrower_id'], 'cash_advance', 'Cash advance removed',
            'The cash advance of ' . format_money($share['amount']) . ' (' . format_date($share['transaction_date']) . ') is no longer recorded for you.', 'borrowed_purchase', $share['id']);

        return 'The cash advance is no longer assigned to ' . borrower_name($share) . '.';
    }

    $changes = [];
    if ($wanted !== $share['borrower_id']) {
        if ($used > 0) {
            throw new DomainException(borrower_name($share) . ' already reported or paid repayments on this cash advance; the borrower cannot be changed.');
        }
        $changes['borrower_id'] = $wanted;
    }
    if ($total !== number_format((float) $share['amount'], 2, '.', '') || $advance['advance_date'] !== $share['transaction_date']) {
        if ($share['plan_id']) {
            throw new DomainException('This cash advance is paid in installments; change the amounts on its installment plan instead.');
        }
        // Never below what the borrower already paid on it (that money would be left over, owed to no charge).
        $paid = (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM borrower_payments WHERE transaction_id = :t AND status = 'approved'", [':t' => $share['id']]);
        if ((float) $total + 0.004 < $paid) {
            throw new DomainException(borrower_name($share) . ' already paid ' . format_money($paid) . ' on this cash advance, so its total cannot be lower than that (you entered ' . format_money($total) . ').');
        }
        $changes += ['amount' => $total, 'transaction_date' => $advance['advance_date'], 'posting_date' => $advance['advance_date']];
    }
    if (!$changes) {
        return '';
    }
    db_update('transactions', $changes + ['updated_by' => $user['id']], $share['id']);
    if (isset($changes['borrower_id'])) {
        notify_borrower($share['borrower_id'], 'cash_advance', 'Cash advance removed',
            'The cash advance of ' . format_money($share['amount']) . ' is no longer recorded for you.', 'borrowed_purchase', $share['id']);
    }
    notify_borrower($wanted, 'cash_advance', 'Cash advance ' . (isset($changes['borrower_id']) ? 'recorded' : 'updated') . ': ' . format_money($total),
        'Your cash advance of ' . format_money($total) . ' (' . format_date($advance['advance_date']) . ') was ' . (isset($changes['borrower_id']) ? 'recorded for you' : 'updated') . '.',
        'borrowed_purchase', $share['id']);

    return 'The borrower\'s cash advance was updated.';
}
