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
