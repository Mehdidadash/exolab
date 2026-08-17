ALTER TABLE cases ADD COLUMN IF NOT EXISTS design_fee DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER total_price;
ALTER TABLE doctor_price_overrides ADD COLUMN IF NOT EXISTS price_type VARCHAR(30) NOT NULL DEFAULT 'service' AFTER doctor_id;
ALTER TABLE doctor_price_overrides MODIFY COLUMN service_id INT NULL;
