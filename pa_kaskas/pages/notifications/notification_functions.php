<?php
/**
 * Notifications module (tables: notifications, notification_logs).
 *
 * In-app notifications are delivered immediately (shown in the header bell).
 * Email, SMS and push are stored as "pending": the schema records deliveries,
 * but this XAMPP application has no email/SMS/push provider configured
 * (see README: database and system limitations).
 * Notifications are messages already sent, so they are not edited or deleted;
 * a pending one can be cancelled.
 *
 * Scope: a user sees and acts on notifications addressed to themselves or to
 * the sign-in accounts of their own borrowers - never anyone else's.
 */

defined('APP_ROOT') or exit('No direct access.');

/**
 * WHERE condition: notification $alias is addressed to the user or one of their borrowers.
 * (These pages run under require_admin(), where owner_id() is the signed-in user.)
 */
function notification_scope(string $alias): string
{
    $a = preg_replace('/[^a-z0-9_]/i', '', $alias);

    return "($a.user_id = " . owner_sql() . " OR $a.user_id IN " . owned_borrower_users_sql() . ')';
}

/** Recipients the user may notify: themselves and their borrowers' sign-in accounts. */
function notification_recipient_options(): array
{
    return db_options(
        "SELECT id, CONCAT(COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''), username, email), ' <', email, '>') AS label
           FROM users u WHERE u.id = :me OR u.id IN " . owned_borrower_users_sql() . '
          ORDER BY label',
        [':me' => owner_id()]
    );
}

function notification_fields(): array
{
    return [
        'user_id'           => ['label' => 'Recipient', 'type' => 'select', 'options' => notification_recipient_options(), 'required' => true, 'empty_label' => 'Select user'],
        'channel'           => ['label' => 'Channel', 'type' => 'select', 'options' => ['in_app' => 'In-app (delivered now)', 'email' => 'Email (queued)', 'sms' => 'SMS (queued)', 'push' => 'Push (queued)'], 'required' => true, 'initial' => 'in_app'],
        'notification_type' => ['label' => 'Type', 'type' => 'text', 'max' => 100, 'required' => true, 'placeholder' => 'e.g. payment_reminder, announcement'],
        'title'             => ['label' => 'Title', 'type' => 'text', 'max' => 255, 'required' => true, 'full' => true],
        'message'           => ['label' => 'Message', 'type' => 'textarea', 'rows' => 5, 'required' => true],
        'scheduled_at'      => ['label' => 'Schedule for', 'type' => 'datetime', 'help' => 'Optional. Leave blank to send now.'],
        'entity_type'       => ['label' => 'Related record type', 'type' => 'text', 'max' => 100, 'placeholder' => 'e.g. obligation'],
        'entity_id'         => ['label' => 'Related record id', 'type' => 'text', 'max' => 36, 'pattern' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', 'pattern_message' => 'Enter a record id (UUID) or leave blank.'],
    ];
}

function notification_find(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    return db_row(
        "SELECT n.*, u.email, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, u.email) AS recipient_name
           FROM notifications n JOIN users u ON u.id = n.user_id
          WHERE n.id = :id AND " . notification_scope('n'),
        [':id' => $id]
    );
}
