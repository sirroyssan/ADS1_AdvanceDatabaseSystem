-- PA-KASKAS upgrade: automatic statements and SOA reconciliation.
-- The application applies this automatically on first page load
-- (includes/statement_engine.php, statement_schema_upgrade); this file is the
-- same change for running by hand. Every statement is safe to run twice.

-- Card billing rules. billing_cycle_day (statement cutoff day) and
-- payment_due_day already exist and are reused.
ALTER TABLE credit_cards
    ADD COLUMN IF NOT EXISTS due_date_rule ENUM('day_of_month', 'days_after_cutoff') NULL AFTER payment_due_day,
    ADD COLUMN IF NOT EXISTS due_days_after_cutoff SMALLINT NULL AFTER due_date_rule,
    ADD COLUMN IF NOT EXISTS minimum_due_percent DECIMAL(7,4) NULL AFTER due_days_after_cutoff,
    ADD COLUMN IF NOT EXISTS minimum_due_floor DECIMAL(15,2) NULL AFTER minimum_due_percent;

-- Generated statements: their period, where they came from, the two charge
-- groups the statement had no column for, and whether the due date / minimum
-- due come from the card's rule, were set by hand, or still need confirming.
ALTER TABLE credit_card_statements
    ADD COLUMN IF NOT EXISTS period_start_date DATE NULL AFTER statement_number,
    ADD COLUMN IF NOT EXISTS source ENUM('manual', 'generated') NOT NULL DEFAULT 'manual' AFTER period_start_date,
    ADD COLUMN IF NOT EXISTS cash_advances DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER purchases,
    ADD COLUMN IF NOT EXISTS installment_charges DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER cash_advances,
    ADD COLUMN IF NOT EXISTS due_date_status ENUM('rule', 'manual', 'unconfirmed') NOT NULL DEFAULT 'manual' AFTER due_date,
    ADD COLUMN IF NOT EXISTS minimum_due_status ENUM('rule', 'manual', 'unconfirmed') NOT NULL DEFAULT 'manual' AFTER minimum_amount_due,
    ADD COLUMN IF NOT EXISTS adjustment_reason VARCHAR(255) NULL AFTER adjustments;

-- What the bank's Statement of Account said, with a snapshot of what the
-- system calculated at that moment. Rows are never updated: each save is a
-- new row, so earlier SOA figures and results stay as history.
CREATE TABLE IF NOT EXISTS statement_reconciliations (
    id CHAR(36) NOT NULL PRIMARY KEY,
    statement_id CHAR(36) NOT NULL,
    bank_statement_balance DECIMAL(15,2) NOT NULL,
    bank_minimum_due DECIMAL(15,2) NULL,
    bank_previous_balance DECIMAL(15,2) NULL,
    bank_payments_credits DECIMAL(15,2) NULL,
    system_statement_balance DECIMAL(15,2) NOT NULL,
    system_minimum_due DECIMAL(15,2) NULL,
    system_previous_balance DECIMAL(15,2) NOT NULL,
    system_payments_credits DECIMAL(15,2) NOT NULL,
    difference DECIMAL(15,2) NOT NULL,
    status ENUM('balanced', 'difference') NOT NULL,
    notes TEXT NULL,
    created_by CHAR(36) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_reconciliation_statement (statement_id, created_at),
    CONSTRAINT fk_reconciliation_statement FOREIGN KEY (statement_id) REFERENCES credit_card_statements(id) ON DELETE CASCADE,
    CONSTRAINT fk_reconciliation_user FOREIGN KEY (created_by) REFERENCES users(id)
);
