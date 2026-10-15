-- PA-KASKAS upgrade 4: the "Previous balance" line printed on the bank's SOA
-- (optional). With it, comparing the SOA with the records shows only what is
-- missing from this period (fees, interest, payments), not the carried balance.
ALTER TABLE credit_card_statements
    ADD COLUMN IF NOT EXISTS soa_previous_balance DECIMAL(15,2) NULL AFTER total_amount_due;
