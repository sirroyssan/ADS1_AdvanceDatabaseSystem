<?php
/**
 * Late charges and finance charges, as one list for the borrower portal
 * (portal/penalties.php) and for the owner (pages/late_charges/index.php).
 *
 *   - Late charges: every applied penalty, on an obligation or on a card
 *     purchase repaid to the owner (applied_penalties.transaction_id), with
 *     what was paid, what is still to pay and what was waived.
 *   - Finance charges: the interest and fees already recorded on the items
 *     (installment plan interest / bank fee of a purchase, agreed interest /
 *     fees of an obligation). They are part of the installments, not extra.
 *   - Bank charges (owner only): charges the bank put on the owner's cards.
 *     They are not tied to a borrower in the records, so borrowers never see them.
 */

defined('APP_ROOT') or exit('No direct access.');

/** Penalties a late charge list may show: on an open obligation, or on a purchase that is not cancelled. */
const LATE_CHARGES_FROM = 'applied_penalties ap
    JOIN penalty_rules r ON r.id = ap.penalty_rule_id
    LEFT JOIN borrower_obligations o ON o.id = ap.obligation_id
    LEFT JOIN transactions t ON t.id = ap.transaction_id
    JOIN borrowers b ON b.id = COALESCE(o.borrower_id, t.borrower_id)';

const LATE_CHARGES_SELECT = "ap.*, r.name AS rule_name, b.id AS borrower_id, b.first_name, b.middle_name, b.last_name, b.suffix, b.borrower_number,
    o.id AS obligation_id, t.id AS purchase_id, COALESCE(o.obligation_number, t.transaction_number) AS item_number,
    IF(t.id IS NULL, 'Obligation', 'Card purchase') AS item_kind";

/** WHERE for the list: [conditions, params]. $borrowerId: one borrower; null: the signed-in owner's borrowers. */
function late_charges_where(?string $borrowerId): array
{
    $where = ["((o.id IS NOT NULL AND o.status NOT IN ('cancelled', 'written_off')) OR (t.id IS NOT NULL AND t.status NOT IN ('cancelled', 'refunded')))"];
    $params = [];
    if ($borrowerId !== null) {
        $where[] = 'b.id = :lc_b';
        $params[':lc_b'] = $borrowerId;
    } else {
        $where[] = owner_scope('borrowers', 'b');
    }

    return [$where, $params];
}

/** Show filter (unpaid / paid / waived) as SQL. */
const LATE_CHARGES_STATES = [
    'unpaid' => 'ap.remaining_amount > 0',
    'paid'   => "ap.remaining_amount = 0 AND (ap.notes IS NULL OR ap.notes NOT LIKE '%WAIVED%')",
    'waived' => "ap.notes LIKE '%WAIVED%'",
];

/** Apply the list filters (show, from, to) and the search box. */
function late_charges_filter(array $state, array &$where, array &$params): void
{
    add_search_condition($state, ['o.obligation_number', 't.transaction_number', 'r.name', 'b.first_name', 'b.last_name'], $where, $params);
    if (($state['filters']['state'] ?? '') !== '') {
        $where[] = LATE_CHARGES_STATES[$state['filters']['state']];
    }
    if (($state['filters']['from'] ?? '') !== '') {
        $where[] = 'ap.applied_date >= :lc_from';
        $params[':lc_from'] = $state['filters']['from'];
    }
    if (($state['filters']['to'] ?? '') !== '') {
        $where[] = 'ap.applied_date <= :lc_to';
        $params[':lc_to'] = $state['filters']['to'];
    }
}

/** Totals of the late charges matching the filters: charged, paid, unpaid. */
function late_charges_sums(array $where, array $params): array
{
    return db_row(
        'SELECT COALESCE(SUM(ap.amount), 0) AS charged, COALESCE(SUM(ap.paid_amount), 0) AS paid, COALESCE(SUM(ap.remaining_amount), 0) AS unpaid
           FROM ' . LATE_CHARGES_FROM . ' WHERE ' . implode(' AND ', $where),
        $params
    );
}

/** The late charges table. $owner: owner layout (borrower column, owner links). */
function late_charges_table(array $rows, array $sums, int $offset, bool $owner): string
{
    $html = '<div class="table-wrap"><table class="table table--cards"><thead><tr>'
        . '<th scope="col" class="col-num">#</th><th scope="col">Date charged</th>' . ($owner ? '<th scope="col">Borrower</th>' : '')
        . '<th scope="col">Item</th><th scope="col">Charge</th><th scope="col" class="num">Days late</th><th scope="col" class="num">Amount</th>'
        . '<th scope="col" class="num">Paid</th><th scope="col" class="num">To pay</th><th scope="col">Status</th></tr></thead><tbody>';
    foreach ($rows as $i => $p) {
        $waived = str_contains((string) $p['notes'], 'WAIVED');
        $unpaid = (float) $p['remaining_amount'] > 0.004;
        $link = $p['purchase_id']
            ? url($owner ? 'pages/transactions/view.php' : 'pages/borrowed/view.php', ['id' => $p['purchase_id']])
            : url($owner ? 'pages/obligations/view.php' : 'portal/obligation.php', ['id' => $p['obligation_id']]);
        $html .= '<tr><td class="col-num" data-label="#">' . e($offset + $i + 1) . '</td>'
            . '<td data-label="Date charged">' . e(format_date($p['applied_date'])) . '</td>'
            . ($owner ? '<td data-label="Borrower"><a class="table-link" href="' . e(url('pages/borrowers/view.php', ['id' => $p['borrower_id']])) . '">' . e(borrower_name($p)) . '</a></td>' : '')
            . '<td data-label="Item"><a ' . ($p['purchase_id'] ? '' : 'data-modal-form ') . 'class="table-link" href="' . e($link) . '">' . e($p['item_number']) . '</a>'
            . '<br><span class="text-muted">' . e($p['item_kind']) . '</span></td>'
            . '<td data-label="Charge">' . e($p['rule_name']) . '</td>'
            . '<td data-label="Days late" class="num">' . e($p['overdue_days']) . '</td>'
            . '<td data-label="Amount" class="num">' . e(format_money($p['amount'])) . '</td>'
            . '<td data-label="Paid" class="num">' . e(format_money($p['paid_amount'])) . '</td>'
            . '<td data-label="To pay" class="num' . ($unpaid ? ' text-danger' : '') . '"><strong>' . e(format_money($p['remaining_amount'])) . '</strong></td>'
            . '<td data-label="Status">' . ($unpaid ? status_badge('unpaid', ['unpaid' => 'danger'])
                : ($waived ? status_badge('waived', ['waived' => 'info']) : status_badge('paid', ['paid' => 'success']))) . '</td></tr>';
    }

    return $html . '</tbody><tfoot><tr><td colspan="' . ($owner ? 6 : 5) . '">Total (matching filters)</td>'
        . '<td class="num">' . e(format_money($sums['charged'])) . '</td><td class="num">' . e(format_money($sums['paid'])) . '</td>'
        . '<td class="num"><strong>' . e(format_money($sums['unpaid'])) . '</strong></td><td></td></tr></tfoot></table></div>';
}

/**
 * Finance charges already recorded on the items: interest and fees of a
 * purchase's installment plan, agreed interest and fees of an obligation.
 * $borrowerId: one borrower; null: the signed-in owner's borrowers.
 *
 * @return array<int, array{date: string, borrower: array, item: string, kind: string, purchase_id: ?string, obligation_id: ?string, charge: string, amount: float, note: string}>
 */
function finance_charges_rows(?string $borrowerId): array
{
    [$scope, $params] = $borrowerId !== null ? ['b.id = :fc_b', [':fc_b' => $borrowerId]] : [owner_scope('borrowers', 'b'), []];
    $who = 'b.id AS borrower_id, b.first_name, b.middle_name, b.last_name, b.suffix, b.borrower_number';
    $rows = [];

    // Purchases repaid to the owner (not the older ones tracked as an obligation - those are below).
    foreach (db_all(
        "SELECT $who, t.id, t.transaction_number, t.transaction_date, p.interest_amount, p.fees_amount, p.interest_rate, p.number_of_installments
           FROM transactions t JOIN borrowers b ON b.id = t.borrower_id
           JOIN installment_plans p ON p.transaction_id = t.id AND p.status <> 'cancelled'
          WHERE $scope AND t.status NOT IN ('cancelled', 'refunded') AND (p.interest_amount > 0 OR p.fees_amount > 0)
            AND NOT EXISTS (SELECT 1 FROM borrower_obligations x WHERE x.transaction_id = t.id AND x.status <> 'cancelled')
          ORDER BY t.transaction_date",
        $params
    ) as $r) {
        $base = ['date' => $r['transaction_date'], 'borrower' => $r, 'item' => $r['transaction_number'], 'kind' => 'Card purchase',
                 'purchase_id' => $r['id'], 'obligation_id' => null, 'note' => 'Included in the ' . (int) $r['number_of_installments'] . ' installments'];
        if ((float) $r['interest_amount'] > 0) {
            $rows[] = $base + ['charge' => 'Finance charge (bank interest' . ((float) $r['interest_rate'] > 0 ? ' ' . rtrim(rtrim((string) $r['interest_rate'], '0'), '.') . '%' : '') . ')',
                               'amount' => (float) $r['interest_amount']];
        }
        if ((float) $r['fees_amount'] > 0) {
            $rows[] = $base + ['charge' => 'Bank installment fee', 'amount' => (float) $r['fees_amount']];
        }
    }
    foreach (db_all(
        "SELECT $who, o.id, o.obligation_number, o.obligation_date, o.agreed_interest, o.agreed_fees, o.agreed_rate
           FROM borrower_obligations o JOIN borrowers b ON b.id = o.borrower_id
          WHERE $scope AND o.status NOT IN ('cancelled', 'written_off') AND (o.agreed_interest > 0 OR o.agreed_fees > 0)
          ORDER BY o.obligation_date",
        $params
    ) as $r) {
        $base = ['date' => $r['obligation_date'], 'borrower' => $r, 'item' => $r['obligation_number'], 'kind' => 'Obligation',
                 'purchase_id' => null, 'obligation_id' => $r['id'], 'note' => 'Included in the amount due'];
        if ((float) $r['agreed_interest'] > 0) {
            $rows[] = $base + ['charge' => 'Agreed interest' . ($r['agreed_rate'] !== null && (float) $r['agreed_rate'] > 0 ? ' (' . rtrim(rtrim((string) $r['agreed_rate'], '0'), '.') . '% a month)' : ''),
                               'amount' => (float) $r['agreed_interest']];
        }
        if ((float) $r['agreed_fees'] > 0) {
            $rows[] = $base + ['charge' => 'Agreed fees', 'amount' => (float) $r['agreed_fees']];
        }
    }
    usort($rows, static fn ($a, $b) => [$a['date'], $a['item']] <=> [$b['date'], $b['item']]);

    return $rows;
}

/** The finance charges table. */
function finance_charges_table(array $rows, bool $owner): string
{
    if (!$rows) {
        return '<p class="empty-inline">No interest or fees are recorded on ' . ($owner ? 'your borrowers\' items' : 'your items') . '.</p>';
    }
    $html = '<div class="table-wrap"><table class="table table--cards table--compact"><thead><tr><th scope="col">Date</th>'
        . ($owner ? '<th scope="col">Borrower</th>' : '') . '<th scope="col">Item</th><th scope="col">Charge</th><th scope="col" class="num">Amount</th><th scope="col">Note</th></tr></thead><tbody>';
    $total = 0.0;
    foreach ($rows as $r) {
        $total += $r['amount'];
        $link = $r['purchase_id']
            ? url($owner ? 'pages/transactions/view.php' : 'pages/borrowed/view.php', ['id' => $r['purchase_id']])
            : url($owner ? 'pages/obligations/view.php' : 'portal/obligation.php', ['id' => $r['obligation_id']]);
        $html .= '<tr><td data-label="Date">' . e(format_date($r['date'])) . '</td>'
            . ($owner ? '<td data-label="Borrower">' . e(borrower_name($r['borrower'])) . '</td>' : '')
            . '<td data-label="Item"><a class="table-link" href="' . e($link) . '">' . e($r['item']) . '</a><br><span class="text-muted">' . e($r['kind']) . '</span></td>'
            . '<td data-label="Charge">' . e($r['charge']) . '</td>'
            . '<td data-label="Amount" class="num">' . e(format_money($r['amount'])) . '</td>'
            . '<td data-label="Note" class="text-muted">' . e($r['note']) . '</td></tr>';
    }

    return $html . '</tbody><tfoot><tr><td colspan="' . ($owner ? 4 : 3) . '">Total</td><td class="num"><strong>' . e(format_money($total)) . '</strong></td><td></td></tr></tfoot></table></div>';
}
