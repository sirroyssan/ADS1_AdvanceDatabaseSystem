-- PA-KASKAS: staff accounts, authorization requests and acknowledgement receipts.
-- Applied automatically on first page load (includes/staff.php: staff_schema_upgrade()).
-- Nothing existing is dropped or rewritten; only a role value, new tables and one column are added.

-- 1. A new role value (existing roles kept).
ALTER TABLE user_roles MODIFY role ENUM('owner','admin','borrower','staff') NOT NULL;

-- 2. Which card owner a staff member works for (one owner per staff account).
CREATE TABLE IF NOT EXISTS staff_members (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    owner_user_id CHAR(36) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by CHAR(36) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deactivated_at DATETIME NULL,
    UNIQUE KEY uq_staff_user (user_id),
    KEY idx_staff_owner (owner_user_id),
    CONSTRAINT fk_staff_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_staff_owner FOREIGN KEY (owner_user_id) REFERENCES users (id)
);

-- 3. Edit / delete requests on confirmed records and their decision (audit trail).
CREATE TABLE IF NOT EXISTS authorization_requests (
    id CHAR(36) NOT NULL PRIMARY KEY,
    request_number VARCHAR(30) NOT NULL,          -- AUTH-YYYYMMDD-####
    owner_user_id CHAR(36) NOT NULL,              -- owner of the record = the only one who may approve
    record_type VARCHAR(30) NOT NULL,             -- transaction | borrower_payment
    record_id CHAR(36) NOT NULL,
    record_ref VARCHAR(80) NULL,
    action VARCHAR(10) NOT NULL,                  -- edit | delete (void)
    reason TEXT NOT NULL,
    original_values LONGTEXT NULL,                -- JSON snapshot when requested
    proposed_values LONGTEXT NULL,                -- JSON of the corrected values (edit)
    status VARCHAR(20) NOT NULL DEFAULT 'pending',-- pending | approved | rejected | cancelled | failed
    requested_by CHAR(36) NOT NULL,
    decided_by CHAR(36) NULL,
    approval_method VARCHAR(20) NULL,             -- session (owner's own account) | credentials (owner signed in at the request)
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
);

-- 4. Acknowledgement receipts (one per payment received in person) and the payments on each.
CREATE TABLE IF NOT EXISTS payment_receipts (
    id CHAR(36) NOT NULL PRIMARY KEY,
    receipt_number VARCHAR(30) NOT NULL,          -- AR-YYYYMMDD-####
    owner_user_id CHAR(36) NOT NULL,
    borrower_id CHAR(36) NOT NULL,
    received_by CHAR(36) NOT NULL,
    received_at DATETIME NOT NULL,
    payment_date DATE NOT NULL,
    amount_tendered DECIMAL(15,2) NOT NULL,
    previous_balance DECIMAL(15,2) NOT NULL,
    remaining_balance DECIMAL(15,2) NOT NULL,
    payment_method_id CHAR(36) NULL,
    method_label VARCHAR(255) NULL,
    reference_number VARCHAR(150) NULL,
    remarks TEXT NULL,
    line_items LONGTEXT NOT NULL,                 -- JSON: each item's balance before / paid / after
    status VARCHAR(10) NOT NULL DEFAULT 'issued', -- issued | void
    void_reason TEXT NULL,
    amended_note TEXT NULL,                       -- corrections / voids of some of its payments
    voided_at DATETIME NULL,
    voided_by CHAR(36) NULL,
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
);

CREATE TABLE IF NOT EXISTS payment_receipt_items (
    id CHAR(36) NOT NULL PRIMARY KEY,
    receipt_id CHAR(36) NOT NULL,
    borrower_payment_id CHAR(36) NOT NULL,
    UNIQUE KEY uq_receipt_payment (borrower_payment_id),   -- a payment is on one receipt only
    KEY idx_receipt_items (receipt_id),
    CONSTRAINT fk_receipt_item_receipt FOREIGN KEY (receipt_id) REFERENCES payment_receipts (id),
    CONSTRAINT fk_receipt_item_payment FOREIGN KEY (borrower_payment_id) REFERENCES borrower_payments (id)
);
