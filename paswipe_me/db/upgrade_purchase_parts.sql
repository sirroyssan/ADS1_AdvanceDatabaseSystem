-- PA-KASKAS upgrade (additive): what each repayment of a card purchase is for.
-- The borrower ticks rows (late charges, Installment 1 ...) when paying; each
-- confirmed repayment is applied exactly there. Money without a choice fills the
-- installments oldest first, then late charges.
-- The application creates this table automatically on first page load
-- (borrowed_parts_upgrade() in includes/borrowed.php); this file is the same change
-- for running by hand. Nothing existing is changed or removed.

CREATE TABLE IF NOT EXISTS borrower_payment_purchase_parts (
    id CHAR(36) NOT NULL PRIMARY KEY,
    borrower_payment_id CHAR(36) NOT NULL,
    transaction_id CHAR(36) NOT NULL,
    part ENUM('penalty', 'installment', 'purchase') NOT NULL,
    installment_schedule_id CHAR(36) NULL,
    amount DECIMAL(15,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_purchase_part_payment (borrower_payment_id),
    KEY idx_purchase_part_txn (transaction_id),
    CONSTRAINT fk_purchase_part_payment FOREIGN KEY (borrower_payment_id) REFERENCES borrower_payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_purchase_part_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id),
    CONSTRAINT fk_purchase_part_schedule FOREIGN KEY (installment_schedule_id) REFERENCES installment_schedules(id) ON DELETE SET NULL,
    CONSTRAINT chk_purchase_part_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
