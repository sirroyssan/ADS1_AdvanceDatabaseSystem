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
 * Payment methods a borrower can pay with: their own accounts and the
 * owner's receiving accounts (active only, plus the current one).
 *
 * @return array<string, string>
 */
function portal_payment_method_options(string $borrowerId, ?string $current = null): array
{
    $rows = db_all(
        'SELECT id, display_name, method_type, masked_account_number, borrower_id FROM payment_methods
          WHERE (borrower_id = :b OR (borrower_id IS NULL AND owner_user_id IS NOT NULL))
            AND (is_active = 1 OR id = :c)
          ORDER BY borrower_id IS NULL, display_name',
        [':b' => $borrowerId, ':c' => $current ?? '']
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
 * Notify every active owner/administrator.
 */
function notify_admins(string $type, string $title, string $message, ?string $entityType = null, ?string $entityId = null): void
{
    $admins = db_all(
        "SELECT DISTINCT u.id FROM users u JOIN user_roles r ON r.user_id = u.id
          WHERE r.role IN (" . sql_in_list(ADMIN_ROLES) . ") AND u.status = 'active'"
    );
    foreach ($admins as $admin) {
        notify_user($admin['id'], $type, $title, $message, $entityType, $entityId);
    }
}

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
