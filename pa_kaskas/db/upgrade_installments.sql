-- PA-KASKAS: installment terms (interest method, fees) and installment rules.
-- Applied automatically on first page load (includes/installments.php:
-- installments_schema_upgrade(), each part only when missing). No data is changed:
-- existing plans keep their amounts (interest_method NULL = amounts entered by hand).

-- Terms used to build each plan's schedule, saved with the plan (a snapshot):
-- changing a rule later never changes an existing plan.
ALTER TABLE installment_plans ADD COLUMN interest_method VARCHAR(30) NULL;      -- none, fixed_amount, add_on_monthly, add_on_total, reducing_balance
ALTER TABLE installment_plans ADD COLUMN interest_rate DECIMAL(8,4) NULL;       -- % (per month for add_on_monthly / reducing_balance)
ALTER TABLE installment_plans ADD COLUMN fee_type VARCHAR(20) NULL;             -- none, fixed, percent
ALTER TABLE installment_plans ADD COLUMN fee_value DECIMAL(15,4) NULL;
ALTER TABLE installment_plans ADD COLUMN fee_billing VARCHAR(20) NULL;          -- spread (every installment) or first (with installment 1)
ALTER TABLE installment_plans ADD COLUMN installment_rule_id CHAR(36) NULL;
ALTER TABLE installment_plans ADD COLUMN terms_snapshot TEXT NULL;              -- the rule's terms as they were when the plan was made (JSON)

-- Installment offers of a bank or of one card.
CREATE TABLE IF NOT EXISTS installment_rules (
    id CHAR(36) NOT NULL PRIMARY KEY,
    owner_user_id CHAR(36) NOT NULL,
    credit_card_id CHAR(36) NULL,              -- one card, or
    issuer VARCHAR(150) NULL,                  -- every card of this bank
    name VARCHAR(150) NOT NULL,
    min_amount DECIMAL(15,2) NULL,
    max_amount DECIMAL(15,2) NULL,
    terms VARCHAR(100) NOT NULL,               -- available terms in months, e.g. "3,6,12,24"
    interest_method VARCHAR(30) NOT NULL,
    interest_rate DECIMAL(8,4) NULL,
    fee_type VARCHAR(20) NOT NULL DEFAULT 'none',
    fee_value DECIMAL(15,4) NULL,
    fee_billing VARCHAR(20) NOT NULL DEFAULT 'spread',
    effective_from DATE NULL,
    effective_until DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_installment_rules_owner (owner_user_id),
    KEY idx_installment_rules_card (credit_card_id),
    CONSTRAINT fk_installment_rules_owner FOREIGN KEY (owner_user_id) REFERENCES users (id),
    CONSTRAINT fk_installment_rules_card FOREIGN KEY (credit_card_id) REFERENCES credit_cards (id),
    CONSTRAINT chk_installment_rules_amounts CHECK (min_amount IS NULL OR max_amount IS NULL OR min_amount <= max_amount)
);

ALTER TABLE installment_plans ADD CONSTRAINT fk_installment_plans_rule FOREIGN KEY (installment_rule_id) REFERENCES installment_rules (id);
