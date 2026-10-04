-- 066_fix_positive_discount_rows.sql
-- مشکل گزارش‌شده: در فرم فاکتور، تخفیف بعد از ذخیره و بازگشتِ دوباره به فرم
-- «جمع» می‌شد (به‌جای تفریق) و مبلغ فاکتور اشتباه درمی‌آمد.
--
-- علت: ردیف‌های تخفیف فاقدِ case_id هستند و مرورگر جمعشان را منفی حساب می‌کند، ولی
-- `addCaseRow()` هنگام بارگذاریِ مجددِ فرم پرچم تخفیف را از دست می‌داد و ردیف مثبت
-- بازسازی می‌شد. کد اصلاح شد (در invoice-items.js و invoice_form.php و saveInvoice()).
--
-- این مایگریشن ردیف‌هایی را که با علامتِ اشتباه (مثبت) ذخیره شده‌اند یک‌بار اصلاح
-- می‌کند و جمع فاکتورهای تأثیرگرفته را بازمحاسبه می‌کند.

-- ۱) تخفیف‌های مثبت → منفی
UPDATE invoice_items
SET total_amount = -ABS(total_amount)
WHERE (case_id IS NULL OR case_id = 0)
  AND total_amount > 0
  AND (item_title LIKE '%تخفیف%' OR item_description LIKE '%تخفیف%');

-- ۲) بازمحاسبهٔ جمعِ همهٔ فاکتورها از روی آیتم‌ها (منبع حقیقت = sum آیتم‌ها)
UPDATE doctor_invoices i
SET i.total_amount = (
        SELECT COALESCE(SUM(ii.total_amount), 0)
        FROM invoice_items ii
        WHERE ii.invoice_id = i.id
    ),
    i.updated_at = NOW()
WHERE EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.invoice_id = i.id);
