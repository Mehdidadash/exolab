-- 018_designer_invoices.sql
-- Design fee (freelance designer) invoicing:
-- designer_invoices + designer_invoice_items, and a link on cases so a
-- designer is not billed twice. Doctor/lab invoicing stays separate.
CREATE TABLE IF NOT EXISTS designer_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) NOT NULL,
    designer_id INT NOT NULL,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    period_label VARCHAR(120) NULL,
    invoice_date DATE NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (designer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS designer_invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    case_id INT NULL,
    doctor_id INT NULL,
    doctor_name VARCHAR(191) NULL,
    service_id INT NULL,
    service_title VARCHAR(191) NULL,
    patient_name VARCHAR(191) NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_design_fee DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    received_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE cases ADD COLUMN IF NOT EXISTS designer_invoice_id INT NULL AFTER invoice_id;
