<?php
/**
 * Staff accounts.
 *
 * A staff member works for one card owner (staff_members.owner_user_id):
 * every owner-scoped query (includes/ownership.php) then shows that owner's
 * records, so staff see exactly the books they keep - never another owner's.
 *
 * Staff may: use the staff dashboard, record transactions, receive borrower
 * payments and print receipts, view cards / installments / due dates / history,
 * and REQUEST a change to a confirmed transaction or payment. They are not in
 * ADMIN_ROLES, so every owner page (edit, delete, override, settings, ...)
 * refuses them on the server. Changes go through authorization_requests and
 * are applied only after the owner approves (includes/authorizations.php).
 *
 * Schema (added once, nothing existing is changed or dropped):
 *   user_roles.role      + 'staff'
 *   staff_members        staff user -> owner they work for
 *   authorization_requests  edit / delete requests, decision and result (audit)
 *   payment_receipts (+ payment_receipt_items)  acknowledgement receipts
 */

defined('APP_ROOT') or exit('No direct access.');

const STAFF_UPGRADE_MARKER = 'storage/upgrade_staff.done';
const STAFF_UPGRADE_V2_MARKER = 'storage/upgrade_staff_v2.done';
/** Roles that may open the staff area: staff and the owner they work for (and admins on their own books). */
const STAFF_AREA_ROLES = ['owner', 'admin', 'staff'];

function staff_schema_upgrade(): void
{
    if (marker_exists(STAFF_UPGRADE_MARKER)) {
        // v2: corrections and voids of a receipt's payments are noted on the receipt.
        if (!marker_exists(STAFF_UPGRADE_V2_MARKER)) {
            db()->exec('ALTER TABLE payment_receipts ADD COLUMN IF NOT EXISTS amended_note TEXT NULL AFTER void_reason');
            marker_write(STAFF_UPGRADE_V2_MARKER);
        }
        return;
    }
    $enum = (string) db_value("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_roles' AND COLUMN_NAME = 'role'");
    if ($enum !== '' && !str_contains($enum, "'staff'")) {
        db()->exec("ALTER TABLE user_roles MODIFY role " . rtrim($enum, ')') . ",'staff') NOT NULL");
    }
    $id = db_row("SELECT CHARACTER_SET_NAME AS cs, COLLATION_NAME AS co FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id'");
    $uuid = 'CHAR(36)' . ($id && $id['cs'] ? ' CHARACTER SET ' . $id['cs'] . ' COLLATE ' . $id['co'] : '');

    db()->exec("CREATE TABLE IF NOT EXISTS staff_members (
        id $uuid NOT NULL PRIMARY KEY,
        user_id $uuid NOT NULL,
        owner_user_id $uuid NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by $uuid NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        deactivated_at DATETIME NULL,
        UNIQUE KEY uq_staff_user (user_id),
        KEY idx_staff_owner (owner_user_id),
        CONSTRAINT fk_staff_user FOREIGN KEY (user_id) REFERENCES users (id),
        CONSTRAINT fk_staff_owner FOREIGN KEY (owner_user_id) REFERENCES users (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS authorization_requests (
        id $uuid NOT NULL PRIMARY KEY,
        request_number VARCHAR(30) NOT NULL,
        owner_user_id $uuid NOT NULL,
        record_type VARCHAR(30) NOT NULL,
        record_id $uuid NOT NULL,
        record_ref VARCHAR(80) NULL,
        action VARCHAR(10) NOT NULL,
        reason TEXT NOT NULL,
        original_values LONGTEXT NULL,
        proposed_values LONGTEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        requested_by $uuid NOT NULL,
        decided_by $uuid NULL,
        approval_method VARCHAR(20) NULL,
        decision_note TEXT NULL,
        result TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        decided_at DATETIME NULL,
        UNIQUE KEY uq_authorization_number (request_number),
        KEY idx_authorization_owner (owner_user_id, status),
        KEY idx_authorization_record (record_type, record_id),
        CONSTRAINT fk_authorization_owner FOREIGN KEY (owner_user_id) REFERENCES users (id),
        CONSTRAINT fk_authorization_requester FOREIGN KEY (requested_by) REFERENCES users (id),
        CONSTRAINT fk_authorization_decider FOREIGN KEY (decided_by) REFERENCES users (id),
        CONSTRAINT chk_authorization_action CHECK (action IN ('edit', 'delete')),
        CONSTRAINT chk_authorization_status CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled', 'failed'))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS payment_receipts (
        id $uuid NOT NULL PRIMARY KEY,
        receipt_number VARCHAR(30) NOT NULL,
        owner_user_id $uuid NOT NULL,
        borrower_id $uuid NOT NULL,
        received_by $uuid NOT NULL,
        received_at DATETIME NOT NULL,
        payment_date DATE NOT NULL,
        amount_tendered DECIMAL(15,2) NOT NULL,
        previous_balance DECIMAL(15,2) NOT NULL,
        remaining_balance DECIMAL(15,2) NOT NULL,
        payment_method_id $uuid NULL,
        method_label VARCHAR(255) NULL,
        reference_number VARCHAR(150) NULL,
        remarks TEXT NULL,
        line_items LONGTEXT NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'issued',
        void_reason TEXT NULL,
        amended_note TEXT NULL,
        voided_at DATETIME NULL,
        voided_by $uuid NULL,
        print_count INT NOT NULL DEFAULT 0,
        last_printed_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_receipt_number (receipt_number),
        KEY idx_receipt_owner (owner_user_id, received_at),
        KEY idx_receipt_borrower (borrower_id),
        CONSTRAINT fk_receipt_owner FOREIGN KEY (owner_user_id) REFERENCES users (id),
        CONSTRAINT fk_receipt_borrower FOREIGN KEY (borrower_id) REFERENCES borrowers (id),
        CONSTRAINT fk_receipt_receiver FOREIGN KEY (received_by) REFERENCES users (id),
        CONSTRAINT chk_receipt_amounts CHECK (amount_tendered > 0 AND previous_balance >= 0 AND remaining_balance >= 0),
        CONSTRAINT chk_receipt_status CHECK (status IN ('issued', 'void'))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS payment_receipt_items (
        id $uuid NOT NULL PRIMARY KEY,
        receipt_id $uuid NOT NULL,
        borrower_payment_id $uuid NOT NULL,
        UNIQUE KEY uq_receipt_payment (borrower_payment_id),
        KEY idx_receipt_items (receipt_id),
        CONSTRAINT fk_receipt_item_receipt FOREIGN KEY (receipt_id) REFERENCES payment_receipts (id),
        CONSTRAINT fk_receipt_item_payment FOREIGN KEY (borrower_payment_id) REFERENCES borrower_payments (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    marker_write(STAFF_UPGRADE_MARKER);
    marker_write(STAFF_UPGRADE_V2_MARKER);
}

/** The owner a staff user works for (active link only), or null. */
function staff_employer(string $userId): ?string
{
    try {
        return db_value('SELECT owner_user_id FROM staff_members WHERE user_id = :u AND is_active = 1', [':u' => $userId]) ?: null;
    } catch (PDOException) {
        return null;   // before the staff upgrade ran
    }
}

function user_is_staff(?array $user): bool
{
    return $user !== null && in_array('staff', $user['roles'], true) && !user_is_admin($user);
}

/**
 * Staff area: staff (linked to an active owner) or an owner / admin on their own books.
 * Returns the signed-in user.
 */
function require_staff_area(): array
{
    $user = require_role(STAFF_AREA_ROLES);
    if (user_is_admin($user)) {
        return require_admin();
    }
    if (staff_employer($user['id']) === null) {
        force_logout('Your staff account is not active. Please contact the card owner.');
    }
    penalty_run_if_due();

    return $user;
}

/** Staff accounts of the signed-in owner. */
function staff_accounts(): array
{
    return db_all(
        "SELECT s.id AS staff_id, s.is_active, s.created_at AS linked_at, s.deactivated_at, u.id, u.username, u.email, u.first_name, u.last_name, u.status, u.last_login_at
           FROM staff_members s JOIN users u ON u.id = s.user_id
          WHERE s.owner_user_id = :o ORDER BY s.is_active DESC, u.first_name, u.last_name",
        [':o' => owner_id()]
    );
}

/** Name of any user (for audit lines and receipts). */
function user_name_of(?string $userId): string
{
    if (!$userId) {
        return '—';
    }
    $u = db_row('SELECT first_name, last_name, username, email FROM users WHERE id = :id', [':id' => $userId]);

    return $u ? (trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['username'] ?: $u['email'])) : '—';
}

/**
 * Card payment due dates for the staff monitor, from the owner's records only:
 *   - every recorded bank SOA still unpaid, or due in the last 31 days
 *     (statement balance, minimum, paid, remaining from credit_card_payments);
 *   - for each active card with a cutoff day, the billing period open today:
 *     its cutoff, and the payment due date from the card's own due-date rule
 *     (amounts are not known until the SOA is recorded - never estimated here).
 * Status: Paid, Partially Paid, Overdue, Due Soon (within STATEMENT_UPCOMING_DAYS) or Upcoming.
 */
function staff_due_dates(?string $cardId = null): array
{
    $today = date('Y-m-d');
    $rows = [];
    $mine = owner_scope('credit_card_statements', 's');
    $soas = db_all(
        'SELECT s.id, s.credit_card_id, s.cutoff_date, s.statement_date, s.due_date, s.total_amount_due, s.minimum_amount_due,
                c.card_nickname, c.issuer, c.last_four, COALESCE(sp.paid, 0) AS paid, ' . statement_remaining_sql() . ' AS remaining
           FROM credit_card_statements s JOIN credit_cards c ON c.id = s.credit_card_id ' . statement_paid_join() . "
          WHERE s.soa_recorded_at IS NOT NULL AND $mine
            AND (" . statement_remaining_sql() . ' > 0.004 OR s.due_date >= CURDATE() - INTERVAL 31 DAY)'
            . ($cardId ? ' AND s.credit_card_id = :c' : '') . ' ORDER BY s.due_date',
        $cardId ? [':c' => $cardId] : []
    );
    $recordedCutoffs = [];
    foreach ($soas as $s) {
        $recordedCutoffs[$s['credit_card_id'] . '|' . $s['cutoff_date']] = true;
        $rows[] = [
            'card' => $s, 'source' => 'soa', 'statement_id' => $s['id'], 'cutoff' => $s['cutoff_date'] ?: $s['statement_date'], 'due' => $s['due_date'],
            'balance' => (float) $s['total_amount_due'], 'minimum' => $s['minimum_amount_due'] !== null ? (float) $s['minimum_amount_due'] : null,
            'paid' => (float) $s['paid'], 'remaining' => (float) $s['remaining'],
        ];
    }

    $cards = db_all(
        "SELECT c.* FROM credit_cards c WHERE c.status = 'active' AND c.billing_cycle_day IS NOT NULL AND " . owner_scope('credit_cards', 'c')
            . ($cardId ? ' AND c.id = :c' : ''),
        $cardId ? [':c' => $cardId] : []
    );
    foreach ($cards as $card) {
        $period = card_period_containing(card_period_context($card), $today);
        if (isset($recordedCutoffs[$card['id'] . '|' . $period['end']]) || $period['recorded']) {
            continue;
        }
        [$due, $known] = card_due_date($card, $period['end']);
        $rows[] = [
            'card' => $card, 'source' => 'open', 'statement_id' => null, 'cutoff' => $period['end'], 'due' => $known ? $due : null,
            'balance' => null, 'minimum' => null, 'paid' => 0.0, 'remaining' => null,
        ];
    }

    foreach ($rows as &$r) {
        $r['days'] = $r['due'] ? (int) round((strtotime($r['due']) - strtotime($today)) / 86400) : null;
        $r['status'] = match (true) {
            $r['source'] === 'soa' && $r['remaining'] <= 0.004 => 'paid',
            $r['due'] !== null && $r['due'] < $today => 'overdue',
            $r['source'] === 'soa' && $r['paid'] > 0.004 => 'partially_paid',
            $r['days'] !== null && $r['days'] <= STATEMENT_UPCOMING_DAYS => 'due_soon',
            default => 'upcoming',
        };
    }
    unset($r);
    usort($rows, static fn ($a, $b) => [$a['status'] === 'paid', $a['due'] === null, $a['due']] <=> [$b['status'] === 'paid', $b['due'] === null, $b['due']]);

    return $rows;
}

function staff_due_badge(string $status): string
{
    return status_badge(DUE_STATUS_LABELS[$status] ?? $status, [
        'Paid' => 'success', 'Partially Paid' => 'info', 'Overdue' => 'danger', 'Due Soon' => 'warning', 'Upcoming' => 'muted',
    ]);
}

const DUE_STATUS_LABELS = ['paid' => 'Paid', 'partially_paid' => 'Partially Paid', 'overdue' => 'Overdue', 'due_soon' => 'Due Soon', 'upcoming' => 'Upcoming'];
