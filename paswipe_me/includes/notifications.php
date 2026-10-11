<?php
/**
 * The signed-in user's own notification inbox (owner, admin and staff).
 *
 * Each notification names the record it is about (entity_type / entity_id).
 * Here that becomes a kind ("Payment", "Card repayment", "Authorization
 * request" ...) and, when opened, a modal with the record and - when the
 * signed-in user may decide it - the decision itself. Opening one marks it
 * read. Only notifications addressed to the signed-in user are ever shown;
 * every decision is posted to the module's own handler, which checks the
 * role, ownership and status again on the server.
 */

defined('APP_ROOT') or exit('No direct access.');

/** Kinds of notifications: [label, icon], by entity type. */
const NOTIFICATION_KINDS = [
    'borrower_payment'      => ['Payment', 'receipt'],
    'transaction'           => ['Card repayment', 'credit-card'],
    'borrowed_purchase'     => ['Card purchase', 'credit-card'],
    'authorization_request' => ['Authorization request', 'shield'],
    'obligation'            => ['Obligation', 'file-text'],
    'demand_letter'         => ['Notice', 'mail-alert'],
    'agreement'             => ['Agreement', 'file-sign'],
    'payment_receipt'       => ['Receipt', 'file-text'],
];

/** Full pages of the records, for the "Open full record" link (admin layout). */
const NOTIFICATION_PAGES = [
    'borrower_payment'      => 'pages/borrower_payments/view.php',
    'transaction'           => 'pages/transactions/view.php',
    'borrowed_purchase'     => 'pages/borrowed/view.php',
    'authorization_request' => 'pages/authorizations/view.php',
    'obligation'            => 'pages/obligations/view.php',
    'demand_letter'         => 'pages/demand_letters/view.php',
    'agreement'             => 'pages/agreements/view.php',
    'payment_receipt'       => 'pages/staff/receipt.php',
];

/** Pages a staff member may open (the rest are the owner's). */
const NOTIFICATION_STAFF_PAGES = ['authorization_request', 'transaction', 'payment_receipt'];

/** [label, icon] for a notification. */
function notification_kind(array $n): array
{
    return NOTIFICATION_KINDS[$n['entity_type'] ?? ''] ?? [humanize((string) ($n['notification_type'] ?: 'message')), 'bell'];
}

function notification_kind_chip(array $n): string
{
    [$label, $iconName] = notification_kind($n);

    return '<span class="notice-kind notice-kind--' . e(preg_replace('/[^a-z_]/', '', (string) $n['entity_type']) ?: 'other') . '">'
        . icon($iconName, 'icon icon--sm') . ' ' . e($label) . '</span>';
}

/** Where the full record is, or null when this user has no page for it. */
function notification_record_url(array $n, array $user): ?string
{
    $type = (string) $n['entity_type'];
    if (!isset(NOTIFICATION_PAGES[$type]) || !is_valid_uuid((string) $n['entity_id'])) {
        return null;
    }
    if (!user_is_admin($user) && !in_array($type, NOTIFICATION_STAFF_PAGES, true)) {
        return null;
    }

    return url(NOTIFICATION_PAGES[$type], ['id' => $n['entity_id']]);
}

/** WHERE for "shown in my inbox": in-app, delivered to me. */
const NOTIFICATION_INBOX_SQL = "n.user_id = :me AND n.channel = 'in_app' AND n.status NOT IN ('cancelled', 'failed', 'pending')";

/** One of my own notifications. */
function notification_mine(string $id, string $userId): ?array
{
    return is_valid_uuid($id)
        ? db_row('SELECT n.* FROM notifications n WHERE n.id = :id AND ' . NOTIFICATION_INBOX_SQL, [':id' => $id, ':me' => $userId])
        : null;
}

function notification_mark_read(array $n): void
{
    if ($n['read_at'] !== null) {
        return;
    }
    db_update('notifications', ['status' => 'read', 'read_at' => date('Y-m-d H:i:s')], $n['id']);
    db_insert('notification_logs', ['id' => new_uuid(), 'notification_id' => $n['id'], 'channel' => $n['channel'], 'status' => 'read']);
}

/** Mark all my notifications read. Returns how many. */
function notification_mark_all_read(string $userId): int
{
    return db_exec(
        "UPDATE notifications n SET n.read_at = NOW(), n.status = 'read' WHERE n.read_at IS NULL AND " . NOTIFICATION_INBOX_SQL,
        [':me' => $userId]
    );
}

