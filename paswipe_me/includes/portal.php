<?php
/**
 * Borrower portal - shared helpers.
 *
 * A borrower signs in with a user account that has the "borrower" role and
 * is linked to ONE borrower record (borrower_user_accounts, set on the
 * borrower's page by an administrator). Every portal query is filtered by
 * that borrower id; ids from the URL are always re-checked against it, so a
 * borrower can never open another borrower's records.
 */

defined('APP_ROOT') or exit('No direct access.');

/** Records a borrower may see. Drafts and cancelled records stay admin-only. */
const PORTAL_AGREEMENT_STATUSES = ['generated', 'active', 'signed', 'completed'];
const PORTAL_LETTER_STATUSES = ['sent', 'acknowledged', 'resolved'];
/** A borrower may cancel a payment only before the owner starts reviewing it. */
const PORTAL_CANCELLABLE_STATUSES = ['submitted', 'correction_required'];

/**
 * Signed-in borrower and their linked borrower record.
 *
 * @return array{0: array, 1: array} [user, borrower]
 */
function require_borrower(): array
{
    $user = require_role(['borrower']);

    $borrower = db_row(
        'SELECT b.* FROM borrower_user_accounts a JOIN borrowers b ON b.id = a.borrower_id
          WHERE a.user_id = :u AND a.deactivated_at IS NULL',
        [':u' => $user['id']]
    );

    if (!$borrower) {
        http_response_code(403);
        $deniedTitle = 'Your account is not linked yet';
        $deniedMessage = 'Your sign-in works, but it is not connected to a borrower record. Please ask the owner to link your account (Borrowers → your record → Sign-in account).';
        require APP_ROOT . '/auth/access_denied.php';
        exit;
    }
    if ($borrower['status'] === 'inactive') {
        force_logout('Your borrower record is inactive. Please contact the owner.');
    }
    penalty_run_if_due();

    return [$user, $borrower];
}

/**
 * Load one row of $table that belongs to the borrower, or go back with a message.
 */
function portal_find(string $sql, string $id, string $borrowerId, string $back, string $label): array
{
    $row = is_valid_uuid($id) ? db_row($sql, [':id' => $id, ':b' => $borrowerId]) : null;

    if (!$row) {
        flash('error', $label . ' not found.');
        redirect($back);
    }

    return $row;
}

/**
 * Can this borrower open the document? Only files attached to their own
 * payments, agreements or sent demand letters.
 */
function portal_can_see_document(string $documentId, string $borrowerId): bool
{
    $agreementStatuses = sql_in_list(PORTAL_AGREEMENT_STATUSES);
    $letterStatuses = sql_in_list(PORTAL_LETTER_STATUSES);

    return (int) db_value(
        "SELECT (SELECT COUNT(*) FROM payment_attachments pa JOIN borrower_payments p ON p.id = pa.borrower_payment_id
                  WHERE pa.document_id = :d1 AND p.borrower_id = :b1)
              + (SELECT COUNT(*) FROM agreements a
                  WHERE (a.generated_document_id = :d2 OR a.signed_document_id = :d3) AND a.borrower_id = :b2 AND a.status IN ($agreementStatuses))
              + (SELECT COUNT(*) FROM agreement_documents ad JOIN agreements a ON a.id = ad.agreement_id
                  WHERE ad.document_id = :d4 AND a.borrower_id = :b3 AND a.status IN ($agreementStatuses))
              + (SELECT COUNT(*) FROM demand_letters l
                  WHERE l.document_id = :d5 AND l.borrower_id = :b4 AND l.status IN ($letterStatuses))",
        [':d1' => $documentId, ':d2' => $documentId, ':d3' => $documentId, ':d4' => $documentId, ':d5' => $documentId,
         ':b1' => $borrowerId, ':b2' => $borrowerId, ':b3' => $borrowerId, ':b4' => $borrowerId]
    ) > 0;
}

/**
 * Payment methods a borrower can pay with: their own accounts and their
 * lender's receiving accounts (active only, plus the current one).
 *
 * @return array<string, string>
 */
function portal_payment_method_options(string $borrowerId, ?string $current = null): array
{
    $rows = db_all(
        'SELECT id, display_name, method_type, masked_account_number, borrower_id FROM payment_methods
          WHERE (borrower_id = :b OR (borrower_id IS NULL AND owner_user_id = (SELECT ob.owner_user_id FROM borrowers ob WHERE ob.id = :b2)))
            AND (is_active = 1 OR id = :c)
          ORDER BY borrower_id IS NULL, display_name',
        [':b' => $borrowerId, ':b2' => $borrowerId, ':c' => $current ?? '']
    );

    $options = [];
    foreach ($rows as $row) {
        $options[$row['id']] = ($row['borrower_id'] ? 'My account: ' : 'Pay to owner: ') . $row['display_name']
            . ($row['masked_account_number'] ? ' (' . $row['masked_account_number'] . ')' : '');
    }

    return $options;
}

/**
 * In-app notification for one user.
 */
function notify_user(string $userId, string $type, string $title, string $message, ?string $entityType = null, ?string $entityId = null): void
{
    db_insert('notifications', [
        'id'                => new_uuid(),
        'user_id'           => $userId,
        'notification_type' => $type,
        'title'             => mb_substr($title, 0, 255),
        'message'           => $message,
        'entity_type'       => $entityType,
        'entity_id'         => $entityId,
        'channel'           => 'in_app',
        'status'            => 'delivered',
        'sent_at'           => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Notify the lender who records this borrower (the borrower's owner) - never
 * other users: each user hears only about their own borrowers.
 */
function notify_lender(string $borrowerId, string $type, string $title, string $message, ?string $entityType = null, ?string $entityId = null): void
{
    $ownerId = db_value(
        "SELECT u.id FROM borrowers b JOIN users u ON u.id = b.owner_user_id WHERE b.id = :b AND u.status = 'active'",
        [':b' => $borrowerId]
    );
    if ($ownerId) {
        notify_user($ownerId, $type, $title, $message, $entityType, $entityId);
    }
}

/**
 * Tell a borrower what the card owner changed on one of their items, field by
 * field ("Amount: ₱10,000.00 → ₱12,000.00; Due date: …"). Nothing is sent
 * when nothing they can see changed. $fields: column => [label, type], type
 * money | date | status | number | text. Returns whether a notice was sent.
 */
function notify_borrower_changes(?string $borrowerId, string $ref, array $before, array $after, array $fields, string $entityType, string $entityId, string $note = ''): bool
{
    if (!$borrowerId) {
        return false;
    }
    $show = static fn ($v, string $type): string => $v === null || $v === '' ? '—' : match ($type) {
        'money'  => format_money($v),
        'date'   => format_date($v),
        'status' => humanize((string) $v),
        'number' => is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.') : (string) $v,
        default  => (string) $v,
    };
    $lines = [];
    foreach ($fields as $column => [$label, $type]) {
        if (!array_key_exists($column, $after)) {
            continue;
        }
        $old = $before[$column] ?? null;
        $new = $after[$column];
        $same = in_array($type, ['money', 'number'], true) ? abs((float) $old - (float) $new) < 0.005 : (string) $old === (string) $new;
        if (!$same) {
            // Long text (a description): only the new wording.
            $lines[] = $type === 'text' ? $label . ' now reads "' . $show($new, $type) . '"' : $label . ': ' . $show($old, $type) . ' → ' . $show($new, $type);
        }
    }
    if (!$lines && $note === '') {
        return false;
    }
    notify_borrower($borrowerId, 'item_updated', 'Updated: ' . $ref,
        'The card owner updated ' . $ref . '.' . ($lines ? ' ' . implode('; ', $lines) . '.' : '') . ($note !== '' ? ' ' . $note : ''), $entityType, $entityId);

    return true;
}

/** What a borrower sees of an obligation, for notify_borrower_changes(). */
const OBLIGATION_NOTICE_FIELDS = [
    'original_amount'  => ['Amount', 'money'],
    'agreed_rate'      => ['Agreed rate (% per month)', 'number'],
    'agreed_interest'  => ['Interest', 'money'],
    'agreed_fees'      => ['Fees', 'money'],
    'total_amount_due' => ['Total due', 'money'],
    'obligation_date'  => ['Date', 'date'],
    'due_date'         => ['Due date', 'date'],
    'description'      => ['Description', 'text'],
    'status'           => ['Status', 'status'],
];

/** What a borrower sees of a card purchase assigned to them. */
const PURCHASE_NOTICE_FIELDS = [
    'amount'           => ['Amount', 'money'],
    'transaction_date' => ['Date', 'date'],
    'payment_type'     => ['Type', 'status'],
    'status'           => ['Status', 'status'],
];

/**
 * Notify the sign-in account linked to a borrower (if any).
 */
function notify_borrower(string $borrowerId, string $type, string $title, string $message, ?string $entityType = null, ?string $entityId = null): void
{
    $userId = db_value('SELECT user_id FROM borrower_user_accounts WHERE borrower_id = :b AND deactivated_at IS NULL', [':b' => $borrowerId]);
    if ($userId) {
        notify_user($userId, $type, $title, $message, $entityType, $entityId);
    }
}

/**
 * Balance summary shown on the portal home page.
 */
function portal_balance_summary(string $borrowerId): array
{
    return db_row(
        "SELECT COALESCE(SUM(total_amount_due), 0) AS total_due,
                COALESCE(SUM(total_paid), 0) AS total_paid,
                COALESCE(SUM(outstanding_amount), 0) AS outstanding,
                COALESCE(SUM(CASE WHEN outstanding_amount > 0 AND due_date < CURDATE() THEN outstanding_amount ELSE 0 END), 0) AS overdue,
                COALESCE(SUM(outstanding_amount > 0), 0) AS open_count,
                (SELECT COALESCE(SUM(ap.remaining_amount), 0) FROM applied_penalties ap JOIN borrower_obligations o2 ON o2.id = ap.obligation_id
                  WHERE o2.borrower_id = :b2 AND o2.status NOT IN ('cancelled', 'written_off')) AS penalties
           FROM borrower_obligations
          WHERE borrower_id = :b AND status NOT IN ('cancelled', 'written_off')",
        [':b' => $borrowerId, ':b2' => $borrowerId]
    );
}

/**
 * Everything a borrower owes the owner, one row per record, newest problems first:
 * obligations (cash loan shares, cash advances given, agreed amounts) and card
 * purchases made on the owner's card (straight or installment). A purchase is
 * listed once: as the transaction, or - for older ones tracked as an obligation -
 * as that obligation. $openOnly drops what is fully paid.
 *
 * @return array<int, array{kind: string, type: string, ref: string, title: string, date: ?string, link: string,
 *                          amount: float, paid: float, outstanding: float, penalties: float, total: float,
 *                          due_now: float, due_date: ?string, installment: ?string, overdue: bool, status: string}>
 */
function portal_all_items(string $borrowerId, bool $openOnly = true): array
{
    $today = date('Y-m-d');
    $items = [];
    $obligations = db_all(
        "SELECT o.*, COALESCE(pen.penalties_unpaid, 0) AS penalties_unpaid, t.payment_type, t.transaction_number, t.notes AS txn_notes, m.merchant_name
           FROM borrower_obligations o " . penalty_totals_join() . "
           LEFT JOIN transactions t ON t.id = o.transaction_id
           LEFT JOIN merchants m ON m.id = t.merchant_id
          WHERE o.borrower_id = :b AND o.status NOT IN ('cancelled', 'written_off')",
        [':b' => $borrowerId]
    );
    foreach ($obligations as $o) {
        $outstanding = (float) $o['outstanding_amount'];
        $penalties = (float) $o['penalties_unpaid'];
        if ($openOnly && $outstanding + $penalties <= 0.004) {
            continue;
        }
        $due = borrowed_obligation_due_now($o, $today, round($outstanding + $penalties, 2), $penalties);
        $items[] = [
            'kind' => 'obligation', 'id' => $o['id'],
            'type' => match (true) {
                $o['cash_loan_allocation_id'] !== null => 'Cash loan',
                $o['payment_type'] === 'cash_advance' => 'Cash advance',
                $o['payment_type'] === 'installment' => 'Installment purchase',
                $o['payment_type'] === 'cash_loan' => 'Cash loan',
                $o['transaction_id'] !== null => 'Card purchase',
                default => 'Amount owed',
            },
            'ref' => $o['obligation_number'], 'title' => $o['description'] ?: ($o['merchant_name'] ?: ($o['transaction_number'] ?: ($o['notes'] ? excerpt($o['notes'], 50) : 'Amount owed'))),
            'date' => $o['obligation_date'], 'link' => url('portal/obligation.php', ['id' => $o['id']]),
            'amount' => (float) $o['total_amount_due'], 'paid' => (float) $o['total_paid'], 'outstanding' => $outstanding, 'penalties' => $penalties,
            'total' => round($outstanding + $penalties, 2), 'due_now' => $outstanding + $penalties > 0.004 ? $due['due_now'] : 0.0,
            'due_month' => $outstanding + $penalties > 0.004 ? $due['due_month'] : 0.0,
            'due_date' => $due['current']['due']['date'] ?? $o['due_date'],
            'installment' => $due['current'] !== null ? 'Installment ' . $due['current']['n'] . ' of ' . $due['installments'] : null,
            'overdue' => (float) $o['overdue_amount'] > 0.004 || ($o['due_date'] && $o['due_date'] < $today && $outstanding > 0.004),
            'status' => $o['status'],
        ];
    }

    $purchases = borrowed_with_ledgers(db_all(
        borrowed_select_sql() . " WHERE t.borrower_id = :b AND t.status NOT IN ('cancelled', 'refunded') ORDER BY t.transaction_date DESC",
        [':b' => $borrowerId]
    ));
    foreach ($purchases as $p) {
        $l = $p['ledger'];
        if ($l['mode'] !== 'transaction' || ($openOnly && $l['outstanding'] <= 0.004)) {
            continue;   // older purchases tracked as an obligation are listed above
        }
        $items[] = [
            'kind' => 'purchase', 'id' => $p['id'],
            'type' => match ($p['payment_type']) {
                'installment' => 'Installment purchase',
                'cash_advance' => 'Cash advance',
                'cash_loan' => 'Cash loan',
                default => 'Card purchase',
            },
            'ref' => $p['transaction_number'],
            'title' => ($p['merchant_name'] ?: ($p['notes'] ? excerpt($p['notes'], 50) : 'Purchase')) . ' · ' . $p['issuer'] . ' •••• ' . $p['last_four'],
            'date' => $p['transaction_date'], 'link' => url('pages/borrowed/view.php', ['id' => $p['id']]),
            'amount' => $l['responsible'], 'paid' => $l['repaid'], 'outstanding' => $l['remaining'], 'penalties' => $l['penalty_unpaid'],
            'total' => $l['outstanding'], 'due_now' => $l['status'] === 'repaid' ? 0.0 : $l['due_now'],
            'due_month' => $l['status'] === 'repaid' ? 0.0 : ($l['due_month'] ?? 0.0),
            'due_date' => $l['next_due']['date'] ?? null, 'installment' => borrowed_installment_text($l),
            'overdue' => $l['status'] === 'overdue', 'status' => $l['status'],
        ];
    }

    // Overdue first, then by the next due date (unknown dates last), then newest.
    usort($items, static fn ($a, $b) => [$b['overdue'], $a['due_date'] === null, $a['due_date'], $b['date']] <=> [$a['overdue'], $b['due_date'] === null, $b['due_date'], $a['date']]);

    return $items;
}

/** Totals over portal_all_items(). */
function portal_items_totals(array $items): array
{
    $sum = ['amount' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0, 'penalties' => 0.0, 'total' => 0.0, 'due_now' => 0.0, 'due_month' => 0.0, 'overdue' => 0.0, 'next' => null];
    foreach ($items as $i) {
        foreach (['amount', 'paid', 'outstanding', 'penalties', 'total', 'due_now', 'due_month'] as $k) {
            $sum[$k] += $i[$k];
        }
        if ($i['overdue']) {
            $sum['overdue'] += $i['total'];
        }
        if ($i['total'] > 0.004 && $i['due_date'] !== null && $i['due_date'] >= date('Y-m-d') && ($sum['next'] === null || $i['due_date'] < $sum['next']['due_date'])) {
            $sum['next'] = $i;
        }
    }

    return array_map(static fn ($v) => is_float($v) ? round($v, 2) : $v, $sum);
}

/** Status label of an item row (obligation statuses and card purchase statuses). */
function portal_item_status(array $item): string
{
    return $item['kind'] === 'purchase' ? borrowed_status_badge($item['status']) : badge($item['status']);
}
