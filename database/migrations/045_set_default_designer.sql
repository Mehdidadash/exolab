-- 045_set_default_designer.sql
-- کاربر ۲۶ (محمد علی یگانه) طراحِ پیش‌فرض فعال است؛ پرچم is_default_designer را برایش
-- فعال می‌کنیم تا فرم‌های ایجاد کیس همیشه این طراح را به‌صورت پیش‌فرض انتخاب کنند
-- (getDefaultDesigner / getAllDesigners روی همین پرچم کار می‌کنند).
UPDATE users SET is_default_designer = 1, updated_at = NOW() WHERE id = 26;
