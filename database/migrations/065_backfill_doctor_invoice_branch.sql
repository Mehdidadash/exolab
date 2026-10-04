-- 065_backfill_doctor_invoice_branch.sql
-- مشکل گزارش‌شده: بعد از ویرایشِ یک فاکتور پزشک، فاکتور از «لیست فاکتورها» ناپدید می‌شد.
-- علت: در `saveInvoice()` هنگام UPDATE ستون `branch_id` نوشته نمی‌شد و در INSERT هم اگر پزشک
-- شعبه نداشت (مثل پزشک #30) مقدار NULL می‌گرفت؛ لیست فاکتورها برای کاربرانِ محدود به شعبه
-- با `i.branch_id = <شعبه>` فیلتر می‌کند → فاکتورِ بدون شعبه دیده نمی‌شد.
--
-- بخش کد اصلاح شد (UPDATE دیگر branch_id را حفظ/تنظیم می‌کند و لیست هم فاکتورهای بدون شعبه را
-- به مدیران نشان می‌دهد). این مایگریشن فاکتورهای بدون شعبه را یک‌بار پر می‌کند:
--   ۱) شعبهٔ خودِ پزشک، در صورت وجود
--   ۲) در غیر این صورت شعبهٔ مرکزی (۱)

UPDATE doctor_invoices i
JOIN users u ON u.id = i.doctor_id
SET i.branch_id = u.branch_id
WHERE (i.branch_id IS NULL OR i.branch_id = 0)
  AND u.branch_id IS NOT NULL
  AND u.branch_id > 0;

UPDATE doctor_invoices
SET branch_id = 1
WHERE branch_id IS NULL OR branch_id = 0;
