-- PA-KASKAS: voided card payments (audit). A voided payment leaves
-- credit_card_payments (so no total counts it) and a full copy is kept here
-- with the reason, who and when. Applied automatically on first use
-- (pages/card_payments/card_payment_functions.php); this file is for running it by hand.
CREATE TABLE IF NOT EXISTS card_payment_voids (
    id CHAR(36) NOT NULL PRIMARY KEY,
    payment_id CHAR(36) NOT NULL,              -- the voided credit_card_payments.id
    credit_card_id CHAR(36) NOT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    reference_number VARCHAR(150) NULL,
    payment_type VARCHAR(100) NULL,
    snapshot LONGTEXT NOT NULL,                -- JSON copy of the whole payment row
    void_reason TEXT NOT NULL,
    voided_by CHAR(36) NULL,
    voided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_card_payment_void (payment_id),
    KEY idx_card_payment_voids_card (credit_card_id, voided_at),
    CONSTRAINT fk_card_payment_void_card FOREIGN KEY (credit_card_id) REFERENCES credit_cards (id),
    CONSTRAINT fk_card_payment_void_user FOREIGN KEY (voided_by) REFERENCES users (id)
);
