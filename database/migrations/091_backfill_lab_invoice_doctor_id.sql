-- 091: اتصالِ فاکتورهای لابراتوار (INV-LAB) به کاربرِ لابراتوار
--
-- چرا؟
--   فاکتورهای INV-LAB در `createMonthlyLabInvoice()` با `doctor_id = NULL`
--   ساخته می‌شدند و فقط `doctor_name` داشتند. در نتیجه:
--     ۱) payment_form.php (که با doctor_id فاکتور می‌گیرد) فهرست خالی نشان می‌داد
--        و بدهیِ لابراتوار قابل تسویه نبود.
--     ۲) financial_overview گروه بدهی‌ها را با doctor_id می‌سازد → ردیف‌های
--        لابراتوار شناسه نداشتند.
--
-- این مهاجرت فقط ردیف‌های قدیمی را با تطبیقِ نام (ویژهٔ نقش‌های لابراتوار) اصلاح می‌کند.
-- اگر چند کاربر با همان نام باشند، برای جلوگیری از اتصالِ اشتباه، ردیف دست‌نخورده می‌ماند.

SET NAMES utf8mb4;

UPDATE doctor_invoices i
JOIN users u
  ON u.full_name = i.doctor_name
 AND u.role IN ('lab', 'outsource_lab', 'customer_lab', 'partner_lab')
SET i.doctor_id = u.id
WHERE i.invoice_number LIKE 'INV-LAB%'
  AND i.doctor_id IS NULL
  AND (
      SELECT COUNT(*)
      FROM users u2
      WHERE u2.full_name = i.doctor_name
        AND u2.role IN ('lab', 'outsource_lab', 'customer_lab', 'partner_lab')
  ) = 1;
