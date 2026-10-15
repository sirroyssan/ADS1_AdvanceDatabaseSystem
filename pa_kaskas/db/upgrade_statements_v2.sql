-- PA-KASKAS upgrade 2 for statements: the statement module now shows each
-- card's billing periods from the existing records and keeps only the SOA
-- figures you enter from the bank (no generated statements).
-- Applied automatically on first page load (includes/statement_engine.php,
-- statement_schema_upgrade_v2), which also removes the statements the earlier
-- version generated without any input from you (see README).

-- Optional notes on a recorded SOA.
ALTER TABLE credit_card_statements
    ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER minimum_due_status;

-- Range lookups "records of this card between two dates".
CREATE INDEX IF NOT EXISTS idx_transactions_card_date ON transactions (credit_card_id, transaction_date);
CREATE INDEX IF NOT EXISTS idx_bank_charges_card_date ON bank_charges (credit_card_id, charge_date);
CREATE INDEX IF NOT EXISTS idx_cc_payments_card_date ON credit_card_payments (credit_card_id, payment_date);
CREATE INDEX IF NOT EXISTS idx_cash_advances_card_date ON cash_advances (credit_card_id, advance_date);

-- One SOA per card and billing period (cutoff date). Added after the cleanup;
-- skipped with a log entry if older hand-entered statements still share a cutoff.
CREATE UNIQUE INDEX IF NOT EXISTS uq_statement_card_cutoff ON credit_card_statements (credit_card_id, cutoff_date);
