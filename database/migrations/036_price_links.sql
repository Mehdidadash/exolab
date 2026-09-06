-- 036_price_links.sql
-- نقشه قیمت‌گذاری خدمات (Price Map):
-- رابطه‌های قیمتی جهت‌دار بین شعب، لابراتوارها و پزشک‌ها.
-- semantics: provider (کسی که کار را انجام می‌دهد) → receiver (کسی که می‌پردازد)
-- مثال: شعبه مرکزی → دکتر (روکش زیرکونیا مولتی لیر @ ۱.۹م) | فاطمه حسینی → شعبه مرکزی (لیرینگ @ ۱.۵م)
CREATE TABLE IF NOT EXISTS price_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    provider_type ENUM('branch','lab','doctor') NOT NULL,
    provider_id INT NOT NULL,
    receiver_type ENUM('branch','lab','doctor') NOT NULL,
    receiver_id INT NOT NULL,
    price DECIMAL(15,2) NOT NULL DEFAULT 0,
    price_type ENUM('general','specific') NOT NULL DEFAULT 'general',
    note VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (service_id),
    INDEX (provider_type, provider_id),
    INDEX (receiver_type, receiver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
