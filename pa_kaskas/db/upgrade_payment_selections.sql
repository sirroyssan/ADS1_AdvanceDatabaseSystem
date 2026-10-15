-- PA-KASKAS upgrade (approved schema change): what a borrower says a payment is for.
-- The application creates this table automatically on first page load
-- (includes/payment_selections.php); this file is the same change for running by hand.

CREATE TABLE IF NOT EXISTS borrower_payment_selections (
    id CHAR(36) NOT NULL PRIMARY KEY,
    borrower_payment_id CHAR(36) NOT NULL,
    obligation_id CHAR(36) NOT NULL,
    installment_schedule_id CHAR(36) NULL,
    amount DECIMAL(15,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_selection_payment (borrower_payment_id),
    CONSTRAINT fk_selection_payment FOREIGN KEY (borrower_payment_id) REFERENCES borrower_payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_selection_obligation FOREIGN KEY (obligation_id) REFERENCES borrower_obligations(id),
    CONSTRAINT fk_selection_schedule FOREIGN KEY (installment_schedule_id) REFERENCES installment_schedules(id),
    CONSTRAINT chk_selection_amount CHECK (amount > 0)
);
