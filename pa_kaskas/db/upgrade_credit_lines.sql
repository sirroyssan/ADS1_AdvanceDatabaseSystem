-- PA-KASKAS: shared credit lines (several cards of one bank sharing one limit).
-- Additive and safe to run once; the app also applies it by itself
-- (credit_line_schema_upgrade() in includes/credit_lines.php).
-- Existing cards are not changed: credit_line_id and sub_limit stay NULL (own limit).
CREATE TABLE IF NOT EXISTS credit_lines (
    id CHAR(36) NOT NULL PRIMARY KEY,
    owner_user_id CHAR(36) NOT NULL,
    name VARCHAR(150) NOT NULL,
    issuer VARCHAR(150) NULL,
    credit_limit DECIMAL(15,2) NOT NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_credit_lines_owner (owner_user_id),
    CONSTRAINT fk_credit_lines_owner FOREIGN KEY (owner_user_id) REFERENCES users (id),
    CONSTRAINT chk_credit_lines_limit CHECK (credit_limit >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE credit_cards
    ADD COLUMN IF NOT EXISTS credit_line_id CHAR(36) NULL AFTER credit_limit,   -- NULL = own limit
    ADD COLUMN IF NOT EXISTS sub_limit DECIMAL(15,2) NULL AFTER credit_line_id; -- optional cap inside the line
ALTER TABLE credit_cards
    ADD KEY idx_credit_cards_line (credit_line_id),
    ADD CONSTRAINT fk_credit_cards_line FOREIGN KEY (credit_line_id) REFERENCES credit_lines (id);
