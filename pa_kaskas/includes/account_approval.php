<?php
/**
 * Accounts and password resets approved by a system administrator.
 *
 *   - Self-registered accounts (auth/register.php) start as users.status =
 *     'pending' and cannot sign in until an administrator approves them
 *     (status 'active') or rejects them (status 'inactive' - accounts are
 *     never deleted). No schema change: the status already exists.
 *   - "Forgot password?" (auth/forgot_password.php) does not change the
 *     password: it files a request with the new password's hash in
 *     password_reset_requests (new table, created on first use - same SQL as
 *     db/upgrade_password_resets.sql). Only when an administrator approves it
 *     does the password change; the stored hash is then cleared. A newer
 *     request replaces an older pending one. Nobody decides their own request.
 *
 * Administrators are told through their notification inbox; the sidebar
 * shows how many are waiting (System → Approvals).
 */

defined('APP_ROOT') or exit('No direct access.');

const PASSWORD_RESET_MARKER = 'storage/upgrade_password_resets.done';
const PASSWORD_RESET_LIMIT_PER_HOUR = 5;   // per IP address
const PASSWORD_RESET_STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

/** Create the request table on first use (works before any administrator signs in). */
function password_reset_upgrade(): void
{
    static $done = false;
    if ($done || marker_exists(PASSWORD_RESET_MARKER)) {
        $done = true;
        return;
    }
    // Same character set / collation as users.id, so the foreign keys fit any import.
    $col = db_row(
        "SELECT CHARACTER_SET_NAME AS cs, COLLATION_NAME AS co FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id'"
    );
    $like = $col && $col['cs'] && $col['co'] ? ' CHARACTER SET ' . $col['cs'] . ' COLLATE ' . $col['co'] : '';
    $table = db_row(
        "SELECT t.ENGINE AS engine, t.TABLE_COLLATION AS co, c.CHARACTER_SET_NAME AS cs
           FROM information_schema.TABLES t JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY c ON c.COLLATION_NAME = t.TABLE_COLLATION
          WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = 'users'"
    );
    $options = $table ? ' ENGINE=' . $table['engine'] . ' DEFAULT CHARSET=' . $table['cs'] . ' COLLATE=' . $table['co'] : ' ENGINE=InnoDB';
    db()->exec(
        "CREATE TABLE IF NOT EXISTS password_reset_requests (
            id CHAR(36)$like NOT NULL PRIMARY KEY,
            request_number VARCHAR(30) NOT NULL,
            user_id CHAR(36)$like NOT NULL,
            new_password_hash VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            requester_ip VARCHAR(45) NULL,
            decided_by CHAR(36)$like NULL,
            decided_at DATETIME NULL,
            decision_note TEXT NULL,
            UNIQUE KEY uq_password_reset_number (request_number),
            KEY idx_password_reset_user (user_id, status),
            KEY idx_password_reset_status (status, requested_at),
            CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users (id),
            CONSTRAINT fk_password_reset_decider FOREIGN KEY (decided_by) REFERENCES users (id),
            CONSTRAINT chk_password_reset_status CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled'))
        )$options"
    );
    marker_write(PASSWORD_RESET_MARKER);
    $done = true;
}

/** Active system administrators (the admin role). */
function system_admin_ids(): array
{
    return array_column(db_all(
        "SELECT DISTINCT u.id FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'admin' WHERE u.status = 'active'"
    ), 'id');
}

/** Tell every active administrator (except $except) in their inbox. */
function notify_system_admins(string $type, string $title, string $message, ?string $except = null): void
{
    foreach (system_admin_ids() as $adminId) {
        if ($adminId !== $except) {
            notify_user($adminId, $type, $title, $message, 'account_approval', null);
        }
    }
}

/** Accounts waiting for approval. */
function account_pending_count(): int
{
    return (int) db_value("SELECT COUNT(*) FROM users WHERE status = 'pending'");
}

function password_reset_pending_count(): int
{
    password_reset_upgrade();

    return (int) db_value("SELECT COUNT(*) FROM password_reset_requests WHERE status = 'pending'");
}

/** Everything waiting for an administrator (sidebar badge). */
function approvals_waiting(): int
{
    try {
        return account_pending_count() + password_reset_pending_count();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * File a password reset for the account with this username or email. Says
 * nothing about whether the account exists (the page always shows the same
 * message). Returns true when a request was filed.
 */
function password_reset_request(string $identifier, string $newPassword): bool
{
    password_reset_upgrade();
    $user = db_row(
        'SELECT id, email, username, first_name, last_name, status FROM users
          WHERE email = :e OR username = :u ORDER BY (email = :o) DESC LIMIT 1',
        [':e' => $identifier, ':u' => $identifier, ':o' => $identifier]
    );
    if (!$user || $user['status'] !== 'active') {
        // Unknown, waiting for approval, suspended, locked or inactive by an administrator: nothing to reset (same answer, same work).
        password_hash($newPassword, PASSWORD_DEFAULT);
        log_activity('password_reset_request', 'user', $user['id'] ?? null, 'Password reset not filed (' . ($user ? 'status ' . $user['status'] : 'unknown account') . ')', null, null, $user['id'] ?? SYSTEM_ACTOR);
        return false;
    }
    $id = new_uuid();
    $number = db_transaction(static function () use ($id, $user, $newPassword) {
        db_value('SELECT id FROM users WHERE id = :id FOR UPDATE', [':id' => $user['id']]);
        // A newer request replaces the one still waiting.
        db_exec("UPDATE password_reset_requests SET status = 'cancelled', new_password_hash = NULL, decided_at = NOW(),
                        decision_note = 'Replaced by a newer request.' WHERE user_id = :u AND status = 'pending'", [':u' => $user['id']]);
        $number = generate_reference('PWR', 'password_reset_requests', 'request_number');
        db_insert('password_reset_requests', [
            'id' => $id, 'request_number' => $number, 'user_id' => $user['id'],
            'new_password_hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'status' => 'pending',
            'requester_ip' => mb_substr(client_ip(), 0, 45),
        ]);

        return $number;
    });
    log_activity('password_reset_request', 'user', $user['id'], 'Password reset requested (' . $number . ')', null, null, $user['id']);
    notify_system_admins('password_reset', 'Password reset to approve: ' . user_display_label($user),
        user_display_label($user) . ' asked for a new password (' . $number . '). Confirm it is really them before approving it in System → Approvals.');

    return true;
}

/** A user's pending reset whose new password is $password (to explain a failed sign-in). */
function password_reset_waiting_for(string $userId, string $password): bool
{
    try {
        password_reset_upgrade();
        $hash = db_value("SELECT new_password_hash FROM password_reset_requests WHERE user_id = :u AND status = 'pending' ORDER BY requested_at DESC LIMIT 1", [':u' => $userId]);
    } catch (PDOException $e) {
        return false;
    }

    return is_string($hash) && $hash !== '' && password_verify($password, $hash);
}

/** "Juan Dela Cruz (juan@mail.com)". */
function user_display_label(array $u): string
{
    $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));

    return ($name !== '' ? $name . ' ' : '') . '(' . ($u['email'] ?: ($u['username'] ?? '')) . ')';
}

/**
 * Approve or reject a pending password reset. Returns an error, or null.
 * Approving sets the new password and clears the sign-in lockout (too many failed attempts).
 */
function password_reset_decide(string $requestId, string $decision, array $admin, string $note = ''): ?string
{
    password_reset_upgrade();
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        return 'Unknown decision.';
    }

    return db_transaction(static function () use ($requestId, $decision, $admin, $note) {
        $r = db_row('SELECT * FROM password_reset_requests WHERE id = :id FOR UPDATE', [':id' => $requestId]);
        if (!$r) {
            return 'Request not found.';
        }
        if ($r['status'] !== 'pending') {
            return 'This request was already ' . $r['status'] . '.';
        }
        if ($r['user_id'] === $admin['id']) {
            return 'You cannot approve or reject your own password reset - another administrator must decide it.';
        }
        $user = db_row('SELECT id, email, username, first_name, last_name FROM users WHERE id = :id FOR UPDATE', [':id' => $r['user_id']]);
        if ($decision === 'approved') {
            // The account status is not touched: a suspended account stays suspended.
            db_update('users', ['password_hash' => $r['new_password_hash'], 'failed_login_attempts' => 0, 'locked_until' => null], $r['user_id']);
        }
        db_update('password_reset_requests', [
            'status' => $decision, 'new_password_hash' => null, 'decided_by' => $admin['id'], 'decided_at' => date('Y-m-d H:i:s'),
            'decision_note' => $note !== '' ? mb_substr($note, 0, 1000) : null,
        ], $r['id']);
        log_activity($decision === 'approved' ? 'password_reset_approved' : 'password_reset_rejected', 'user', $r['user_id'],
            ($decision === 'approved' ? 'Approved' : 'Rejected') . ' password reset ' . $r['request_number'] . ' of ' . user_display_label($user) . ($note !== '' ? ': ' . $note : ''));
        notify_user($r['user_id'], 'password_reset', $decision === 'approved' ? 'Your new password is active' : 'Password reset not approved',
            $decision === 'approved' ? 'An administrator approved your password reset ' . $r['request_number'] . '. Sign in with your new password.'
                : 'An administrator did not approve your password reset ' . $r['request_number'] . ($note !== '' ? ': ' . $note : '.') . ' Your password was not changed.');

        return null;
    });
}

/** Approve (status active) or reject (status inactive) a self-registered account. Returns an error, or null. */
function account_decide(string $userId, string $decision, array $admin, string $note = ''): ?string
{
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        return 'Unknown decision.';
    }

    return db_transaction(static function () use ($userId, $decision, $admin, $note) {
        $u = db_row('SELECT id, email, username, first_name, last_name, status FROM users WHERE id = :id FOR UPDATE', [':id' => $userId]);
        if (!$u) {
            return 'Account not found.';
        }
        if ($u['status'] !== 'pending') {
            return 'This account is no longer waiting for approval (status: ' . humanize($u['status']) . ').';
        }
        if ($u['id'] === $admin['id']) {
            return 'You cannot approve your own account.';
        }
        $status = $decision === 'approved' ? 'active' : 'inactive';
        db_update('users', ['status' => $status, 'failed_login_attempts' => 0, 'locked_until' => null], $u['id']);
        log_activity($decision === 'approved' ? 'account_approved' : 'account_rejected', 'user', $u['id'],
            ($decision === 'approved' ? 'Approved' : 'Rejected') . ' new account ' . user_display_label($u) . ($note !== '' ? ': ' . $note : ''),
            ['status' => 'pending'], ['status' => $status] + ($note !== '' ? ['reason' => $note] : []));
        if ($decision === 'approved') {
            notify_user($u['id'], 'account_approved', 'Welcome to ' . APP_NAME, 'Your account was approved by an administrator. Start by adding your first credit card.');
        }

        return null;
    });
}
