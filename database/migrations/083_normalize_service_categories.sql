-- 083_normalize_service_categories.sql
-- یکسان‌سازی «دستهٔ خدمت» در `site_prices`.
-- قبلاً دسته متنِ آزاد بود («پزشکان»، «همکار»، «عمومی»، ...). از این پس فقط چهار مقدار
-- مجاز است و فرم‌ها هم به select تبدیل شده‌اند (serviceCategories() در includes/helpers.php):
--   دندانپزشکان | همکاری | عمومی | مشترک
--
-- نگاشت: پزشکان → دندانپزشکان ، همکار → همکاری ، بقیه/خالی → عمومی

UPDATE `site_prices` SET `category` = 'دندانپزشکان' WHERE `category` = 'پزشکان';
UPDATE `site_prices` SET `category` = 'دندانپزشکان' WHERE `category` = 'پزشک';
UPDATE `site_prices` SET `category` = 'دندانپزشکان' WHERE `category` = 'دندانپزشک';
UPDATE `site_prices` SET `category` = 'همکاری' WHERE `category` = 'همکار';
UPDATE `site_prices` SET `category` = 'عمومی'
 WHERE `category` IS NULL
    OR `category` = ''
    OR `category` NOT IN ('دندانپزشکان', 'همکاری', 'عمومی', 'مشترک');
