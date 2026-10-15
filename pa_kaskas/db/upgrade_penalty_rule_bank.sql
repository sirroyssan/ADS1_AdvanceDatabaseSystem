-- PA-KASKAS upgrade: penalty rules apply only to their bank's cards.
-- The application runs this automatically on first page load (includes/penalties.php,
-- penalty_schema_upgrade); this file is the same change for running by hand.
-- Approved schema change: ONE nullable column, nothing else is altered.

ALTER TABLE penalty_rules
    ADD COLUMN applies_to_issuer VARCHAR(150) NULL AFTER name;

-- Then open Penalty Rules in the app and choose the Bank for each rule.
-- Rules without a bank are never charged automatically.
