<?php
// panel/dashboard.php
require_once __DIR__ . '/auth.php';
require_login();
$user = current_user();
$role = $user['role'];

use Morilog\Jalali\Jalalian;

panel_layout_start('داشبورد');

// ─── نوبت‌های اسکن: امروز و فردا (کارکنان/مدیران همهٔ شعبه، پزشک فقط نوبت‌های خودش) ───
$dashCanAppts = (function_exists('canManageScanAppointments') && canManageScanAppointments($user)) || $role === 'doctor';
if ($dashCanAppts):
    $dToday    = date('Y-m-d');
    $dTomorrow = date('Y-m-d', strtotime('+1 day'));
    $dAppts    = getScanAppointments(['from' => $dToday, 'to' => $dTomorrow, 'limit' => 12]);
    $dTypes    = scanAppointmentTypes();
    $dStatuses = scanAppointmentStatuses();
    $dScanBody = 0;
    foreach ($dAppts as $da) { if (!empty($da['needs_scan_body'])) $dScanBody++; }
    ?>
    <div class="form-card" style="margin-bottom:20px;">
        <h4 style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; margin:0 0 10px;">
            <span>🗓 نوبت‌های اسکن امروز و فردا
                <?php if ($dScanBody): ?>
                    <span class="badge" style="background:#fef3c7; color:#92400e; margin-inline-start:6px;">🧩 <?= toPersianDigits((string) $dScanBody) ?> مورد اسکن‌بادی</span>
                <?php endif; ?>
            </span>
            <span style="display:flex; gap:6px; flex-wrap:wrap;">
                <?php if (canManageScanAppointments($user)): ?>
                    <a class="btn" href="scan_appointments.php?new=1" style="background:#0F172A; color:#fff; padding:5px 12px;">➕ نوبت جدید</a>
                <?php endif; ?>
                <a class="btn" href="scan_appointments.php" style="background:#E5E7EB; color:#0F172A; padding:5px 12px;">تقویم نوبت‌ها</a>
            </span>
        </h4>
        <?php if (empty($dAppts)): ?>
            <p class="empty" style="margin:0;">امروز و فردا نوبت اسکنی ثبت نشده است.</p>
        <?php else: ?>
            <div style="display:grid; gap:8px;">
                <?php foreach ($dAppts as $da):
                    $dtMeta = $dTypes[(string) $da['appt_type']] ?? ['label' => '—', 'icon' => '📌', 'color' => '#64748b'];
                    $dsMeta = $dStatuses[(string) $da['status']] ?? ['label' => '—', 'color' => '#64748b'];
                ?>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:8px 10px;">
                        <strong style="min-width:86px;"><?= toJalaliDateFormatted((string) $da['appt_date']) ?></strong>
                        <span><?= toPersianDigits(substr((string) $da['start_time'], 0, 5)) ?><?= !empty($da['end_time']) ? ' تا ' . toPersianDigits(substr((string) $da['end_time'], 0, 5)) : '' ?></span>
                        <span style="background:<?= htmlspecialchars($dtMeta['color']) ?>; color:#fff; border-radius:6px; padding:2px 8px; font-size:.78rem;"><?= $dtMeta['icon'] ?> <?= htmlspecialchars($dtMeta['label']) ?></span>
                        <?php if (!empty($da['needs_scan_body'])): ?>
                            <span style="background:#fef3c7; color:#92400e; border-radius:6px; padding:2px 8px; font-size:.78rem; font-weight:700;">🧩 اسکن‌بادی بردار<?= !empty($da['scan_body_type_name']) ? ': ' . htmlspecialchars((string) $da['scan_body_type_name']) : '' ?></span>
                        <?php endif; ?>
                        <span style="background:<?= htmlspecialchars($dsMeta['color']) ?>; color:#fff; border-radius:6px; padding:2px 8px; font-size:.78rem;"><?= htmlspecialchars($dsMeta['label']) ?></span>
                        <?php if (!empty($da['doctor_name'])): ?><span style="font-size:.86rem; color:#334155;">👨‍⚕️ <?= htmlspecialchars((string) $da['doctor_name']) ?></span><?php endif; ?>
                        <?php if (!empty($da['patient_name']) || !empty($da['case_patient'])): ?><span style="font-size:.86rem; color:#334155;">🧑 <?= htmlspecialchars((string) ($da['patient_name'] ?: $da['case_patient'])) ?></span><?php endif; ?>
                        <?php if (!empty($da['case_id'])): ?><a href="view_case.php?id=<?= (int) $da['case_id'] ?>" style="font-size:.82rem;">کیس #<?= (int) $da['case_id'] ?></a><?php endif; ?>
                        <?php if (!empty($da['address'])): ?><span style="font-size:.8rem; color:#64748b;">📍 <?= htmlspecialchars((string) $da['address']) ?></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
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
        // «کیس فعال» = همهٔ کیس‌ها منهای «تحویل شد» (۴) و «لغو شد» (۵).
        // (نامِ وضعیت‌ها فارسی است — با نامِ انگلیسی نمی‌شد تشخیص داد.)
        $stats['active_cases'] = $runStat("SELECT COUNT(*) FROM cases WHERE status_id NOT IN (4, 5)");
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
        $stats['active_cases'] = $runStat($caseCountSql . ' AND c.status_id NOT IN (4, 5)', $caseScope['params']);
        $stats['total_invoices'] = $runStat('SELECT COUNT(*) ' . $invBase, $invBr['params']);
        $stats['unpaid_invoices'] = $runStat("SELECT COUNT(*) {$invBase} AND i.payment_status='unpaid'", $invBr['params']);
        $stats['monthly_revenue'] = $runStat("SELECT COALESCE(SUM(i.total_amount), 0) {$invBase} AND i.payment_status='paid' AND i.invoice_date BETWEEN ? AND ?", array_merge($invBr['params'], [$curMonthFrom, $curMonthTo]));
        $stats['total_revenue'] = $runStat("SELECT COALESCE(SUM(i.total_amount), 0) {$invBase} AND i.payment_status='paid'", $invBr['params']);
    }

    // ─── تفکیک بدهی: «فاکتورشده» در برابر «فاکتورنشده» ───
    // از تابعِ مشترکِ `partyDebtBreakdown()` استفاده می‌کنیم (منبعِ واحدِ حقیقت).
    // ✅ قاعده: هر کیس فقط یک پرداخت‌کننده دارد → lab → clinic → doctor.
    // پس هیچ کیسی در دو گروه شمرده نمی‌شود و لازم نیست پزشکانِ عضو کلینیک را
    // دستی حذف کنیم (چون در تابع، کیسِ دارای clinic_id از پزشک حذف می‌شود).
    $debtBreak = ['doctor' => [], 'clinic' => [], 'lab' => []];
    try {
        $dashBranchOpt = $branchScoped ? ['branch_id' => (int) $bid] : [];

        // ── پزشکان: هر کسی که کیسِ «خودی» (بدون lab و بدون clinic) یا فاکتور دارد ──
        $docIds = [];
        $q = "SELECT doctor_id AS uid FROM doctor_invoices
              WHERE doctor_id IS NOT NULL AND invoice_number NOT LIKE 'INV-LAB%' AND invoice_number NOT LIKE 'INV-CLN%'";
        if ($branchScoped) $q .= " AND branch_id = " . (int) $bid;
        $q .= " UNION SELECT doctor_id FROM cases
               WHERE doctor_id IS NOT NULL AND clinic_id IS NULL
                 AND lab_id IS NULL AND outsourced_lab_id IS NULL AND status_id <> 5";
        if ($branchScoped) $q .= " AND branch_id = " . (int) $bid;
        foreach (db()->query($q)->fetchAll() as $r) { $docIds[(int) $r['uid']] = true; }
        foreach (array_keys($docIds) as $uid) {
            if ($uid <= 0) continue;
            // کاربرِ با نقشِ «کلینیک» در گروه کلینیک‌ها حساب می‌شود، نه پزشکان.
            $chk = db()->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $chk->execute([$uid]);
            if (($chk->fetchColumn() ?: '') === 'clinic') continue;
            $b = partyDebtBreakdown('doctor', $uid, $dashBranchOpt);
            $debtBreak['doctor']['invoiced']      = ($debtBreak['doctor']['invoiced'] ?? 0) + $b['invoiced'];
            $debtBreak['doctor']['uninvoiced']    = ($debtBreak['doctor']['uninvoiced'] ?? 0) + $b['uninvoiced'];
            $debtBreak['doctor']['invoiced_count']   = ($debtBreak['doctor']['invoiced_count'] ?? 0) + $b['invoice_count'];
            $debtBreak['doctor']['uninvoiced_count'] = ($debtBreak['doctor']['uninvoiced_count'] ?? 0) + $b['uninvoiced_count'];
        }

        // ── کلینیک‌ها: هر کلینیکی که کیسِ clinic_id دارد یا فاکتور INV-CLN ──
        $clinicIds = [];
        foreach (db()->query("SELECT id FROM users WHERE role = 'clinic'")->fetchAll() as $r) { $clinicIds[(int) $r['id']] = true; }
        foreach (db()->query("SELECT DISTINCT clinic_id FROM cases WHERE clinic_id IS NOT NULL")->fetchAll() as $r) { $clinicIds[(int) $r['clinic_id']] = true; }
        foreach (db()->query("SELECT DISTINCT doctor_id FROM doctor_invoices WHERE invoice_number LIKE 'INV-CLN%' AND doctor_id IS NOT NULL")->fetchAll() as $r) { $clinicIds[(int) $r['doctor_id']] = true; }
        foreach (array_keys($clinicIds) as $uid) {
            if ($uid <= 0) continue;
            $b = partyDebtBreakdown('clinic', $uid, $dashBranchOpt);
            $debtBreak['clinic']['invoiced']      = ($debtBreak['clinic']['invoiced'] ?? 0) + $b['invoiced'];
            $debtBreak['clinic']['uninvoiced']    = ($debtBreak['clinic']['uninvoiced'] ?? 0) + $b['uninvoiced'];
            $debtBreak['clinic']['invoiced_count']   = ($debtBreak['clinic']['invoiced_count'] ?? 0) + $b['invoice_count'];
            $debtBreak['clinic']['uninvoiced_count'] = ($debtBreak['clinic']['uninvoiced_count'] ?? 0) + $b['uninvoiced_count'];
        }

        // ── لابراتوارهای همکار: هر لابراتواری که فاکتور INV-LAB دارد یا کیس به آن سپرده شده ──
        $labIds = [];
        foreach (db()->query("SELECT id FROM users WHERE role IN ('lab','partner_lab','customer_lab','outsource_lab')")->fetchAll() as $r) { $labIds[(int) $r['id']] = true; }
        foreach (db()->query("SELECT lab_id AS uid FROM cases WHERE lab_id IS NOT NULL UNION SELECT outsourced_lab_id FROM cases WHERE outsourced_lab_id IS NOT NULL")->fetchAll() as $r) { $labIds[(int) $r['uid']] = true; }
        foreach (db()->query("SELECT DISTINCT doctor_id FROM doctor_invoices WHERE invoice_number LIKE 'INV-LAB%' AND doctor_id IS NOT NULL")->fetchAll() as $r) { $labIds[(int) $r['doctor_id']] = true; }
        foreach (array_keys($labIds) as $uid) {
            if ($uid <= 0) continue;
            $b = partyDebtBreakdown('lab', $uid, $dashBranchOpt);
            $debtBreak['lab']['invoiced']      = ($debtBreak['lab']['invoiced'] ?? 0) + $b['invoiced'];
            $debtBreak['lab']['uninvoiced']    = ($debtBreak['lab']['uninvoiced'] ?? 0) + $b['uninvoiced'];
            $debtBreak['lab']['invoiced_count']   = ($debtBreak['lab']['invoiced_count'] ?? 0) + $b['invoice_count'];
            $debtBreak['lab']['uninvoiced_count'] = ($debtBreak['lab']['uninvoiced_count'] ?? 0) + $b['uninvoiced_count'];
        }
    } catch (\Throwable $e) {
        // اگر جداول مرتبط نبودند، داشبورد نباید بشکند.
        $debtBreak = ['doctor' => [], 'clinic' => [], 'lab' => []];
    }
    $debtLabels = ['doctor' => 'پزشکان', 'clinic' => 'کلینیک‌ها', 'lab' => 'لابراتوارهای همکار'];
    $debtSum = ['invoiced' => 0.0, 'uninvoiced' => 0.0, 'invoiced_count' => 0, 'uninvoiced_count' => 0];
    foreach ($debtBreak as $k => $v) {
        $debtSum['invoiced']         += (float) ($v['invoiced'] ?? 0);
        $debtSum['uninvoiced']       += (float) ($v['uninvoiced'] ?? 0);
        $debtSum['invoiced_count']   += (int) ($v['invoiced_count'] ?? 0);
        $debtSum['uninvoiced_count'] += (int) ($v['uninvoiced_count'] ?? 0);
    }
    $debtSum['total'] = $debtSum['invoiced'] + $debtSum['uninvoiced'];
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

    <!-- ─── تفکیک بدهی: فاکتورشده در برابر فاکتورنشده ─── -->
    <div class="form-card" style="margin-bottom:24px;">
        <h4 style="margin:0 0 12px; display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap;">
            <span>💰 بدهی طرف‌حساب‌ها — تفکیک «فاکتورشده» و «فاکتورنشده»</span>
            <span style="font-size:0.85rem; color:#64748b; font-weight:400;">
                جمع کل: <strong style="color:#ef4444;"><?= formatAmountToman($debtSum['total']) ?></strong>
            </span>
        </h4>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(230px, 1fr)); gap:12px; margin-bottom:14px;">
            <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:12px;">
                <div style="font-size:0.85rem; color:#991b1b; font-weight:700;">بدهی فاکتورشده</div>
                <div style="font-size:1.25rem; font-weight:700; color:<?= $debtSum['invoiced'] < -0.01 ? '#166534' : '#b91c1c' ?>; margin-top:4px;"><?= formatAmountToman($debtSum['invoiced']) ?></div>
                <small style="color:#991b1b;">مجموع فاکتورها − مجموع پرداختی‌ها</small>
            </div>
            <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:12px;">
                <div style="font-size:0.85rem; color:#92400e; font-weight:700;">بدهی فاکتورنشده</div>
                <div style="font-size:1.25rem; font-weight:700; color:<?= $debtSum['uninvoiced'] < -0.01 ? '#166534' : '#b45309' ?>; margin-top:4px;"><?= formatAmountToman($debtSum['uninvoiced']) ?></div>
                <small style="color:#92400e;">بدهی کل <?= formatAmountToman($debtSum['total']) ?> − فاکتورشده <?= formatAmountToman($debtSum['invoiced']) ?></small>
            </div>
            <div style="background:#f1f5f9; border:1px solid #cbd5e1; border-radius:10px; padding:12px;">
                <div style="font-size:0.85rem; color:#334155; font-weight:700;">جمع کل بدهی</div>
                <div style="font-size:1.25rem; font-weight:700; color:<?= $debtSum['total'] < -0.01 ? '#166534' : '#0f172a' ?>; margin-top:4px;"><?= formatAmountToman($debtSum['total']) ?></div>
                <small style="color:#475569;">فاکتورشده + فاکتورنشده</small>
            </div>
        </div>

        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>نوع طرف‌حساب</th>
                        <th class="th-price">بدهی فاکتورشده</th>
                        <th class="th-narrow">تعداد فاکتور</th>
                        <th class="th-price">بدهی فاکتورنشده</th>
                        <th class="th-narrow">تعداد کیس</th>
                        <th class="th-price">جمع</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($debtLabels as $dk => $dl):
                    $dv = $debtBreak[$dk] ?? [];
                    $inv = (float) ($dv['invoiced'] ?? 0);
                    $uninv = (float) ($dv['uninvoiced'] ?? 0);
                    $amtCell = function (float $v) {
                        if (abs($v) < 0.01) return '<span style="color:#94a3b8;">—</span>';
                        $c = $v < 0 ? '#166534' : '#b91c1c';
                        return '<span style="color:' . $c . ';">' . formatAmountToman($v) . '</span>';
                    };
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($dl) ?></strong></td>
                        <td><?= $amtCell($inv) ?></td>
                        <td><?= toPersianDigits((string) (int) ($dv['invoiced_count'] ?? 0)) ?></td>
                        <td><?= $amtCell($uninv) ?></td>
                        <td><?= toPersianDigits((string) (int) ($dv['uninvoiced_count'] ?? 0)) ?></td>
                        <td style="font-weight:700; color:<?= ($inv + $uninv) < -0.01 ? '#166534' : '#0f172a' ?>;"><?= formatAmountToman($inv + $uninv) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f8fafc; font-weight:700;">
                        <td>جمع کل</td>
                        <td><?= formatAmountToman($debtSum['invoiced']) ?></td>
                        <td><?= toPersianDigits((string) $debtSum['invoiced_count']) ?></td>
                        <td><?= formatAmountToman($debtSum['uninvoiced']) ?></td>
                        <td><?= toPersianDigits((string) $debtSum['uninvoiced_count']) ?></td>
                        <td style="color:<?= $debtSum['total'] < -0.01 ? '#166534' : '#ef4444' ?>;"><?= formatAmountToman($debtSum['total']) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p style="margin:10px 0 0; font-size:0.82rem; color:#64748b;">
            ✅ هر کیس فقط یک پرداخت‌کننده دارد: با <code>lab_id</code> ← لابراتوار؛
            وگرنه با <code>clinic_id</code> ← کلینیک؛ وگرنه ← پزشک.<br>
            <b>بدهی کل</b> = هزینهٔ کیس‌های خودش − پرداختی &nbsp;|&nbsp;
            <b>بدهی فاکتورشده</b> = مبلغ فاکتورهای خودش − پرداختی<br>
            <b>بدهی فاکتورنشده</b> = بدهی کل − بدهی فاکتورشده (پرداختی فقط یک بار کسر می‌شود).<br>
            عدد <b style="color:#166534;">منفی</b> یعنی طرف‌حساب بستانکار است.
            کیس‌های لغوشده در محاسبه نمی‌آیند. گزارش لحظه‌ای (مستقل از بازهٔ تاریخ).
        </p>
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

    // تفکیک بدهی: فاکتورشده (ماندهٔ فاکتورهای پرداخت‌نشده) + فاکتورنشده (کیس‌های بدون فاکتور)
    $myDebt = partyDebtBreakdown('doctor', (int) $user['id']);
    $totalDebt = $myDebt['total'];
    ?>
    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); margin-bottom: 32px;">
        <div class="card" style="text-align: center; border-right: 4px solid #06B6D4;">
            <h4 style="margin:0; color:#525252; font-size:0.9rem;">کل کیس‌ها</h4>
            <p style="font-size:2rem; margin:8px 0 0; font-weight:700;"><?= toPersianDigits($totalCases) ?></p>
        </div>
        <?php if (abs($totalDebt) > 0.01): ?>
        <div class="card" style="text-align: center; border-right: 4px solid <?= $totalDebt > 0 ? '#ef4444' : '#10b981' ?>">
            <h4 style="margin:0; color:#525252; font-size:0.9rem;">جمع بدهی</h4>
            <p style="font-size:1.2rem; margin:8px 0 0; font-weight:700; color:<?= $totalDebt > 0 ? '#ef4444' : '#166534' ?>;"><?= formatAmountToman($totalDebt) ?></p>
        </div>
        <?php endif; ?>
    </div>

    <?php if (abs($myDebt['invoiced_raw']) > 0.01 || abs($myDebt['uninvoiced_raw']) > 0.01): ?>
    <!-- تفکیک بدهی: فاکتورشده / فاکتورنشده -->
    <div class="form-card" style="margin-bottom:24px;">
        <h4 style="margin:0 0 12px;">💰 تفکیک بدهی شما</h4>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px;">
            <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:12px;">
                <div style="font-size:0.85rem; color:#991b1b; font-weight:700;">بدهی فاکتورشده</div>
                <div style="font-size:1.15rem; font-weight:700; color:<?= $myDebt['invoiced'] < -0.01 ? '#166534' : '#b91c1c' ?>; margin-top:4px;"><?= formatAmountToman($myDebt['invoiced']) ?></div>
                <small style="color:#991b1b;">فاکتورها <?= formatAmountToman($myDebt['invoiced_raw']) ?> − پرداختی <?= formatAmountToman($myDebt['paid']) ?></small>
            </div>
            <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:12px;">
                <div style="font-size:0.85rem; color:#92400e; font-weight:700;">بدهی فاکتورنشده</div>
                <div style="font-size:1.15rem; font-weight:700; color:<?= $myDebt['uninvoiced'] < -0.01 ? '#166534' : '#b45309' ?>; margin-top:4px;"><?= formatAmountToman($myDebt['uninvoiced']) ?></div>
                <small style="color:#92400e;">هزینهٔ <?= toPersianDigits((string) $myDebt['uninvoiced_count']) ?> کیس <?= formatAmountToman($myDebt['uninvoiced_raw']) ?> − فاکتورها <?= formatAmountToman($myDebt['invoiced_raw']) ?></small>
            </div>
            <div style="background:#f1f5f9; border:1px solid #cbd5e1; border-radius:10px; padding:12px;">
                <div style="font-size:0.85rem; color:#334155; font-weight:700;">بدهی کل</div>
                <div style="font-size:1.15rem; font-weight:700; color:<?= $myDebt['total'] < -0.01 ? '#166534' : '#0f172a' ?>; margin-top:4px;"><?= formatAmountToman($myDebt['total']) ?></div>
                <small style="color:#64748b;">هزینهٔ کیس‌ها <?= formatAmountToman($myDebt['uninvoiced_raw']) ?> − پرداختی <?= formatAmountToman($myDebt['paid']) ?></small>
            </div>
        </div>
        <p style="margin:10px 0 0; font-size:0.82rem; color:#64748b;">
            «بدهی کل» = مجموع هزینهٔ کیس‌های خودتان منهای مجموع پرداختی‌ها.
            «فاکتورشده» = مبلغ فاکتورهای خودتان منهای مجموع پرداختی‌ها.
            «فاکتورنشده» = بدهی کل − بدهی فاکتورشده؛ پرداختی فقط یک بار کسر می‌شود.
            کیس‌هایی که به لابراتوار سپرده شده‌اند از حساب شما خارج‌اند.
            عدد منفی یعنی بیشتر از بدهی پرداخت کرده‌اید (بستانکار).
        </p>
    </div>
    <?php endif; ?>
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
        <div class="card">
            <h3>پروفایل من</h3>
            <a class="btn" href="my_profile.php">ویرایش مشخصات و رمز عبور</a>
        </div>
    </div>
    <?php
} elseif (in_array($role, ['clinic', 'lab', 'partner_lab', 'customer_lab', 'outsource_lab'], true)
          || has_permission('view_clinic_cases') || has_permission('view_assigned_cases')) {
    // ─── کلینیک / لابراتوارِ همکار: تفکیک بدهی فاکتورشده و فاکتورنشده ───
    $party = ($role === 'clinic' || has_permission('view_clinic_cases')) ? 'clinic' : 'lab';
    $partyLabel = $party === 'clinic' ? 'کلینیک شما' : 'لابراتوار شما';
    $myDebt = partyDebtBreakdown($party, (int) $user['id'], [
        'clinic_doctor_ids' => $party === 'clinic' && function_exists('getClinicDoctorIds')
            ? getClinicDoctorIds((int) $user['id'])
            : [],
    ]);
    ?>
    <?php if (abs($myDebt['invoiced_raw']) > 0.01 || abs($myDebt['uninvoiced_raw']) > 0.01): ?>
    <div class="form-card" style="margin-bottom:24px;">
        <h4 style="margin:0 0 12px;">💰 تفکیک بدهی <?= htmlspecialchars($partyLabel) ?></h4>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:12px;">
            <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:12px;">
                <div style="font-size:0.85rem; color:#991b1b; font-weight:700;">بدهی فاکتورشده</div>
                <div style="font-size:1.15rem; font-weight:700; color:<?= $myDebt['invoiced'] < -0.01 ? '#166534' : '#b91c1c' ?>; margin-top:4px;"><?= formatAmountToman($myDebt['invoiced']) ?></div>
                <small style="color:#991b1b;">فاکتورها <?= formatAmountToman($myDebt['invoiced_raw']) ?> − پرداختی <?= formatAmountToman($myDebt['paid']) ?></small>
            </div>
            <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:12px;">
                <div style="font-size:0.85rem; color:#92400e; font-weight:700;">بدهی فاکتورنشده</div>
                <div style="font-size:1.15rem; font-weight:700; color:<?= $myDebt['uninvoiced'] < -0.01 ? '#166534' : '#b45309' ?>; margin-top:4px;"><?= formatAmountToman($myDebt['uninvoiced']) ?></div>
                <small style="color:#92400e;">هزینهٔ <?= toPersianDigits((string) $myDebt['uninvoiced_count']) ?> کیس <?= formatAmountToman($myDebt['uninvoiced_raw']) ?> − فاکتورها <?= formatAmountToman($myDebt['invoiced_raw']) ?></small>
            </div>
            <div style="background:#f1f5f9; border:1px solid #cbd5e1; border-radius:10px; padding:12px;">
                <div style="font-size:0.85rem; color:#334155; font-weight:700;">بدهی کل</div>
                <div style="font-size:1.15rem; font-weight:700; color:<?= $myDebt['total'] < -0.01 ? '#166534' : '#0f172a' ?>; margin-top:4px;"><?= formatAmountToman($myDebt['total']) ?></div>
                <small style="color:#64748b;">هزینهٔ کیس‌ها <?= formatAmountToman($myDebt['uninvoiced_raw']) ?> − پرداختی <?= formatAmountToman($myDebt['paid']) ?></small>
            </div>
        </div>
        <p style="margin:0; font-size:0.82rem; color:#64748b;">
            «بدهی کل» = مجموع هزینهٔ کیس‌های خودتان منهای مجموع پرداختی‌ها.
            «فاکتورشده» = مبلغ فاکتورهای خودتان منهای مجموع پرداختی‌ها.
            «فاکتورنشده» = بدهی کل − بدهی فاکتورشده؛ پرداختی فقط یک بار کسر می‌شود.
            کیس‌هایی که به لابراتوار سپرده شده‌اند از حساب شما خارج‌اند.
        </p>
    </div>
    <?php endif; ?>
    <div class="grid">
        <div class="card">
            <h3>کیس‌ها</h3>
            <a class="btn" href="cases.php">مشاهده</a>
        </div>
        <div class="card">
            <h3>فاکتورها</h3>
            <a class="btn" href="invoices.php">مشاهده</a>
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