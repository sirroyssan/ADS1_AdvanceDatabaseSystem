-- PA-KASKAS: your rate to the borrower (earnings) and saving goals.
-- Additive only; the app runs the same statements once (includes/earnings.php,
-- marker storage/upgrade_earnings.done). Safe to run more than once.

ALTER TABLE transactions ADD COLUMN IF NOT EXISTS borrower_rate DECIMAL(8,4) NULL AFTER borrower_id;

CREATE TABLE IF NOT EXISTS savings_goals (
    id CHAR(36) NOT NULL PRIMARY KEY,
    owner_user_id CHAR(36) NOT NULL,
    credit_card_id CHAR(36) NULL,
    name VARCHAR(150) NOT NULL,
    target_amount DECIMAL(15,2) NOT NULL,
    start_date DATE NOT NULL,
    target_date DATE NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    used_at DATETIME NULL,
    notes TEXT NULL,
    created_by CHAR(36) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_savings_goals_owner (owner_user_id),
    KEY idx_savings_goals_card (credit_card_id),
    CONSTRAINT chk_savings_goal_amount CHECK (target_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
