-- 057_site_prices_hide_on_site.sql
-- قیمت‌هایی که در «لیست قیمت» سایت اصلی نمایش داده نمی‌شوند ولی در فرم کیس و
-- سایر بخش‌های داخلی قابل استفاده‌اند.
ALTER TABLE site_prices
    ADD COLUMN IF NOT EXISTS hide_on_site TINYINT(1) NOT NULL DEFAULT 0 AFTER active;
