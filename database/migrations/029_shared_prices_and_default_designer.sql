-- 029_shared_prices_and_default_designer.sql
-- 1) site_prices becomes the SHARED standard service catalog (all branches see
--    the same service titles/units/IDs). Per-branch custom price per service
--    is stored separately so each branch can override its own rate while
--    falling back to the shared list.
CREATE TABLE IF NOT EXISTS branch_service_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    service_id INT NOT NULL,
    custom_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (branch_id, service_id),
    INDEX (service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2) Default designer: when a partner lab / branch creates a case, this designer
--    is auto-selected.
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_default_designer TINYINT(1) NOT NULL DEFAULT 0 AFTER is_designer;
