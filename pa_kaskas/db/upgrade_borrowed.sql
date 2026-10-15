-- PA-KASKAS: borrowed transactions (registered users who use another user's card).
-- Applied automatically on first page load (includes/borrowed.php: borrowed_schema_upgrade()).
--
-- borrower_user_accounts.user_id was UNIQUE: one sign-in account could be linked
-- to only one borrower record in the whole system. A registered user can borrow
-- from several card owners, and each owner keeps their own borrower record, so
-- the same user may now be linked once per borrower record. No data changes.
-- (Borrower portal accounts are still linked to one borrower only - checked in code.)

ALTER TABLE borrower_user_accounts ADD UNIQUE KEY uq_bua_user_borrower (user_id, borrower_id);
ALTER TABLE borrower_user_accounts DROP INDEX user_id;

-- ---------------------------------------------------------------------------
-- v2: the transaction is the source of truth for borrowed purchases (no
-- separate obligation). Applied by borrowed_schema_v2(); the changed tables
-- are copied first to backup_borrowed_<table>.
-- Repayments and late penalties point at the purchase itself:

ALTER TABLE borrower_payments ADD COLUMN transaction_id CHAR(36) NULL;
CREATE INDEX idx_bp_transaction ON borrower_payments (transaction_id);
ALTER TABLE borrower_payments ADD CONSTRAINT fk_bp_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id);

ALTER TABLE applied_penalties ADD COLUMN transaction_id CHAR(36) NULL;
CREATE INDEX idx_ap_transaction ON applied_penalties (transaction_id);
ALTER TABLE applied_penalties ADD CONSTRAINT fk_ap_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id);
ALTER TABLE applied_penalties MODIFY obligation_id CHAR(36) NULL;   -- a purchase's penalty has no obligation

-- Data: obligations the previous version created automatically ("Created
-- automatically: the borrower used your card…") are set to cancelled (kept as
-- history); their repayments and penalties get transaction_id = the purchase.
