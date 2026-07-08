<?php
// panel/dashboard.php
require_once __DIR__ . '/auth.php';
require_login();
$user = current_user();
$role = $user['role'];

panel_layout_start('داشبورد');

if ($role === 'admin') {
    ?>
    <div class="grid">
        <div class="card">
            <h3>مدیریت قیمت‌ها</h3>
            <a class="btn" href="prices.php">رفتن</a>
        </div>
        <div class="card">
            <h3>نمونه کار</h3>
            <a class="btn" href="works.php">رفتن</a>
        </div>
        <div class="card">
            <h3>پزشکان</h3>
            <a class="btn" href="doctors.php">رفتن</a>
        </div>
        <div class="card">
            <h3>فاکتورها</h3>
            <a class="btn" href="invoices.php">رفتن</a>
        </div>
        <div class="card">
            <h3>پرداخت‌ها</h3>
            <a class="btn" href="payments.php">رفتن</a>
        </div>
        <div class="card">
            <h3>حساب‌های بانکی</h3>
            <a class="btn" href="bank_accounts.php">رفتن</a>
        </div>
        <div class="card">
            <h3>قیمت‌های اختصاصی پزشکان</h3>
            <p>تعیین قیمت‌های متفاوت برای هر پزشک به ازای هر خدمت.</p>
            <a class="btn" href="doctor_price_overrides.php">مدیریت</a>
        </div>
    </div>
    <?php
} elseif ($role === 'doctor') {
    // Doctor stats
    $stmt = db()->prepare('SELECT COUNT(*) FROM cases WHERE doctor_id = (SELECT id FROM users WHERE id = ?)');
    $stmt->execute([$user['id']]);
    $totalCases = $stmt->fetchColumn();
    ?>
    <div class="grid">
        <div class="card">
            <h3>کل کیس‌ها</h3>
            <p style="font-size:1.8rem;"><?= toPersianDigits($totalCases) ?></p>
            <a class="btn" href="cases.php">مشاهده</a>
        </div>
        <div class="card">
            <h3>فاکتورها</h3>
            <a class="btn" href="invoices.php">مشاهده</a>
        </div>
        <div class="card">
            <h3>پرداخت‌ها</h3>
            <a class="btn" href="payments.php">مشاهده</a>
        </div>
    </div>
    <?php
} else {
    // Staff / secretary / designer / technician
    ?>
    <div class="grid">
        <div class="card">
            <h3>کیس‌ها</h3>
            <a class="btn" href="cases.php">مشاهده و مدیریت</a>
        </div>
        <?php if (has_permission('view_invoices')): ?>
        <div class="card">
            <h3>فاکتورها</h3>
            <a class="btn" href="invoices.php">مشاهده</a>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
panel_layout_end();