-- 031_branch_receivables.sql
-- Inter-branch RECEIVABLE invoices (فاکتور طلب از شعبه همکار):
-- When branch A outsources work to branch B, branch B (the receiving/producing
-- branch) records the amount branch A owes it. This is the mirror image of the
-- creating branch's outsource_invoice (expense). One case, two financial views.
CREATE TABLE IF NOT EXISTS branch_receivables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(30) NOT NULL UNIQUE,
    branch_id INT NULL,              -- receiving branch (the one owed) = us
    partner_branch_id INT NULL,      -- the branch that owes us
    total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    period_label VARCHAR(100) NULL,
    invoice_date DATE NULL,
    notes TEXT NULL,
    payment_status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
    created_at DATETIME NULL,
    updated_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS branch_receivable_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receivable_id INT NOT NULL,
    case_id INT NULL,
    doctor_id INT NULL,
    doctor_name VARCHAR(191) NULL,
    service_id INT NULL,
    service_title VARCHAR(191) NULL,
    patient_name VARCHAR(191) NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_rate DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    received_date DATE NULL,
    created_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS branch_receivable_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receivable_id INT NOT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    payment_date DATE NULL,
    method VARCHAR(50) NULL,
    reference VARCHAR(191) NULL,
    notes VARCHAR(191) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL
);

ALTER TABLE cases ADD COLUMN IF NOT EXISTS receivable_invoice_id INT NULL AFTER outsource_invoice_id;
