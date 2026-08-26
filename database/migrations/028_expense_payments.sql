-- 028_expense_payments.sql
-- Record payments WE make to others (designer design fees, outsourcing to labs),
-- so each expense invoice can be tracked as paid / partially paid.
-- Recipient bank/card info is free-text – NO pre-defined bank_accounts row required.

-- Payment status on expense invoices
ALTER TABLE designer_invoices ADD COLUMN IF NOT EXISTS payment_status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid' AFTER total_amount;
ALTER TABLE outsource_invoices ADD COLUMN IF NOT EXISTS payment_status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid' AFTER total_amount;

-- Expense payments (payments we make)
CREATE TABLE IF NOT EXISTS expense_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    expense_type VARCHAR(20) NOT NULL,          -- 'designer' | 'outsource'
    invoice_id INT NOT NULL,                    -- designer_invoices.id / outsource_invoices.id
    party_name VARCHAR(255) NULL,               -- recipient name (designer/lab)
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    payment_method VARCHAR(50) NULL,
    payment_date DATE NULL,
    transaction_number VARCHAR(255) NULL,
    recipient_bank VARCHAR(120) NULL,           -- free text (no bank_accounts row needed)
    recipient_card VARCHAR(120) NULL,           -- free text card/account/shaba number
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (expense_type, invoice_id),
    INDEX (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
