-- 084_backfill_case_outsourced_rate.sql
-- تثبیتِ نرخِ برون‌سپاریِ کیس‌های تاریخی (رفع باگ «تغییرِ نرخ‌ها، مخارج گذشته را عوض می‌کند»).
--
-- ── مشکل ──
-- برای بخشی از کیس‌ها (خصوصاً lab_out) ستون `cases.outsourced_rate` خالی مانده بود و
-- گزارش‌هایی مثل «کیس‌های مخارج» و «بررسی درآمد و هزینه» نرخ را **همان لحظه** از
-- price_links / outsource_rates می‌خواندند. با به‌روزرسانیِ نرخ‌ها، مخارجِ کیس‌های قدیمی
-- هم عوض می‌شد (نمونه: کیس ۱۱۸۰ با تاریخ ۱۴۰۵/۰۶/۰۹ که فاکتورش ۱٬۰۰۰٬۰۰۰ بود ولی
-- مخارجش ۱٬۱۵۰٬۰۰۰ نشان داده می‌شد).
--
-- ── راه‌حل ──
-- نرخی که در **فاکتور برون‌سپاری** ثبت شده، سندِ قطعیِ همان زمان است؛ آن را روی خودِ کیس
-- می‌نویسیم. کدها هم اصلاح شدند تا همیشه اول نرخِ ثبت‌شده را ببینند
-- (caseOutsourceStoredRateSql در panel/db.php).
--
-- این UPDATE بی‌خطر/تکرارپذیر است: فقط ردیف‌های NULL را پر می‌کند و نرخ دستیِ موجود
-- روی کیس را هرگز بازنویسی نمی‌کند.

UPDATE `cases` c
JOIN (
    SELECT `case_id`, MAX(`id`) AS `last_item_id`
    FROM `outsource_invoice_items`
    WHERE `case_id` IS NOT NULL
    GROUP BY `case_id`
) last ON last.`case_id` = c.`id`
JOIN `outsource_invoice_items` oi ON oi.`id` = last.`last_item_id`
SET c.`outsourced_rate` = oi.`unit_rate`,
    c.`updated_at` = NOW()
WHERE c.`outsourced_rate` IS NULL
  AND oi.`unit_rate` IS NOT NULL
  AND oi.`unit_rate` > 0;
