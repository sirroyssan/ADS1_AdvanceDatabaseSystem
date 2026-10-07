-- ============================================================
-- PA-SWIPE AKO
-- MariaDB 10.6+ Database Schema - v2
-- Reserved-word compatibility corrected
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- ENUM TYPES
-- ============================================================















































-- ============================================================
-- USERS / AUTHENTICATION
-- ============================================================

CREATE TABLE users (
    id CHAR(36) NOT NULL PRIMARY KEY,
    username VARCHAR(100) UNIQUE,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    first_name VARCHAR(100),
    middle_name VARCHAR(100),
    last_name VARCHAR(100),
    mobile VARCHAR(30),
    status ENUM('pending','active','suspended','locked','inactive') NOT NULL DEFAULT 'pending',
    email_verified_at DATETIME,
    mobile_verified_at DATETIME,
    last_login_at DATETIME,
    failed_login_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE user_roles (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role ENUM('owner','admin','borrower') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, role)
);

-- ============================================================
-- BORROWERS
-- ============================================================

CREATE TABLE borrowers (
    id CHAR(36) NOT NULL PRIMARY KEY,
    borrower_number VARCHAR(50) UNIQUE NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100),
    last_name VARCHAR(100) NOT NULL,
    suffix VARCHAR(30),
    nickname VARCHAR(100),
    relationship VARCHAR(100),
    primary_mobile VARCHAR(30),
    primary_email VARCHAR(255),
    status ENUM('good_standing','payment_due','late','delinquent','restricted','inactive') NOT NULL DEFAULT 'good_standing',
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) REFERENCES users(id),
    updated_by CHAR(36) REFERENCES users(id)
);

CREATE TABLE borrower_user_accounts (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
    borrower_id CHAR(36) NOT NULL UNIQUE REFERENCES borrowers(id),
    activated_at DATETIME,
    deactivated_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE borrower_addresses (
    id CHAR(36) NOT NULL PRIMARY KEY,
    borrower_id CHAR(36) NOT NULL REFERENCES borrowers(id) ON DELETE CASCADE,
    address_type VARCHAR(30) NOT NULL DEFAULT 'primary',
    house_unit VARCHAR(150),
    street VARCHAR(255),
    barangay VARCHAR(150),
    municipality_city VARCHAR(150),
    province VARCHAR(150),
    postal_code VARCHAR(20),
    is_primary TINYINT(1) NOT NULL DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE borrower_contacts (
    id CHAR(36) NOT NULL PRIMARY KEY,
    borrower_id CHAR(36) NOT NULL REFERENCES borrowers(id) ON DELETE CASCADE,
    contact_type VARCHAR(50) NOT NULL,
    contact_name VARCHAR(255),
    relationship VARCHAR(100),
    mobile VARCHAR(30),
    email VARCHAR(255),
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- CATEGORIES / MERCHANTS
-- ============================================================

CREATE TABLE categories (
    id CHAR(36) NOT NULL PRIMARY KEY,
    parent_id CHAR(36) REFERENCES categories(id),
    name VARCHAR(150) NOT NULL,
    description TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(parent_id, name)
);

CREATE TABLE merchants (
    id CHAR(36) NOT NULL PRIMARY KEY,
    merchant_name VARCHAR(255) NOT NULL,
    branch VARCHAR(255),
    address TEXT,
    barangay VARCHAR(150),
    municipality_city VARCHAR(150),
    province VARCHAR(150),
    merchant_type VARCHAR(30),
    website VARCHAR(500),
    platform VARCHAR(100),
    category_id CHAR(36) REFERENCES categories(id),
    contact VARCHAR(255),
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- CREDIT CARDS
-- ============================================================

CREATE TABLE credit_cards (
    id CHAR(36) NOT NULL PRIMARY KEY,
    owner_user_id CHAR(36) NOT NULL REFERENCES users(id),
    card_nickname VARCHAR(100) NOT NULL,
    issuer VARCHAR(150) NOT NULL,
    card_type ENUM('credit','charge','other') NOT NULL DEFAULT 'credit',
    network ENUM('visa','mastercard','amex','jcb','unionpay','other'),
    last_four CHAR(4) NOT NULL,
    credit_limit DECIMAL(15,2) NOT NULL DEFAULT 0,
    available_credit DECIMAL(15,2) NOT NULL DEFAULT 0,
    billing_cycle_day SMALLINT,
    payment_due_day SMALLINT,
    annual_fee TINYINT(1) NOT NULL DEFAULT FALSE,
    annual_fee_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    interest_rate DECIMAL(8,4),
    opening_date DATE,
    expiration_date DATE,
    status ENUM('active','blocked','expired','closed','inactive') NOT NULL DEFAULT 'active',
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (credit_limit >= 0),
    CHECK (available_credit >= 0),
    CHECK (available_credit <= credit_limit),
    CHECK (last_four REGEXP '^[0-9]{4}$'),
    CHECK (billing_cycle_day IS NULL OR billing_cycle_day BETWEEN 1 AND 31),
    CHECK (payment_due_day IS NULL OR payment_due_day BETWEEN 1 AND 31)
);

CREATE TABLE card_billing_cycles (
    id CHAR(36) NOT NULL PRIMARY KEY,
    credit_card_id CHAR(36) NOT NULL REFERENCES credit_cards(id) ON DELETE CASCADE,
    cycle_start_date DATE NOT NULL,
    cycle_end_date DATE NOT NULL,
    statement_date DATE,
    payment_due_date DATE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (cycle_end_date >= cycle_start_date),
    UNIQUE (credit_card_id, cycle_start_date, cycle_end_date)
);

CREATE TABLE documents (
    id CHAR(36) NOT NULL PRIMARY KEY,
    document_type ENUM('receipt','official_receipt','sales_invoice','delivery_receipt',
    'product_photo','warranty','serial_photo','payment_proof',
    'bank_statement','credit_card_statement','cash_loan_offer',
    'cash_loan_approval','bank_correspondence','agreement',
    'signed_agreement','demand_letter','other') NOT NULL,
    original_filename VARCHAR(500) NOT NULL,
    storage_key TEXT NOT NULL UNIQUE,
    mime_type VARCHAR(150),
    file_size BIGINT,
    checksum VARCHAR(128),
    uploaded_by CHAR(36) REFERENCES users(id),
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    metadata JSON NOT NULL DEFAULT '{}',
    is_deleted TINYINT(1) NOT NULL DEFAULT FALSE
);

CREATE TABLE credit_card_statements (
    id CHAR(36) NOT NULL PRIMARY KEY,
    credit_card_id CHAR(36) NOT NULL REFERENCES credit_cards(id),
    statement_number VARCHAR(100),
    statement_date DATE NOT NULL,
    cutoff_date DATE,
    due_date DATE NOT NULL,
    previous_balance DECIMAL(15,2) NOT NULL DEFAULT 0,
    purchases DECIMAL(15,2) NOT NULL DEFAULT 0,
    payments DECIMAL(15,2) NOT NULL DEFAULT 0,
    interest DECIMAL(15,2) NOT NULL DEFAULT 0,
    finance_charges DECIMAL(15,2) NOT NULL DEFAULT 0,
    late_fees DECIMAL(15,2) NOT NULL DEFAULT 0,
    other_charges DECIMAL(15,2) NOT NULL DEFAULT 0,
    credits DECIMAL(15,2) NOT NULL DEFAULT 0,
    adjustments DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_amount_due DECIMAL(15,2) NOT NULL DEFAULT 0,
    minimum_amount_due DECIMAL(15,2) NOT NULL DEFAULT 0,
    attachment_document_id CHAR(36) REFERENCES documents(id),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (credit_card_id, statement_date)
);

-- ============================================================
-- TRANSACTIONS / PRODUCTS
-- ============================================================

CREATE TABLE transactions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    transaction_number VARCHAR(50) UNIQUE NOT NULL,
    credit_card_id CHAR(36) NOT NULL REFERENCES credit_cards(id),
    merchant_id CHAR(36) REFERENCES merchants(id),
    borrower_id CHAR(36) REFERENCES borrowers(id),
    category_id CHAR(36) REFERENCES categories(id),
    transaction_date DATE NOT NULL,
    posting_date DATE,
    transaction_type ENUM('personal','card_user','family','friend','other') NOT NULL,
    payment_type ENUM('straight','installment','cash_loan','cash_advance','other') NOT NULL,
    status ENUM('draft','posted','active','completed','cancelled','refunded','disputed') NOT NULL DEFAULT 'draft',
    amount DECIMAL(15,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    reference_number VARCHAR(150),
    receipt_number VARCHAR(150),
    location TEXT,
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) REFERENCES users(id),
    updated_by CHAR(36) REFERENCES users(id),
    CHECK (amount >= 0)
);

CREATE TABLE transaction_items (
    id CHAR(36) NOT NULL PRIMARY KEY,
    transaction_id CHAR(36) NOT NULL REFERENCES transactions(id) ON DELETE RESTRICT,
    product_name VARCHAR(255) NOT NULL,
    product_type VARCHAR(150),
    brand VARCHAR(150),
    model VARCHAR(150),
    description TEXT,
    quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    serial_number VARCHAR(255),
    imei VARCHAR(255),
    product_code VARCHAR(255),
    asset_reference_number VARCHAR(255),
    barcode VARCHAR(255),
    color VARCHAR(100),
    size VARCHAR(100),
    variant VARCHAR(150),
    specifications JSON NOT NULL DEFAULT '{}',
    `condition` VARCHAR(100),
    warranty_details TEXT,
    warranty_expiration DATE,
    or_number VARCHAR(150),
    si_number VARCHAR(150),
    remarks TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (quantity > 0),
    CHECK (unit_price >= 0),
    CHECK (total_amount >= 0)
);

CREATE TABLE transaction_attachments (
    id CHAR(36) NOT NULL PRIMARY KEY,
    transaction_id CHAR(36) NOT NULL REFERENCES transactions(id) ON DELETE CASCADE,
    document_id CHAR(36) NOT NULL REFERENCES documents(id),
    attachment_type VARCHAR(100),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(transaction_id, document_id)
);

-- ============================================================
-- INSTALLMENTS
-- ============================================================

CREATE TABLE installment_plans (
    id CHAR(36) NOT NULL PRIMARY KEY,
    transaction_id CHAR(36) NOT NULL UNIQUE REFERENCES transactions(id),
    total_principal DECIMAL(15,2) NOT NULL,
    number_of_installments INTEGER NOT NULL,
    installment_amount DECIMAL(15,2) NOT NULL,
    interest_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    fees_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_repayment DECIMAL(15,2) NOT NULL,
    frequency ENUM('weekly','biweekly','monthly','quarterly','other') NOT NULL DEFAULT 'monthly',
    start_date DATE NOT NULL,
    first_due_date DATE NOT NULL,
    end_date DATE,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (total_principal >= 0),
    CHECK (number_of_installments > 0),
    CHECK (installment_amount >= 0),
    CHECK (total_repayment >= 0)
);

CREATE TABLE installment_schedules (
    id CHAR(36) NOT NULL PRIMARY KEY,
    installment_plan_id CHAR(36) NOT NULL REFERENCES installment_plans(id) ON DELETE CASCADE,
    installment_number INTEGER NOT NULL,
    due_date DATE NOT NULL,
    principal_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    interest_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    fee_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    amount_due DECIMAL(15,2) NOT NULL,
    amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0,
    remaining_amount DECIMAL(15,2) NOT NULL,
    status ENUM('upcoming','due','partially_paid','paid','overdue','waived','cancelled') NOT NULL DEFAULT 'upcoming',
    paid_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (installment_plan_id, installment_number),
    CHECK (installment_number > 0),
    CHECK (amount_due >= 0),
    CHECK (amount_paid >= 0),
    CHECK (remaining_amount >= 0)
);

-- ============================================================
-- CASH LOANS / ADVANCES
-- ============================================================

CREATE TABLE cash_loans (
    id CHAR(36) NOT NULL PRIMARY KEY,
    credit_card_id CHAR(36) NOT NULL REFERENCES credit_cards(id),
    loan_reference VARCHAR(100) UNIQUE NOT NULL,
    offer_number VARCHAR(100),
    requested_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    offered_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    approved_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    accepted_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    actual_cash_received DECIMAL(15,2) NOT NULL DEFAULT 0,
    processing_fee DECIMAL(15,2) NOT NULL DEFAULT 0,
    interest_rate DECIMAL(8,4),
    other_fees DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_repayment DECIMAL(15,2) NOT NULL DEFAULT 0,
    term_months INTEGER,
    monthly_payment DECIMAL(15,2),
    payment_frequency ENUM('weekly','biweekly','monthly','quarterly','other'),
    start_date DATE,
    first_due_date DATE,
    end_date DATE,
    status ENUM('offered','accepted','disbursed','active','completed','cancelled','rejected') NOT NULL DEFAULT 'offered',
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) REFERENCES users(id),
    updated_by CHAR(36) REFERENCES users(id),
    CHECK (requested_amount >= 0),
    CHECK (offered_amount >= 0),
    CHECK (approved_amount >= 0),
    CHECK (accepted_amount >= 0),
    CHECK (actual_cash_received >= 0)
);

CREATE TABLE cash_loan_schedules (
    id CHAR(36) NOT NULL PRIMARY KEY,
    cash_loan_id CHAR(36) NOT NULL REFERENCES cash_loans(id) ON DELETE CASCADE,
    installment_number INTEGER NOT NULL,
    due_date DATE NOT NULL,
    principal_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    interest_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    fee_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    amount_due DECIMAL(15,2) NOT NULL,
    amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0,
    remaining_amount DECIMAL(15,2) NOT NULL,
    status ENUM('upcoming','due','partially_paid','paid','overdue','waived','cancelled') NOT NULL DEFAULT 'upcoming',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (cash_loan_id, installment_number)
);

CREATE TABLE cash_advances (
    id CHAR(36) NOT NULL PRIMARY KEY,
    credit_card_id CHAR(36) NOT NULL REFERENCES credit_cards(id),
    reference_number VARCHAR(150),
    advance_date DATE NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    fee DECIMAL(15,2) NOT NULL DEFAULT 0,
    interest DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(15,2) NOT NULL,
    channel VARCHAR(100),
    statement_id CHAR(36) REFERENCES credit_card_statements(id),
    status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (amount >= 0),
    CHECK (fee >= 0),
    CHECK (interest >= 0),
    CHECK (total_amount >= 0)
);

CREATE TABLE cash_loan_allocations (
    id CHAR(36) NOT NULL PRIMARY KEY,
    cash_loan_id CHAR(36) NOT NULL REFERENCES cash_loans(id) ON DELETE CASCADE,
    borrower_id CHAR(36) REFERENCES borrowers(id),
    allocation_type VARCHAR(50) NOT NULL,
    description VARCHAR(500),
    allocated_amount DECIMAL(15,2) NOT NULL,
    obligation_id CHAR(36),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (allocated_amount >= 0)
);

-- ============================================================
-- BORROWER OBLIGATIONS
-- ============================================================

CREATE TABLE borrower_obligations (
    id CHAR(36) NOT NULL PRIMARY KEY,
    obligation_number VARCHAR(50) UNIQUE NOT NULL,
    borrower_id CHAR(36) NOT NULL REFERENCES borrowers(id),
    transaction_id CHAR(36) REFERENCES transactions(id),
    cash_loan_allocation_id CHAR(36),
    description VARCHAR(500),
    original_amount DECIMAL(15,2) NOT NULL,
    agreed_interest DECIMAL(15,2) NOT NULL DEFAULT 0,
    agreed_fees DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_amount_due DECIMAL(15,2) NOT NULL,
    total_paid DECIMAL(15,2) NOT NULL DEFAULT 0,
    outstanding_amount DECIMAL(15,2) NOT NULL,
    overdue_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    status ENUM('pending','active','partially_paid','paid','overdue',
    'delinquent','cancelled','written_off') NOT NULL DEFAULT 'active',
    obligation_date DATE NOT NULL,
    due_date DATE,
    completed_at DATETIME,
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) REFERENCES users(id),
    updated_by CHAR(36) REFERENCES users(id),
    CHECK (original_amount >= 0),
    CHECK (agreed_interest >= 0),
    CHECK (agreed_fees >= 0),
    CHECK (total_amount_due >= 0),
    CHECK (total_paid >= 0),
    CHECK (outstanding_amount >= 0),
    CHECK (overdue_amount >= 0)
);

ALTER TABLE cash_loan_allocations
    ADD CONSTRAINT fk_cash_allocation_obligation
    FOREIGN KEY (obligation_id) REFERENCES borrower_obligations(id);

ALTER TABLE borrower_obligations
    ADD CONSTRAINT fk_obligation_cash_allocation
    FOREIGN KEY (cash_loan_allocation_id) REFERENCES cash_loan_allocations(id);

-- ============================================================
-- PAYMENT METHODS
-- ============================================================

CREATE TABLE payment_methods (
    id CHAR(36) NOT NULL PRIMARY KEY,
    owner_user_id CHAR(36) REFERENCES users(id),
    borrower_id CHAR(36) REFERENCES borrowers(id),
    method_type ENUM('gcash','maya','maribank','bdo','bpi','metrobank',
    'bank_transfer','cash','other') NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    account_name VARCHAR(255),
    masked_account_number VARCHAR(100),
    last_four CHAR(4),
    is_active TINYINT(1) NOT NULL DEFAULT TRUE,
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (last_four IS NULL OR last_four REGEXP '^[0-9]{4}$')
);

-- ============================================================
-- BORROWER PAYMENTS
-- ============================================================

CREATE TABLE borrower_payments (
    id CHAR(36) NOT NULL PRIMARY KEY,
    payment_number VARCHAR(50) UNIQUE NOT NULL,
    borrower_id CHAR(36) NOT NULL REFERENCES borrowers(id),
    payment_method_id CHAR(36) REFERENCES payment_methods(id),
    payment_date DATE NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    amount DECIMAL(15,2) NOT NULL,
    reference_number VARCHAR(150),
    notes TEXT,
    status ENUM('submitted','under_review','approved','rejected',
    'correction_required','cancelled') NOT NULL DEFAULT 'submitted',
    allocation_status ENUM('unallocated','partially_allocated','fully_allocated','overallocated') NOT NULL DEFAULT 'unallocated',
    reviewed_at DATETIME,
    reviewed_by CHAR(36) REFERENCES users(id),
    rejection_reason TEXT,
    correction_reason TEXT,
    approved_at DATETIME,
    rejected_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (amount > 0)
);

CREATE TABLE borrower_payment_allocations (
    id CHAR(36) NOT NULL PRIMARY KEY,
    borrower_payment_id CHAR(36) NOT NULL REFERENCES borrower_payments(id) ON DELETE RESTRICT,
    obligation_id CHAR(36) NOT NULL REFERENCES borrower_obligations(id),
    installment_schedule_id CHAR(36) REFERENCES installment_schedules(id),
    allocated_amount DECIMAL(15,2) NOT NULL,
    allocation_type VARCHAR(50) NOT NULL DEFAULT 'installment',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (allocated_amount > 0)
);

CREATE TABLE payment_verifications (
    id CHAR(36) NOT NULL PRIMARY KEY,
    borrower_payment_id CHAR(36) NOT NULL UNIQUE REFERENCES borrower_payments(id),
    reviewed_by CHAR(36) NOT NULL REFERENCES users(id),
    reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decision ENUM('submitted','under_review','approved','rejected',
    'correction_required','cancelled') NOT NULL,
    reason TEXT,
    verified_amount DECIMAL(15,2),
    reference_verified TINYINT(1),
    proof_verified TINYINT(1),
    payment_date_verified TINYINT(1),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE payment_verification_logs (
    id CHAR(36) NOT NULL PRIMARY KEY,
    borrower_payment_id CHAR(36) NOT NULL REFERENCES borrower_payments(id),
    from_status ENUM('submitted','under_review','approved','rejected',
    'correction_required','cancelled'),
    to_status ENUM('submitted','under_review','approved','rejected',
    'correction_required','cancelled') NOT NULL,
    action VARCHAR(100) NOT NULL,
    reason TEXT,
    performed_by CHAR(36) NOT NULL REFERENCES users(id),
    performed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE payment_attachments (
    id CHAR(36) NOT NULL PRIMARY KEY,
    borrower_payment_id CHAR(36) NOT NULL REFERENCES borrower_payments(id) ON DELETE CASCADE,
    document_id CHAR(36) NOT NULL REFERENCES documents(id),
    attachment_type VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(borrower_payment_id, document_id)
);

-- ============================================================
-- CREDIT CARD PAYMENTS / BANK CHARGES
-- ============================================================

CREATE TABLE credit_card_payments (
    id CHAR(36) NOT NULL PRIMARY KEY,
    credit_card_id CHAR(36) NOT NULL REFERENCES credit_cards(id),
    statement_id CHAR(36) REFERENCES credit_card_statements(id),
    payment_method_id CHAR(36) REFERENCES payment_methods(id),
    payment_date DATE NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    payment_channel VARCHAR(100),
    source_account_masked VARCHAR(100),
    reference_number VARCHAR(150),
    payment_type VARCHAR(100),
    receipt_document_id CHAR(36) REFERENCES documents(id),
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (amount > 0)
);

CREATE TABLE bank_charges (
    id CHAR(36) NOT NULL PRIMARY KEY,
    credit_card_id CHAR(36) NOT NULL REFERENCES credit_cards(id),
    statement_id CHAR(36) REFERENCES credit_card_statements(id),
    charge_date DATE NOT NULL,
    charge_type VARCHAR(100) NOT NULL,
    description TEXT,
    amount DECIMAL(15,2) NOT NULL,
    reference_number VARCHAR(150),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (amount >= 0)
);

-- ============================================================
-- PENALTIES
-- ============================================================

CREATE TABLE penalty_rules (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    calculation_type VARCHAR(30) NOT NULL,
    basis VARCHAR(50) NOT NULL,
    rate DECIMAL(10,4),
    fixed_amount DECIMAL(15,2),
    frequency VARCHAR(30),
    grace_period_days INTEGER NOT NULL DEFAULT 0,
    maximum_charge DECIMAL(15,2),
    is_active TINYINT(1) NOT NULL DEFAULT TRUE,
    effective_from DATE,
    effective_until DATE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (grace_period_days >= 0)
);

CREATE TABLE applied_penalties (
    id CHAR(36) NOT NULL PRIMARY KEY,
    obligation_id CHAR(36) NOT NULL REFERENCES borrower_obligations(id),
    installment_schedule_id CHAR(36) REFERENCES installment_schedules(id),
    penalty_rule_id CHAR(36) NOT NULL REFERENCES penalty_rules(id),
    applied_date DATE NOT NULL,
    overdue_days INTEGER NOT NULL DEFAULT 0,
    calculation_base DECIMAL(15,2) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    remaining_amount DECIMAL(15,2) NOT NULL,
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (overdue_days >= 0),
    CHECK (calculation_base >= 0),
    CHECK (amount >= 0),
    CHECK (paid_amount >= 0),
    CHECK (remaining_amount >= 0)
);

-- ============================================================
-- AGREEMENTS
-- ============================================================

CREATE TABLE agreement_templates (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    template_body TEXT NOT NULL,
    version VARCHAR(50) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE agreements (
    id CHAR(36) NOT NULL PRIMARY KEY,
    agreement_number VARCHAR(50) UNIQUE NOT NULL,
    template_id CHAR(36) REFERENCES agreement_templates(id),
    borrower_id CHAR(36) NOT NULL REFERENCES borrowers(id),
    transaction_id CHAR(36) REFERENCES transactions(id),
    obligation_id CHAR(36) REFERENCES borrower_obligations(id),
    agreement_date DATE NOT NULL,
    status ENUM('draft','generated','active','signed','completed','cancelled') NOT NULL DEFAULT 'draft',
    original_amount DECIMAL(15,2),
    installment_amount DECIMAL(15,2),
    installment_count INTEGER,
    agreed_charges DECIMAL(15,2) NOT NULL DEFAULT 0,
    payment_terms TEXT,
    generated_document_id CHAR(36) REFERENCES documents(id),
    signed_document_id CHAR(36) REFERENCES documents(id),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) REFERENCES users(id)
);

CREATE TABLE agreement_signatories (
    id CHAR(36) NOT NULL PRIMARY KEY,
    agreement_id CHAR(36) NOT NULL REFERENCES agreements(id) ON DELETE CASCADE,
    user_id CHAR(36) REFERENCES users(id),
    borrower_id CHAR(36) REFERENCES borrowers(id),
    name VARCHAR(255) NOT NULL,
    role VARCHAR(100) NOT NULL,
    email VARCHAR(255),
    mobile VARCHAR(30),
    status ENUM('pending','signed','declined') NOT NULL DEFAULT 'pending',
    signed_at DATETIME,
    signature_reference VARCHAR(255),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (
        (user_id IS NOT NULL AND borrower_id IS NULL)
        OR
        (user_id IS NULL AND borrower_id IS NOT NULL)
        OR
        (user_id IS NULL AND borrower_id IS NULL)
    )
);

CREATE TABLE agreement_documents (
    id CHAR(36) NOT NULL PRIMARY KEY,
    agreement_id CHAR(36) NOT NULL REFERENCES agreements(id) ON DELETE CASCADE,
    document_id CHAR(36) NOT NULL REFERENCES documents(id),
    document_type VARCHAR(50) NOT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    uploaded_by CHAR(36) REFERENCES users(id),
    UNIQUE(agreement_id, document_id)
);

-- ============================================================
-- DEMAND LETTERS
-- ============================================================

CREATE TABLE demand_letter_templates (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    version VARCHAR(50) NOT NULL,
    template_body TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE demand_letters (
    id CHAR(36) NOT NULL PRIMARY KEY,
    letter_number VARCHAR(50) UNIQUE NOT NULL,
    borrower_id CHAR(36) NOT NULL REFERENCES borrowers(id),
    obligation_id CHAR(36) REFERENCES borrower_obligations(id),
    agreement_id CHAR(36) REFERENCES agreements(id),
    template_id CHAR(36) REFERENCES demand_letter_templates(id),
    generated_date DATE NOT NULL,
    original_amount DECIMAL(15,2),
    total_paid DECIMAL(15,2),
    remaining_balance DECIMAL(15,2),
    documented_charges DECIMAL(15,2) DEFAULT 0,
    content TEXT NOT NULL,
    status ENUM('draft','approved','sent','acknowledged','resolved','cancelled') NOT NULL DEFAULT 'draft',
    sent_at DATETIME,
    acknowledged_at DATETIME,
    document_id CHAR(36) REFERENCES documents(id),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by CHAR(36) REFERENCES users(id)
);

-- ============================================================
-- NOTIFICATIONS
-- ============================================================

CREATE TABLE notifications (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    notification_type VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    entity_type VARCHAR(100),
    entity_id CHAR(36),
    channel ENUM('in_app','email','sms','push') NOT NULL DEFAULT 'in_app',
    status ENUM('pending','sent','delivered','read','failed','cancelled') NOT NULL DEFAULT 'pending',
    scheduled_at DATETIME,
    sent_at DATETIME,
    read_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE notification_logs (
    id CHAR(36) NOT NULL PRIMARY KEY,
    notification_id CHAR(36) NOT NULL REFERENCES notifications(id) ON DELETE CASCADE,
    channel ENUM('in_app','email','sms','push') NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending','sent','delivered','read','failed','cancelled') NOT NULL,
    provider_message_id VARCHAR(255),
    error_message TEXT
);

-- ============================================================
-- AUDIT LOG
-- ============================================================

CREATE TABLE activity_logs (
    id CHAR(36) NOT NULL PRIMARY KEY,
    actor_user_id CHAR(36) REFERENCES users(id),
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id CHAR(36),
    description TEXT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- INDEXES
-- ============================================================

CREATE INDEX idx_users_status ON users(status);
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_borrowers_status ON borrowers(status);
CREATE INDEX idx_borrowers_mobile ON borrowers(primary_mobile);
CREATE INDEX idx_borrowers_email ON borrowers(primary_email);

CREATE INDEX idx_borrower_addresses_borrower ON borrower_addresses(borrower_id);
CREATE INDEX idx_borrower_contacts_borrower ON borrower_contacts(borrower_id);

CREATE INDEX idx_credit_cards_owner ON credit_cards(owner_user_id);
CREATE INDEX idx_credit_cards_status ON credit_cards(status);
CREATE INDEX idx_credit_cards_last_four ON credit_cards(last_four);

CREATE INDEX idx_billing_cycles_card ON card_billing_cycles(credit_card_id);
CREATE INDEX idx_billing_cycles_dates ON card_billing_cycles(cycle_start_date, cycle_end_date);

CREATE INDEX idx_statements_card_due_date
    ON credit_card_statements(credit_card_id, due_date);

CREATE INDEX idx_transactions_card ON transactions(credit_card_id);
CREATE INDEX idx_transactions_borrower ON transactions(borrower_id);
CREATE INDEX idx_transactions_date ON transactions(transaction_date);
CREATE INDEX idx_transactions_status ON transactions(status);
CREATE INDEX idx_transactions_reference ON transactions(reference_number);
CREATE INDEX idx_transactions_merchant ON transactions(merchant_id);

CREATE INDEX idx_transaction_items_serial ON transaction_items(serial_number);
CREATE INDEX idx_transaction_items_imei ON transaction_items(imei);
CREATE INDEX idx_transaction_items_or ON transaction_items(or_number);
CREATE INDEX idx_transaction_items_si ON transaction_items(si_number);
CREATE INDEX idx_transaction_items_barcode ON transaction_items(barcode);

CREATE INDEX idx_installment_schedule_due_date
    ON installment_schedules(due_date);

CREATE INDEX idx_installment_schedule_status
    ON installment_schedules(status);

CREATE INDEX idx_cash_loans_card ON cash_loans(credit_card_id);
CREATE INDEX idx_cash_loans_status ON cash_loans(status);
CREATE INDEX idx_cash_loans_dates ON cash_loans(start_date, end_date);

CREATE INDEX idx_cash_loan_schedules_due
    ON cash_loan_schedules(due_date);

CREATE INDEX idx_cash_allocations_borrower
    ON cash_loan_allocations(borrower_id);

CREATE INDEX idx_obligations_borrower
    ON borrower_obligations(borrower_id);

CREATE INDEX idx_obligations_status
    ON borrower_obligations(status);

CREATE INDEX idx_obligations_due_date
    ON borrower_obligations(due_date);

CREATE INDEX idx_obligations_outstanding ON borrower_obligations(outstanding_amount);

CREATE INDEX idx_payment_methods_borrower
    ON payment_methods(borrower_id);

CREATE INDEX idx_borrower_payments_borrower
    ON borrower_payments(borrower_id);

CREATE INDEX idx_borrower_payments_status
    ON borrower_payments(status);

CREATE INDEX idx_borrower_payments_date
    ON borrower_payments(payment_date);

CREATE INDEX idx_borrower_payments_reference
    ON borrower_payments(reference_number);

CREATE INDEX idx_payment_allocations_payment
    ON borrower_payment_allocations(borrower_payment_id);

CREATE INDEX idx_payment_allocations_obligation
    ON borrower_payment_allocations(obligation_id);

CREATE INDEX idx_payment_verifications_decision
    ON payment_verifications(decision);

CREATE INDEX idx_cc_payments_card
    ON credit_card_payments(credit_card_id);

CREATE INDEX idx_cc_payments_date
    ON credit_card_payments(payment_date);

CREATE INDEX idx_bank_charges_card
    ON bank_charges(credit_card_id);

CREATE INDEX idx_penalties_obligation
    ON applied_penalties(obligation_id);

CREATE INDEX idx_agreements_borrower
    ON agreements(borrower_id);

CREATE INDEX idx_agreements_status
    ON agreements(status);

CREATE INDEX idx_demand_letters_borrower
    ON demand_letters(borrower_id);

CREATE INDEX idx_demand_letters_status
    ON demand_letters(status);

CREATE INDEX idx_notifications_user_status
    ON notifications(user_id, status);

CREATE INDEX idx_notifications_scheduled ON notifications(scheduled_at);

CREATE INDEX idx_activity_logs_entity
    ON activity_logs(entity_type, entity_id);

CREATE INDEX idx_activity_logs_actor
    ON activity_logs(actor_user_id);

CREATE INDEX idx_activity_logs_created
    ON activity_logs(created_at);

CREATE INDEX idx_documents_type
    ON documents(document_type);

CREATE INDEX idx_documents_uploaded_by
    ON documents(uploaded_by);

CREATE INDEX idx_merchants_name
    ON merchants(merchant_name);

CREATE INDEX idx_categories_parent
    ON categories(parent_id);

-- ============================================================
-- FULL TEXT SEARCH INDEXES
-- ============================================================

-- ============================================================
-- MARIADB TRIGGERS
-- ============================================================

-- ============================================================

-- ============================================================
-- MARIADB UUID / UPDATED_AT / VALIDATION TRIGGERS
-- ============================================================

DROP TRIGGER IF EXISTS trg_users_uuid;
CREATE TRIGGER trg_users_uuid
BEFORE INSERT ON users
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_users_updated_at;
CREATE TRIGGER trg_users_updated_at
BEFORE UPDATE ON users
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_user_roles_uuid;
CREATE TRIGGER trg_user_roles_uuid
BEFORE INSERT ON user_roles
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_borrowers_uuid;
CREATE TRIGGER trg_borrowers_uuid
BEFORE INSERT ON borrowers
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_borrowers_updated_at;
CREATE TRIGGER trg_borrowers_updated_at
BEFORE UPDATE ON borrowers
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_borrower_user_accounts_uuid;
CREATE TRIGGER trg_borrower_user_accounts_uuid
BEFORE INSERT ON borrower_user_accounts
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_borrower_user_accounts_updated_at;
CREATE TRIGGER trg_borrower_user_accounts_updated_at
BEFORE UPDATE ON borrower_user_accounts
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_borrower_addresses_uuid;
CREATE TRIGGER trg_borrower_addresses_uuid
BEFORE INSERT ON borrower_addresses
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_borrower_addresses_updated_at;
CREATE TRIGGER trg_borrower_addresses_updated_at
BEFORE UPDATE ON borrower_addresses
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_borrower_contacts_uuid;
CREATE TRIGGER trg_borrower_contacts_uuid
BEFORE INSERT ON borrower_contacts
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_borrower_contacts_updated_at;
CREATE TRIGGER trg_borrower_contacts_updated_at
BEFORE UPDATE ON borrower_contacts
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_categories_uuid;
CREATE TRIGGER trg_categories_uuid
BEFORE INSERT ON categories
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_merchants_uuid;
CREATE TRIGGER trg_merchants_uuid
BEFORE INSERT ON merchants
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_merchants_updated_at;
CREATE TRIGGER trg_merchants_updated_at
BEFORE UPDATE ON merchants
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_credit_cards_uuid;
CREATE TRIGGER trg_credit_cards_uuid
BEFORE INSERT ON credit_cards
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_credit_cards_updated_at;
CREATE TRIGGER trg_credit_cards_updated_at
BEFORE UPDATE ON credit_cards
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_card_billing_cycles_uuid;
CREATE TRIGGER trg_card_billing_cycles_uuid
BEFORE INSERT ON card_billing_cycles
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_documents_uuid;
CREATE TRIGGER trg_documents_uuid
BEFORE INSERT ON documents
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_credit_card_statements_uuid;
CREATE TRIGGER trg_credit_card_statements_uuid
BEFORE INSERT ON credit_card_statements
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_credit_card_statements_updated_at;
CREATE TRIGGER trg_credit_card_statements_updated_at
BEFORE UPDATE ON credit_card_statements
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_transactions_uuid;
CREATE TRIGGER trg_transactions_uuid
BEFORE INSERT ON transactions
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_transactions_updated_at;
CREATE TRIGGER trg_transactions_updated_at
BEFORE UPDATE ON transactions
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_transaction_items_uuid;
CREATE TRIGGER trg_transaction_items_uuid
BEFORE INSERT ON transaction_items
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_transaction_items_updated_at;
CREATE TRIGGER trg_transaction_items_updated_at
BEFORE UPDATE ON transaction_items
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_transaction_attachments_uuid;
CREATE TRIGGER trg_transaction_attachments_uuid
BEFORE INSERT ON transaction_attachments
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_installment_plans_uuid;
CREATE TRIGGER trg_installment_plans_uuid
BEFORE INSERT ON installment_plans
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_installment_plans_updated_at;
CREATE TRIGGER trg_installment_plans_updated_at
BEFORE UPDATE ON installment_plans
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_installment_schedules_uuid;
CREATE TRIGGER trg_installment_schedules_uuid
BEFORE INSERT ON installment_schedules
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_installment_schedules_updated_at;
CREATE TRIGGER trg_installment_schedules_updated_at
BEFORE UPDATE ON installment_schedules
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_cash_loans_uuid;
CREATE TRIGGER trg_cash_loans_uuid
BEFORE INSERT ON cash_loans
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_cash_loans_updated_at;
CREATE TRIGGER trg_cash_loans_updated_at
BEFORE UPDATE ON cash_loans
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_cash_loan_schedules_uuid;
CREATE TRIGGER trg_cash_loan_schedules_uuid
BEFORE INSERT ON cash_loan_schedules
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_cash_loan_schedules_updated_at;
CREATE TRIGGER trg_cash_loan_schedules_updated_at
BEFORE UPDATE ON cash_loan_schedules
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_cash_advances_uuid;
CREATE TRIGGER trg_cash_advances_uuid
BEFORE INSERT ON cash_advances
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_cash_advances_updated_at;
CREATE TRIGGER trg_cash_advances_updated_at
BEFORE UPDATE ON cash_advances
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_cash_loan_allocations_uuid;
CREATE TRIGGER trg_cash_loan_allocations_uuid
BEFORE INSERT ON cash_loan_allocations
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_cash_loan_allocations_updated_at;
CREATE TRIGGER trg_cash_loan_allocations_updated_at
BEFORE UPDATE ON cash_loan_allocations
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_borrower_obligations_uuid;
CREATE TRIGGER trg_borrower_obligations_uuid
BEFORE INSERT ON borrower_obligations
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_borrower_obligations_updated_at;
CREATE TRIGGER trg_borrower_obligations_updated_at
BEFORE UPDATE ON borrower_obligations
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_payment_methods_uuid;
CREATE TRIGGER trg_payment_methods_uuid
BEFORE INSERT ON payment_methods
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_payment_methods_updated_at;
CREATE TRIGGER trg_payment_methods_updated_at
BEFORE UPDATE ON payment_methods
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_borrower_payments_uuid;
CREATE TRIGGER trg_borrower_payments_uuid
BEFORE INSERT ON borrower_payments
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_borrower_payments_updated_at;
CREATE TRIGGER trg_borrower_payments_updated_at
BEFORE UPDATE ON borrower_payments
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_borrower_payment_allocations_uuid;
CREATE TRIGGER trg_borrower_payment_allocations_uuid
BEFORE INSERT ON borrower_payment_allocations
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_payment_verifications_uuid;
CREATE TRIGGER trg_payment_verifications_uuid
BEFORE INSERT ON payment_verifications
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_payment_verification_logs_uuid;
CREATE TRIGGER trg_payment_verification_logs_uuid
BEFORE INSERT ON payment_verification_logs
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_payment_attachments_uuid;
CREATE TRIGGER trg_payment_attachments_uuid
BEFORE INSERT ON payment_attachments
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_credit_card_payments_uuid;
CREATE TRIGGER trg_credit_card_payments_uuid
BEFORE INSERT ON credit_card_payments
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_credit_card_payments_updated_at;
CREATE TRIGGER trg_credit_card_payments_updated_at
BEFORE UPDATE ON credit_card_payments
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_bank_charges_uuid;
CREATE TRIGGER trg_bank_charges_uuid
BEFORE INSERT ON bank_charges
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_penalty_rules_uuid;
CREATE TRIGGER trg_penalty_rules_uuid
BEFORE INSERT ON penalty_rules
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_penalty_rules_updated_at;
CREATE TRIGGER trg_penalty_rules_updated_at
BEFORE UPDATE ON penalty_rules
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_applied_penalties_uuid;
CREATE TRIGGER trg_applied_penalties_uuid
BEFORE INSERT ON applied_penalties
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_agreement_templates_uuid;
CREATE TRIGGER trg_agreement_templates_uuid
BEFORE INSERT ON agreement_templates
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_agreement_templates_updated_at;
CREATE TRIGGER trg_agreement_templates_updated_at
BEFORE UPDATE ON agreement_templates
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_agreements_uuid;
CREATE TRIGGER trg_agreements_uuid
BEFORE INSERT ON agreements
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_agreements_updated_at;
CREATE TRIGGER trg_agreements_updated_at
BEFORE UPDATE ON agreements
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_agreement_signatories_uuid;
CREATE TRIGGER trg_agreement_signatories_uuid
BEFORE INSERT ON agreement_signatories
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_agreement_documents_uuid;
CREATE TRIGGER trg_agreement_documents_uuid
BEFORE INSERT ON agreement_documents
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_demand_letter_templates_uuid;
CREATE TRIGGER trg_demand_letter_templates_uuid
BEFORE INSERT ON demand_letter_templates
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_demand_letter_templates_updated_at;
CREATE TRIGGER trg_demand_letter_templates_updated_at
BEFORE UPDATE ON demand_letter_templates
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_demand_letters_uuid;
CREATE TRIGGER trg_demand_letters_uuid
BEFORE INSERT ON demand_letters
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_demand_letters_updated_at;
CREATE TRIGGER trg_demand_letters_updated_at
BEFORE UPDATE ON demand_letters
FOR EACH ROW
SET NEW.updated_at = CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_notifications_uuid;
CREATE TRIGGER trg_notifications_uuid
BEFORE INSERT ON notifications
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_notification_logs_uuid;
CREATE TRIGGER trg_notification_logs_uuid
BEFORE INSERT ON notification_logs
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DROP TRIGGER IF EXISTS trg_activity_logs_uuid;
CREATE TRIGGER trg_activity_logs_uuid
BEFORE INSERT ON activity_logs
FOR EACH ROW
SET NEW.id = IF(NEW.id IS NULL OR NEW.id = '', UUID(), NEW.id);

DELIMITER $$

DROP TRIGGER IF EXISTS trg_validate_payment_allocation_insert$$
CREATE TRIGGER trg_validate_payment_allocation_insert
BEFORE INSERT ON borrower_payment_allocations
FOR EACH ROW
BEGIN
    DECLARE payment_total DECIMAL(15,2);
    DECLARE allocated_total DECIMAL(15,2);

    SELECT amount INTO payment_total
    FROM borrower_payments
    WHERE id = NEW.borrower_payment_id;

    SELECT COALESCE(SUM(allocated_amount), 0)
    INTO allocated_total
    FROM borrower_payment_allocations
    WHERE borrower_payment_id = NEW.borrower_payment_id;

    IF allocated_total + NEW.allocated_amount > payment_total THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Payment allocations cannot exceed payment amount';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_validate_payment_allocation_update$$
CREATE TRIGGER trg_validate_payment_allocation_update
BEFORE UPDATE ON borrower_payment_allocations
FOR EACH ROW
BEGIN
    DECLARE payment_total DECIMAL(15,2);
    DECLARE allocated_total DECIMAL(15,2);

    SELECT amount INTO payment_total
    FROM borrower_payments
    WHERE id = NEW.borrower_payment_id;

    SELECT COALESCE(SUM(allocated_amount), 0)
    INTO allocated_total
    FROM borrower_payment_allocations
    WHERE borrower_payment_id = NEW.borrower_payment_id
      AND id <> NEW.id;

    IF allocated_total + NEW.allocated_amount > payment_total THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Payment allocations cannot exceed payment amount';
    END IF;
END$$

DELIMITER ;

-- ============================================================
-- REPORTING VIEWS
-- ============================================================

CREATE OR REPLACE VIEW vw_borrower_balances AS
SELECT
    b.id AS borrower_id,
    b.borrower_number,
    b.first_name,
    b.last_name,
    b.status,
    COALESCE(SUM(o.total_amount_due), 0)
        AS total_charged,
    COALESCE(SUM(o.total_paid), 0)
        AS total_paid,
    COALESCE(SUM(o.outstanding_amount), 0)
        AS outstanding,
    COALESCE(SUM(o.overdue_amount), 0)
        AS overdue
FROM borrowers b
LEFT JOIN borrower_obligations o
    ON o.borrower_id = b.id
   AND o.status NOT IN ('cancelled')
GROUP BY
    b.id,
    b.borrower_number,
    b.first_name,
    b.last_name,
    b.status;

CREATE OR REPLACE VIEW vw_pending_payment_approvals AS
SELECT
    p.id,
    p.payment_number,
    b.borrower_number,
    b.first_name,
    b.last_name,
    p.payment_date,
    p.submitted_at,
    p.amount,
    p.reference_number,
    pm.method_type,
    pm.display_name,
    p.status
FROM borrower_payments p
JOIN borrowers b
    ON b.id = p.borrower_id
LEFT JOIN payment_methods pm
    ON pm.id = p.payment_method_id
WHERE p.status IN (
    'submitted',
    'under_review',
    'correction_required'
);

CREATE OR REPLACE VIEW vw_overdue_obligations AS
SELECT
    o.id,
    o.obligation_number,
    o.borrower_id,
    b.borrower_number,
    b.first_name,
    b.last_name,
    o.total_amount_due,
    o.total_paid,
    o.outstanding_amount,
    o.overdue_amount,
    o.due_date,
    DATEDIFF(CURRENT_DATE, o.due_date) AS overdue_days
FROM borrower_obligations o
JOIN borrowers b
    ON b.id = o.borrower_id
WHERE o.outstanding_amount > 0
  AND o.due_date < CURRENT_DATE
  AND o.status NOT IN (
      'cancelled',
      'paid',
      'written_off'
  );

CREATE OR REPLACE VIEW vw_credit_card_utilization AS
SELECT
    cc.id,
    cc.card_nickname,
    cc.issuer,
    cc.last_four,
    cc.credit_limit,
    (cc.credit_limit - cc.available_credit)
        AS outstanding,
    cc.available_credit,
    CASE
        WHEN cc.credit_limit > 0
        THEN ROUND(
            (
                (cc.credit_limit - cc.available_credit)
                / cc.credit_limit
            ) * 100,
            2
        )
        ELSE 0
    END AS utilization_percentage
FROM credit_cards cc
WHERE cc.status = 'active';

-- ============================================================
-- SEED ROLE VALUES
-- ============================================================
-- Roles are assigned through user_roles after user creation.
-- No default users/passwords are created by this schema.

COMMIT;

-- ============================================================
-- NOTES
-- ============================================================
-- 1. Never store full card numbers, CVV, PIN, OTP, or banking passwords.
-- 2. Store uploaded files in object storage; documents.storage_key stores
--    the object-storage reference.
-- 3. borrower_payments are not financial ledger entries until approved.
-- 4. Credit-card payments and borrower payments are separate concepts.
-- 5. Financial approval/update workflows should run inside DB transactions.
-- 6. Activity logs should be treated as append-only by the application.
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- END OF PA-SWIPE AKO MARIADB 10.6+ SCHEMA
-- ============================================================
