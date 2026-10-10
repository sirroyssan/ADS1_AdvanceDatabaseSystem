-- PA-KASKAS upgrade 3 for statements: upcoming statements are prepared
-- automatically (current and next billing period of each card) from the
-- existing records; the bank's SOA is still recorded by hand into the same row.
-- Applied automatically on first page load (includes/statement_engine.php).

-- A statement row is either "prepared" (soa_recorded_at IS NULL: the period and
-- its expected items, no bank figures) or a recorded SOA (soa_recorded_at set).
-- The existing UNIQUE (credit_card_id, cutoff_date) keeps one row per card and period.
ALTER TABLE credit_card_statements
    ADD COLUMN IF NOT EXISTS soa_recorded_at DATETIME NULL AFTER notes,
    ADD COLUMN IF NOT EXISTS prepared_at DATETIME NULL AFTER soa_recorded_at,
    ADD COLUMN IF NOT EXISTS prepared_signature CHAR(32) NULL AFTER prepared_at;

-- Which statement each record belongs to. One row per source record (an
-- installment schedule row, a cash loan repayment, a purchase, a bank charge,
-- a cash advance or its fee, a payment): UNIQUE (source_type, source_id) means
-- a scheduled installment can never be on two statements. Nothing is copied
-- from the source records except the amount and date at preparation time.
CREATE TABLE IF NOT EXISTS statement_items (
    id CHAR(36) NOT NULL PRIMARY KEY,
    statement_id CHAR(36) NOT NULL,
    credit_card_id CHAR(36) NOT NULL,
    source_type VARCHAR(30) NOT NULL,
    source_id CHAR(36) NOT NULL,
    item_date DATE NOT NULL,
    part VARCHAR(30) NOT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    installment_number INT NULL,
    installment_count INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_statement_item_source (source_type, source_id),
    KEY idx_statement_items_statement (statement_id),
    CONSTRAINT fk_statement_item_statement FOREIGN KEY (statement_id) REFERENCES credit_card_statements(id) ON DELETE CASCADE,
    CONSTRAINT fk_statement_item_card FOREIGN KEY (credit_card_id) REFERENCES credit_cards(id)
);

CREATE INDEX IF NOT EXISTS idx_installment_schedules_due ON installment_schedules (due_date, installment_plan_id);
