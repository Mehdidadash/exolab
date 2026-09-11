<?php
// panel/dashboard.php
require_once __DIR__ . '/auth.php';
require_login();
$user = current_user();
$role = $user['role'];

use Morilog\Jalali\Jalalian;

panel_layout_start('داشبورد');

if ($role === 'admin' || $role === 'branch_admin') {
    // ─── آمارهای لحظه‌ای ───
    // مدیر کل = کل مجموعه؛ مدیر شعبه = فقط مواردِ شعبهٔ خودش (کیس‌ها، فاکتورها، ...)
    $branchScoped = ($role === 'branch_admin');
    $bid = $branchScoped ? (int) ($user['branch_id'] ?? 0) : null;

    $runStat = function (string $sql, array $params = []): int {
        $st = db()->prepare($sql);
        $st->execute($params);
        return (int) $st->fetchColumn();
    };

    // «درآمد ماه جاری» = فاکتورهای «پرداخت‌شده» با تاریخِ صدور در ماهِ جاریِ شمسی.
    // (نگاه قبلی ماه میلادی بود و اوایلِ ماهِ شمسی، ماه قبل را نادیده می‌گرفت.)
    try {
        $curJ = Jalalian::now();
        $curJalaliStart = Jalalian::fromFormat('Y/m/d', sprintf('%04d/%02d/01', $curJ->getYear(), $curJ->getMonth()));
        $curMonthFrom = $curJalaliStart->toCarbon()->toDateString();
        $curMonthTo = $curJalaliStart->addMonths(1)->subDay()->toCarbon()->toDateString();
    } catch (\Throwable $e) {
        $curMonthFrom = date('Y-m-01');
        $curMonthTo = date('Y-m-t');
    }

    if (!$branchScoped) {
        $stats['doctors'] = $runStat("SELECT COUNT(*) FROM users WHERE role='doctor' AND active=1");
        $stats['cases'] = $runStat('SELECT COUNT(*) FROM cases');
        $stats['active_cases'] = $runStat("SELECT COUNT(*) FROM cases WHERE status_id != 4");
        $stats['total_invoices'] = $runStat('SELECT COUNT(*) FROM doctor_invoices');
        $stats['unpaid_invoices'] = $runStat("SELECT COUNT(*) FROM doctor_invoices WHERE payment_status='unpaid'");
        $stats['monthly_revenue'] = $runStat("SELECT COALESCE(SUM(total_amount), 0) FROM doctor_invoices WHERE payment_status='paid' AND invoice_date BETWEEN ? AND ?", [$curMonthFrom, $curMonthTo]);
        $stats['total_revenue'] = $runStat("SELECT COALESCE(SUM(total_amount), 0) FROM doctor_invoices WHERE payment_status='paid'");
    } else {
        $caseScope = branchCaseScope('c', $bid);
        $caseCountSql = 'SELECT COUNT(*) FROM cases c WHERE ' . $caseScope['sql'];
        $stats['doctors'] = $runStat("SELECT COUNT(*) FROM users WHERE role='doctor' AND active=1 AND branch_id = ?", [$bid]);

        // Invoice branch = invoice.branch_id, یا (برای فاکتورهای قدیمیِ بدون شعبه) شعبهٔ خودِ پزشک.
        $invBr = doctorInvoiceBranchScope('i', $bid);
        $invBase = 'FROM doctor_invoices i WHERE ' . $invBr['sql'];

        $stats['cases'] = $runStat($caseCountSql, $caseScope['params']);
        $stats['active_cases'] = $runStat($caseCountSql . ' AND c.status_id != 4', $caseScope['params']);
        $stats['total_invoices'] = $runStat('SELECT COUNT(*) ' . $invBase, $invBr['params']);
        $stats['unpaid_invoices'] = $runStat("SELECT COUNT(*) {$invBase} AND i.payment_status='unpaid'", $invBr['params']);
        $stats['monthly_revenue'] = $runStat("SELECT COALESCE(SUM(i.total_amount), 0) {$invBase} AND i.payment_status='paid' AND i.invoice_date BETWEEN ? AND ?", array_merge($invBr['params'], [$curMonthFrom, $curMonthTo]));
        $stats['total_revenue'] = $runStat("SELECT COALESCE(SUM(i.total_amount), 0) {$invBase} AND i.payment_status='paid'", $invBr['params']);
    }
    ?>
    <!-- Stats Cards -->
    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); margin-bottom: 32px;">
        <div class="card" style="text-align: center; border-right: 4px solid #06B6D4;">
            <h4 style="margin:0; color:#525252; font-size:0.9rem;">پزشکان فعال</h4>
            <p style="font-size:2rem; margin:8px 0 0; font-weight:700;"><?= toPersianDigits($stats['doctors']) ?></p>
        </div>
        <div class="card" style="text-align: center; border-right: 4px solid #0F172A;">
            <h4 style="margin:0; color:#525252; font-size:0.9rem;">کل کیس‌ها</h4>
            <p style="font-size:2rem; margin:8px 0 0; font-weight:700;"><?= toPersianDigits($stats['cases']) ?></p>
        </div>
        <div class="card" style="text-align: center; border-right: 4px solid #f59e0b;">
            <h4 style="margin:0; color:#525252; font-size:0.9rem;">کیس‌های فعال</h4>
            <p style="font-size:2rem; margin:8px 0 0; font-weight:700;"><?= toPersianDigits($stats['active_cases']) ?></p>
        </div>
        <div class="card" style="text-align: center; border-right: 4px solid #10b981;">
            <h4 style="margin:0; color:#525252; font-size:0.9rem;">فاکتورهای صادرشده</h4>
            <p style="font-size:2rem; margin:8px 0 0; font-weight:700;"><?= toPersianDigits($stats['total_invoices']) ?></p>
            <small style="color:#ef4444;"><?= toPersianDigits($stats['unpaid_invoices']) ?> پرداخت نشده</small>
        </div>
        <div class="card" style="text-align: center; border-right: 4px solid #8b5cf6;">
            <h4 style="margin:0; color:#525252; font-size:0.9rem;">درآمد ماه جاری</h4>
            <p style="font-size:1.2rem; margin:8px 0 0; font-weight:700;"><?= formatAmountToman($stats['monthly_revenue']) ?></p>
        </div>
        <div class="card" style="text-align: center; border-right: 4px solid #06B6D4;">
            <h4 style="margin:0; color:#525252; font-size:0.9rem;">کل درآمد</h4>
            <p style="font-size:1.2rem; margin:8px 0 0; font-weight:700;"><?= formatAmountToman($stats['total_revenue']) ?></p>
        </div>
    </div>

    <!-- Navigation Cards -->
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
            <h3>بررسی درآمد و هزینه</h3>
            <a class="btn" href="financial_overview.php">رفتن</a>
        </div>
        <div class="card">
            <h3>فاکتورهای مخارج (بدهی‌ها)</h3>
            <a class="btn" href="expenses.php">رفتن</a>
        </div>
        <div class="card">
            <h3>حساب‌های بانکی</h3>
            <a class="btn" href="bank_accounts.php">رفتن</a>
        </div>
        <div class="card">
            <h3>کیس‌ها</h3>
            <a class="btn" href="cases.php">رفتن</a>
        </div>
    </div>
    <?php
} elseif ($role === 'doctor') {
    // Doctor stats
    $stmt = db()->prepare('SELECT COUNT(*) FROM cases WHERE doctor_id = ?');
    $stmt->execute([$user['id']]);
    $totalCases = $stmt->fetchColumn();

    $stmt2 = db()->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM doctor_invoices WHERE doctor_id = ? AND payment_status = 'unpaid'");
    $stmt2->execute([$user['id']]);
    $totalDebt = $stmt2->fetchColumn();
    ?>
    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); margin-bottom: 32px;">
        <div class="card" style="text-align: center; border-right: 4px solid #06B6D4;">
            <h4 style="margin:0; color:#525252; font-size:0.9rem;">کل کیس‌ها</h4>
            <p style="font-size:2rem; margin:8px 0 0; font-weight:700;"><?= toPersianDigits($totalCases) ?></p>
        </div>
        <?php if ($totalDebt > 0): ?>
        <div class="card" style="text-align: center; border-right: 4px solid #ef4444;">
            <h4 style="margin:0; color:#525252; font-size:0.9rem;">بدهی</h4>
            <p style="font-size:1.2rem; margin:8px 0 0; font-weight:700; color:#ef4444;"><?= formatAmountToman($totalDebt) ?></p>
        </div>
        <?php endif; ?>
    </div>
    <div class="grid">
        <div class="card">
            <h3>کیس‌های من</h3>
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