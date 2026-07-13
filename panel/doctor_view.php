<?php
// panel/doctor_view.php
require_once __DIR__ . '/auth.php';
require_role('admin');

$doctorId = !empty($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$doctorId) {
    header('Location: doctors.php');
    exit;
}

$doctor = getDoctor($doctorId);
if (!$doctor) {
    header('Location: doctors.php?error=notfound');
    exit;
}

// ─── آمار ───
$totalCases = db()->prepare("SELECT COUNT(*) FROM cases WHERE doctor_id = ?");
$totalCases->execute([$doctorId]);
$totalCases = (int) $totalCases->fetchColumn();

$activeCases = db()->prepare("SELECT COUNT(*) FROM cases c JOIN case_statuses s ON c.status_id = s.id WHERE c.doctor_id = ? AND s.name NOT IN ('Delivered','Cancelled')");
$activeCases->execute([$doctorId]);
$activeCases = (int) $activeCases->fetchColumn();

$totalInvoices = db()->prepare("SELECT COUNT(*) FROM doctor_invoices WHERE doctor_id = ?");
$totalInvoices->execute([$doctorId]);
$totalInvoices = (int) $totalInvoices->fetchColumn();

$unpaidInvoices = db()->prepare("SELECT COUNT(*) FROM doctor_invoices WHERE doctor_id = ? AND payment_status = 'unpaid'");
$unpaidInvoices->execute([$doctorId]);
$unpaidInvoices = (int) $unpaidInvoices->fetchColumn();

$totalRevenue = db()->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM doctor_invoices WHERE doctor_id = ? AND payment_status = 'paid'");
$totalRevenue->execute([$doctorId]);
$totalRevenue = (float) $totalRevenue->fetchColumn();

$totalDebt = db()->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM doctor_invoices WHERE doctor_id = ? AND payment_status = 'unpaid'");
$totalDebt->execute([$doctorId]);
$totalDebt = (float) $totalDebt->fetchColumn();

$totalPayments = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM doctor_payments WHERE doctor_id = ?");
$totalPayments->execute([$doctorId]);
$totalPayments = (float) $totalPayments->fetchColumn();

// ─── آخرین کیس‌ها ───
$stmt = db()->prepare(
    "SELECT c.*, p.title AS service_title, cs.name AS status_name
     FROM cases c
     LEFT JOIN site_prices p ON c.service_id = p.id
     LEFT JOIN case_statuses cs ON c.status_id = cs.id
     WHERE c.doctor_id = ?
     ORDER BY c.created_at DESC LIMIT 10"
);
$stmt->execute([$doctorId]);
$recentCases = $stmt->fetchAll();

// ─── آخرین فاکتورها ───
$stmt = db()->prepare(
    "SELECT * FROM doctor_invoices WHERE doctor_id = ? ORDER BY invoice_date DESC LIMIT 10"
);
$stmt->execute([$doctorId]);
$recentInvoices = $stmt->fetchAll();

// ─── آخرین پرداخت‌ها ───
$stmt = db()->prepare(
    "SELECT p.*, b.bank_name, b.account_owner_name
     FROM doctor_payments p
     LEFT JOIN bank_accounts b ON p.bank_account_id = b.id
     WHERE p.doctor_id = ?
     ORDER BY p.payment_date DESC LIMIT 10"
);
$stmt->execute([$doctorId]);
$recentPayments = $stmt->fetchAll();

panel_layout_start('نمایه پزشک: ' . $doctor['name']);
?>
<div style="margin-bottom:18px;">
    <a class="btn" href="doctors.php">بازگشت به لیست پزشکان</a>
    <a class="btn" href="doctor_form.php?id=<?= $doctorId ?>" style="background:#0F172A; color:#fff;">ویرایش پزشک</a>
</div>

<!-- Info Card -->
<div class="form-card" style="margin-bottom:24px;">
    <div style="display:flex; gap:20px; flex-wrap:wrap; justify-content:space-between;">
        <div>
            <h3 style="margin:0 0 8px;"><?= htmlspecialchars($doctor['name']) ?></h3>
            <p style="margin:4px 0;"><strong>تلفن:</strong> <?= htmlspecialchars($doctor['phone'] ?? '—') ?></p>
            <p style="margin:4px 0;"><strong>ایمیل:</strong> <?= htmlspecialchars($doctor['email'] ?? '—') ?></p>
            <p style="margin:4px 0;"><strong>وضعیت:</strong> <?= $doctor['active'] ? 'فعال' : 'غیرفعال' ?></p>
            <?php if ($doctor['notes']): ?>
                <p style="margin:4px 0;"><strong>یادداشت:</strong> <?= htmlspecialchars($doctor['notes']) ?></p>
            <?php endif; ?>
        </div>
        <div style="text-align:left;">
            <p style="margin:4px 0; font-size:0.9rem;">آخرین ورود: <?= !empty($doctor['last_login']) ? toJalaliDateFormatted($doctor['last_login']) : '—' ?></p>
        </div>
    </div>
</div>

<!-- Stats Grid -->
<div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); margin-bottom:24px;">
    <div class="card" style="text-align:center; border-right:4px solid #06B6D4;">
        <h4 style="margin:0; color:#525252; font-size:0.85rem;">کل کیس‌ها</h4>
        <p style="font-size:1.8rem; margin:8px 0 0; font-weight:700;"><?= toPersianDigits($totalCases) ?></p>
    </div>
    <div class="card" style="text-align:center; border-right:4px solid #f59e0b;">
        <h4 style="margin:0; color:#525252; font-size:0.85rem;">کیس‌های فعال</h4>
        <p style="font-size:1.8rem; margin:8px 0 0; font-weight:700;"><?= toPersianDigits($activeCases) ?></p>
    </div>
    <div class="card" style="text-align:center; border-right:4px solid #10b981;">
        <h4 style="margin:0; color:#525252; font-size:0.85rem;">فاکتورها</h4>
        <p style="font-size:1.8rem; margin:8px 0 0; font-weight:700;"><?= toPersianDigits($totalInvoices) ?></p>
        <small style="color:#ef4444;"><?= toPersianDigits($unpaidInvoices) ?> پرداخت نشده</small>
    </div>
    <div class="card" style="text-align:center; border-right:4px solid #8b5cf6;">
        <h4 style="margin:0; color:#525252; font-size:0.85rem;">دریافتی</h4>
        <p style="font-size:1.1rem; margin:8px 0 0; font-weight:700;"><?= formatAmountToman($totalPayments) ?></p>
    </div>
    <div class="card" style="text-align:center; border-right:4px solid #ef4444;">
        <h4 style="margin:0; color:#525252; font-size:0.85rem;">بدهی</h4>
        <p style="font-size:1.1rem; margin:8px 0 0; font-weight:700; color:#ef4444;"><?= formatAmountToman($totalDebt) ?></p>
    </div>
</div>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
    <!-- Recent Cases -->
    <div class="form-card">
        <h3>آخرین کیس‌ها</h3>
        <table style="width:100%; font-size:0.9rem;">
            <thead><tr><th>#</th><th>بیمار</th><th>خدمت</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
            <tbody>
            <?php foreach ($recentCases as $c): ?>
                <tr>
                    <td><a href="view_case.php?id=<?= $c['id'] ?>"><?= $c['id'] ?></a></td>
                    <td><?= htmlspecialchars($c['patient_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($c['service_title'] ?? '') ?></td>
                    <td><span class="badge"><?= htmlspecialchars($c['status_name'] ?? '') ?></span></td>
                    <td><?= toJalaliDateFormatted($c['received_date']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recentCases)): ?><tr><td colspan="5" class="empty">هیچ کیسی ثبت نشده</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Recent Invoices -->
    <div class="form-card">
        <h3>آخرین فاکتورها</h3>
        <table style="width:100%; font-size:0.9rem;">
            <thead><tr><th>شماره</th><th>تاریخ</th><th>مبلغ</th><th>وضعیت</th></tr></thead>
            <tbody>
            <?php foreach ($recentInvoices as $inv): ?>
                <tr>
                    <td><a href="invoice_form.php?id=<?= $inv['id'] ?>"><?= htmlspecialchars($inv['invoice_number']) ?></a></td>
                    <td><?= toJalaliDateFormatted($inv['invoice_date']) ?></td>
                    <td><?= formatAmountToman($inv['total_amount']) ?></td>
                    <td><span class="badge" style="background:<?= $inv['payment_status'] === 'paid' ? '#dcfce7' : '#fef3c7' ?>; color:<?= $inv['payment_status'] === 'paid' ? '#166534' : '#92400e' ?>;">
                        <?= $inv['payment_status'] === 'paid' ? 'پرداخت شده' : 'پرداخت نشده' ?>
                    </span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recentInvoices)): ?><tr><td colspan="4" class="empty">فاکتوری صادر نشده</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Recent Payments -->
    <div class="form-card" style="grid-column:1/-1;">
        <h3>آخرین پرداخت‌ها</h3>
        <table style="width:100%; font-size:0.9rem;">
            <thead><tr><th>مبلغ</th><th>روش</th><th>تاریخ</th><th>شماره تراکنش</th><th>حساب بانکی</th></tr></thead>
            <tbody>
            <?php foreach ($recentPayments as $pay): ?>
                <tr>
                    <td><?= formatAmountToman($pay['amount']) ?></td>
                    <td><?= htmlspecialchars($pay['payment_method']) ?></td>
                    <td><?= toJalaliDateFormatted($pay['payment_date']) ?></td>
                    <td><?= htmlspecialchars($pay['transaction_number'] ?? '—') ?></td>
                    <td><?= htmlspecialchars(($pay['bank_name'] ?? '') . ' - ' . ($pay['account_owner_name'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recentPayments)): ?><tr><td colspan="5" class="empty">پرداختی ثبت نشده</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php panel_layout_end(); ?>
