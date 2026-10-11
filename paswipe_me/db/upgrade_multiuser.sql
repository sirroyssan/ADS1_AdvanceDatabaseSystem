-- PA-KASKAS multi-user upgrade: each financial record has one owner.
-- Applied automatically on first page load (includes/multiuser.php: multiuser_schema(),
-- which adds each part only when missing), after a
-- backup of every table is written to storage/backups/. Safe to run twice.
--
-- Cards already have their owner (credit_cards.owner_user_id). Records that
-- hang off a card (transactions, charges, cash advances, cash loans, card
-- payments, statements, installments …) are owned through their card. The
-- tables below have no card to follow, so they get their own owner column.
-- NULL = owner not known yet: the record is hidden from everyone until an
-- administrator confirms its owner (Users & Roles → Ownership review).

ALTER TABLE borrowers               ADD COLUMN IF NOT EXISTS owner_user_id CHAR(36) NULL AFTER id;
ALTER TABLE penalty_rules           ADD COLUMN IF NOT EXISTS owner_user_id CHAR(36) NULL AFTER id;
ALTER TABLE agreement_templates     ADD COLUMN IF NOT EXISTS owner_user_id CHAR(36) NULL AFTER id;
ALTER TABLE demand_letter_templates ADD COLUMN IF NOT EXISTS owner_user_id CHAR(36) NULL AFTER id;
ALTER TABLE documents               ADD COLUMN IF NOT EXISTS owner_user_id CHAR(36) NULL AFTER id;

ALTER TABLE borrowers               ADD CONSTRAINT fk_borrowers_owner FOREIGN KEY IF NOT EXISTS (owner_user_id) REFERENCES users(id);
ALTER TABLE penalty_rules           ADD CONSTRAINT fk_penalty_rules_owner FOREIGN KEY IF NOT EXISTS (owner_user_id) REFERENCES users(id);
ALTER TABLE agreement_templates     ADD CONSTRAINT fk_agreement_templates_owner FOREIGN KEY IF NOT EXISTS (owner_user_id) REFERENCES users(id);
ALTER TABLE demand_letter_templates ADD CONSTRAINT fk_letter_templates_owner FOREIGN KEY IF NOT EXISTS (owner_user_id) REFERENCES users(id);
ALTER TABLE documents               ADD CONSTRAINT fk_documents_owner FOREIGN KEY IF NOT EXISTS (owner_user_id) REFERENCES users(id);

CREATE INDEX IF NOT EXISTS idx_borrowers_owner ON borrowers (owner_user_id);
CREATE INDEX IF NOT EXISTS idx_penalty_rules_owner ON penalty_rules (owner_user_id);
CREATE INDEX IF NOT EXISTS idx_agreement_templates_owner ON agreement_templates (owner_user_id);
CREATE INDEX IF NOT EXISTS idx_letter_templates_owner ON demand_letter_templates (owner_user_id);
CREATE INDEX IF NOT EXISTS idx_documents_owner ON documents (owner_user_id);
