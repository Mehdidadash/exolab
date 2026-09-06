-- 037_unify_price_links.sql
-- price_links می‌شود «جدول دوم» یکپارچه برای همه نرخ‌های غیرپیش‌فرض:
--   general        = قیمت عمومی/پیش‌فرض (اطلاعاتی در نقشه)
--   specific       = قیمت اختصاصی یک طرف (دکتر/کلینیک) — ما از او می‌گیریم
--   outsource      = نرخ برون‌سپاری/خرید از لابراتوار یا شعبه — ما به او می‌پردازیم
--   design_fee     = نرخ طراحی (پرداخت به طراح)
--   branch_default = پیش‌فرض قیمتِ خودِ یک شعبه (branch_service_prices)
-- ستون‌ها:
--   service_id  NULL می‌شود (برای نرخ کلی طراحی که به خدمت خاصی وابسته نیست)
--   receiver_id NULL می‌شود (برای پیش‌فرض شعبه که گیرنده مشخصی ندارد)
--   branch_id   NULL = سراسری، عدد = متعلق به آن شعبه
ALTER TABLE price_links
    MODIFY COLUMN service_id INT NULL,
    MODIFY COLUMN provider_type ENUM('branch','lab','doctor','designer') NOT NULL,
    MODIFY COLUMN receiver_type ENUM('branch','lab','doctor','designer') NOT NULL,
    MODIFY COLUMN receiver_id INT NULL,
    MODIFY COLUMN price_type ENUM('general','specific','outsource','design_fee','branch_default') NOT NULL DEFAULT 'general',
    ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER price_type;
