-- 020_label_marking_and_outsourcing.sql
-- Mark cases whose labels have been printed (for a green highlight in the list)
ALTER TABLE cases ADD COLUMN IF NOT EXISTS label_printed_at DATETIME NULL AFTER updated_at;

-- Outsourcing: per-lab per-service rates (what the lab charges us to outsource a service)
CREATE TABLE IF NOT EXISTS outsource_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lab_id INT NOT NULL,
    service_id INT NOT NULL,
    rate DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (lab_id, service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Outsourcing invoices (paid to labs, independent of case financials – like designer invoices)
CREATE TABLE IF NOT EXISTS outsource_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) NOT NULL,
    lab_id INT NOT NULL,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    period_label VARCHAR(120) NULL,
    invoice_date DATE NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (lab_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS outsource_invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    case_id INT NULL,
    doctor_id INT NULL,
    doctor_name VARCHAR(191) NULL,
    service_id INT NULL,
    service_title VARCHAR(191) NULL,
    patient_name VARCHAR(191) NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_rate DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    received_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE cases ADD COLUMN IF NOT EXISTS outsource_invoice_id INT NULL AFTER designer_invoice_id;
