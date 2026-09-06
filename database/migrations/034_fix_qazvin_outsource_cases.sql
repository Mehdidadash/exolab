-- 034_fix_qazvin_outsource_cases.sql
-- Fix OLD cases of قزوین doctors (خطیب=21، شیخی=20) that were created with the
-- wrong ownership/type (branch_id=1 / lab_in). These cases were handed to the
-- CENTRAL branch by قزوین (فاطمه حسینی):
--   * قزوین owns the case and bills the doctor 1,900,000 per unit.
--   * The CENTRAL branch produced the work and bills قزوین the inter-branch
--     rate (1,000,000 per unit) via a branch receivable invoice.
-- The fix converts them to the standard lab_out structure (as if قزوین had
-- outsourced them to the central lab, user 27 = لابراتوار مرکزی).

-- 1) Move ownership of ALL these doctors' cases to قزوین (branch 2).
UPDATE cases
SET branch_id = 2
WHERE doctor_id IN (20, 21)
  AND branch_id = 1
  AND invoice_id IS NULL
  AND receivable_invoice_id IS NULL;

-- 2) Convert the lab_in cases to lab_out to the central lab (user 27),
--    mark the central branch as source, and set the doctor price (1,900,000).
UPDATE cases
SET case_type = 'lab_out',
    source_branch_id = 1,
    lab_id = 27,
    unit_price = 1900000,
    total_price = 1900000 * quantity
WHERE doctor_id IN (20, 21)
  AND case_type = 'lab_in'
  AND branch_id = 2
  AND invoice_id IS NULL
  AND receivable_invoice_id IS NULL;

-- 3) Define the inter-branch rate (نرخ بین شعب) for روکش زیرکونیا مولتی لیر
--    from the central lab (user 27): 1,000,000 per unit. Stored as GLOBAL
--    (branch_id NULL) so every branch sees it in its own rates list.
INSERT INTO outsource_rates (branch_id, lab_id, service_id, rate, created_at, updated_at)
SELECT NULL, 27, 1, 1000000, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM outsource_rates WHERE lab_id = 27 AND service_id = 1);

-- 4) Create the central branch manager (مدیر شعبه مرکزی، branch 1) so branch 1
--    can issue branch receivable invoices to قزوین and manage its own books.
--    Default password for this account: CentralAdmin@1405 (change it after login).
INSERT INTO users (username, password_hash, full_name, role, branch_id, active, created_at, updated_at)
SELECT 'central_admin', '$2y$10$nhUjiHidau76.pSnJSUFn..gw.3f/hFRTnuj01sAhbXh7Jqedg0U2', 'مدیر شعبه مرکزی', 'branch_admin', 1, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM users WHERE role = 'branch_admin' AND branch_id = 1 AND active = 1);
