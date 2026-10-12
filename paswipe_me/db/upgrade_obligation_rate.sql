-- PA-KASKAS: agreed interest rate on borrower obligations (% per month).
-- Additive and safe to run more than once; the app also applies it by itself
-- (obligation_rate_upgrade() in includes/installments.php).
-- NULL = the interest was entered as an amount, as before.
-- Agreed interest = original amount x agreed_rate % x months
-- (months = the obligation's monthly installments: a cash loan share follows
-- the loan's schedule; otherwise one a month from the obligation date to the due date).
ALTER TABLE borrower_obligations ADD COLUMN IF NOT EXISTS agreed_rate DECIMAL(8,4) NULL AFTER agreed_interest;
