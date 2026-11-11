-- 089_unify_payment_party_type.sql
--
-- هدف: «ثبت دریافت» فقط در یک جا انجام شود (payments.php + payment_form.php)
-- و نوعِ طرف‌حساب (پزشک / کلینیک / لابراتوار همکار / طراح / شعبه همکار) بخشی از
-- خودِ رکورد پرداخت باشد.
--
-- پیش از این، دریافت از شعبهٔ همکار در جدول جدا (branch_receivable_payments) و
-- با فرمِ جداگانه در branch_receivables.php ثبت می‌شد؛ درحالی‌که ماهیتش دقیقاً
-- همان «دریافتی از طرف حساب» است (مثل دریافت از لابراتوار همکار).
-- اکنون همه در doctor_payments ثبت می‌شوند و با branch_receivable_id به فاکتور
-- طلب از شعبه وصل می‌شوند. جدول قدیمی حذف نمی‌شود (برای بازگردانی/آرشیو).

ALTER TABLE doctor_payments
    ADD COLUMN IF NOT EXISTS party_type VARCHAR(20) NOT NULL DEFAULT 'doctor' AFTER doctor_name,
    ADD COLUMN IF NOT EXISTS partner_branch_id INT NULL AFTER party_type,
    ADD COLUMN IF NOT EXISTS branch_receivable_id INT NULL AFTER partner_branch_id;

ALTER TABLE doctor_payments ADD INDEX IF NOT EXISTS idx_payments_party_type (party_type);
ALTER TABLE doctor_payments ADD INDEX IF NOT EXISTS idx_payments_partner_branch (partner_branch_id);
ALTER TABLE doctor_payments ADD INDEX IF NOT EXISTS idx_payments_receivable (branch_receivable_id);

-- پر کردن نوعِ طرف‌حساب برای رکوردهای قبلی از روی نقشِ کاربر
UPDATE doctor_payments p
JOIN users u ON u.id = p.doctor_id
SET p.party_type = CASE
        WHEN u.role = 'clinic' THEN 'clinic'
        WHEN u.role IN ('partner_lab', 'customer_lab', 'outsource_lab', 'lab') THEN 'lab'
        WHEN u.role = 'designer' THEN 'designer'
        ELSE 'doctor'
    END
WHERE p.party_type IS NULL OR p.party_type = 'doctor';

-- انتقالِ دریافت‌های قدیمیِ «شعبه همکار» به doctor_payments
INSERT INTO doctor_payments
    (branch_id, doctor_id, doctor_name, party_type, partner_branch_id, branch_receivable_id,
     amount, payment_method, payment_date, transaction_number, bank_account_id, notes, created_at, updated_at)
SELECT br.branch_id,
       NULL,
       COALESCE(b.name, 'شعبه همکار'),
       'branch',
       br.partner_branch_id,
       p.receivable_id,
       p.amount,
       CASE p.method
            WHEN 'card' THEN 'کارت به کارت'
            WHEN 'cash' THEN 'نقدی'
            WHEN 'pos' THEN 'POS'
            WHEN 'transfer' THEN 'پایا'
            WHEN 'check' THEN 'چک'
            ELSE COALESCE(NULLIF(TRIM(p.method), ''), 'نامشخص')
       END,
       COALESCE(p.payment_date, DATE(p.created_at), CURDATE()),
       p.reference,
       NULL,
       p.notes,
       COALESCE(p.created_at, NOW()),
       COALESCE(p.updated_at, NOW())
FROM branch_receivable_payments p
JOIN branch_receivables br ON br.id = p.receivable_id
LEFT JOIN branches b ON b.id = br.partner_branch_id;
