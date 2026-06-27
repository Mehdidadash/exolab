<?php
require_once __DIR__ . '/auth.php';
admin_layout_start('داشبورد مدیریت');
?>
<div class="grid">
    <div class="card">
        <h3>مدیریت لیست قیمت</h3>
        <p>اضافه، ویرایش و حذف آیتم‌های لیست قیمت از طریق پنل مدیریت.</p>
        <a class="btn" href="prices.php">رفتن به لیست قیمت</a>
    </div>
    <div class="card">
        <h3>مدیریت نمونه کار</h3>
        <p>آپلود و مدیریت تصاویر نمونه کار، ویرایش ترتیب نمایش.</p>
        <a class="btn" href="works.php">رفتن به نمونه کار</a>
    </div>
    <div class="card">
        <h3>مدیریت فاکتورها</h3>
        <p>ایجاد و مدیریت صورت‌حساب‌های دکتران، پیش‌نمایش و دانلود PDF.</p>
        <a class="btn" href="invoices.php">رفتن به فاکتورها</a>
    </div>
    <div class="card">
        <h3>ثبت پرداخت‌ها</h3>
        <p>ثبت و پیگیری پرداخت‌های دکتران از طریق روش‌های مختلف.</p>
        <a class="btn" href="payments.php">رفتن به پرداخت‌ها</a>
    </div>
    <div class="card">
        <h3>مدیریت حسابهای بانکی</h3>
        <p>مدیریت اطلاعات حسابهای بانکی برای دریافت پرداخت.</p>
        <a class="btn" href="bank_accounts.php">رفتن به حسابهای بانکی</a>
    </div>
    <div class="card">
        <h3>خروج</h3>
        <p>برای خروج از حساب مدیر می‌توانید از لینک «خروج» در نوار بالا استفاده کنید.</p>
    </div>
</div>
<?php admin_layout_end();
