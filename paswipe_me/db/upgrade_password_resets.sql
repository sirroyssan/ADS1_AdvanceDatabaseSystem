-- PA-KASKAS: password resets approved by a system administrator.
-- "Forgot password?" files a request here with the new password's hash; the
-- password changes only when an administrator approves it (the hash is then
-- cleared). Applied automatically on first use (includes/account_approval.php);
-- this file is for running it by hand. No existing table is changed.
-- New self-registered accounts use the existing users.status 'pending' until approved.
CREATE TABLE IF NOT EXISTS password_reset_requests (
    id CHAR(36) NOT NULL PRIMARY KEY,
    request_number VARCHAR(30) NOT NULL,          -- PWR-YYYYMMDD-####
    user_id CHAR(36) NOT NULL,
    new_password_hash VARCHAR(255) NULL,          -- password_hash() of the new password; NULL once decided
    status VARCHAR(20) NOT NULL DEFAULT 'pending',-- pending | approved | rejected | cancelled
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    requester_ip VARCHAR(45) NULL,
    decided_by CHAR(36) NULL,
    decided_at DATETIME NULL,
    decision_note TEXT NULL,
    UNIQUE KEY uq_password_reset_number (request_number),
    KEY idx_password_reset_user (user_id, status),
    KEY idx_password_reset_status (status, requested_at),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_password_reset_decider FOREIGN KEY (decided_by) REFERENCES users (id),
    CONSTRAINT chk_password_reset_status CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled'))
);
