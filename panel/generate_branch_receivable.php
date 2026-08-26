<?php
// panel/generate_branch_receivable.php
// Receivable invoice to a partner branch: for inbound cross-branch cases
// (a partner branch outsourced work to US), we bill the partner branch for the
// outsource amount they owe us. Mirror of the creating branch's outsource invoice.
require_once __DIR__ . '/auth.php';
require_admin();
if (!is_branch_scoped()) {
    die('این صفحه مخصوص مدیران شعبه است.');
}

use Morilog\Jalali\Jalalian;

// Partner branches = branches other than our own
$myBranchId = currentBranchId();
$branches = array_filter(getAllBranches(), function ($b) use ($myBranchId) {
    return (int) $b['id'] !== (int) $myBranchId;
});

$message = '';
$generatedInvoiceId = null;

$branchId = isset($_POST['branch_id']) ? (int) $_POST['branch_id'] : 0;
$month = isset($_POST['month']) ? (int) $_POST['month'] : 0;
$year = isset($_POST['year']) ? (int) $_POST['year'] : 0;
$selectedCaseIds = isset($_POST['case_ids']) && is_array($_POST['case_ids']) ? array_map('intval', $_POST['case_ids']) : [];
$periodType = isset($_POST['period_type']) ? (string) $_POST['period_type'] : 'monthly';
if (!in_array($periodType, ['monthly', 'custom'], true)) $periodType = 'monthly';
$dateFrom = trim((string) ($_POST['date_from'] ?? ''));
$dateTo = trim((string) ($_POST['date_to'] ?? ''));

$nowJalali = Jalalian::now();
$defaultYear = $nowJalali->getYear();
$defaultMonth = $nowJalali->getMonth();

$jalaliMonths = [
    1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
    5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
    9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
];

$selectedCasesOnly = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_selected']));

// ─── Preview / Generate ───
if (($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) || $selectedCasesOnly) {
    $startDate = '';
    $endDate = '';
    $periodLabel = '';
    if ($periodType === 'custom') {
        $startDate = parseJalaliToGregorian($dateFrom);
        $endDate = parseJalaliToGregorian($dateTo);
        if ($startDate === '' || $endDate === '') {
            $message = 'لطفاً تاریخ شروع و پایان (بازه) را وارد کنید.';
        } elseif ($startDate > $endDate) {
            $message = 'تاریخ شروع نباید از تاریخ پایان بزرگ‌تر باشد.';
        } else {
            $periodLabel = toJalaliDateFormatted($startDate) . ' تا ' . toJalaliDateFormatted($endDate);
        }
    } elseif ($branchId && $month && $year) {
        if ($year < 1300 || $year > 1500 || $month < 1 || $month > 12) {
            $message = 'سال یا ماه نامعتبر است.';
        } else {
            $jalaliDateStr = sprintf('%04d/%02d/01', $year, $month);
            try {
                $jalaliStart = Jalalian::fromFormat('Y/m/d', $jalaliDateStr);
                $startDate = $jalaliStart->toCarbon()->toDateString();
                $endDate = $jalaliStart->addMonths(1)->subDay()->toCarbon()->toDateString();
                $periodLabel = $jalaliMonths[$month] . ' ' . $year;
            } catch (\Exception $e) {
                $message = 'تاریخ نامعتبر: ' . $e->getMessage();
            }
        }
    } else {
        $message = 'لطفاً شعبه همکار و بازه زمانی را انتخاب کنید.';
    }

    if (empty($message) && $startDate !== '' && $endDate !== '') {
        $cases = getUninvoicedInboundPartnerCases($branchId ?: null, $startDate, $endDate);

        if ($selectedCasesOnly && !empty($selectedCaseIds)) {
            $cases = array_values(array_filter($cases, function ($c) use ($selectedCaseIds) {
                return in_array((int) $c['id'], $selectedCaseIds);
            }));
        }

        if (empty($cases)) {
            $message = 'هیچ کیس برون‌سپاری‌شده‌ای از این شعبه (فاکتورنشده) در بازه انتخابی وجود ندارد.';
        } else {
            $total = 0;
            foreach ($cases as $c) {
                $total += round(((float) ($c['unit_rate'] ?? 0)) * (int) ($c['_bill_qty'] ?? 1));
            }
            $partner = getBranch($branchId);
            $partnerName = $partner['name'] ?? 'شعبه همکار';
            // group by doctor for display
            $grouped = [];
            foreach ($cases as $c) {
                $gKey = (int) ($c['doctor_id'] ?? 0);
                $grouped[$gKey][] = $c;
            }
            ?>
            <!DOCTYPE html>
            <html dir="rtl" lang="fa">
            <head>
                <meta charset="UTF-8">
                <title>پیش‌نمایش فاکتور طلب از شعبه</title>
                <link rel="stylesheet" href="../assets/css/style.css">
                <style>
                    .preview-box { background:#f9fafb; padding:20px; border-radius:12px; margin:20px 0; }
                    .preview-box table { width:100%; border-collapse:collapse; }
                    .preview-box th,.preview-box td { padding:8px 12px; border:1px solid #e5e7eb; text-align:right; }
                    .total-row { font-weight:bold; background:#e5e7eb; }
                    .case-checkbox { width:20px; height:20px; cursor:pointer; }
                    .doctor-group-title { background:#eef2ff; font-weight:bold; padding:8px 12px; border:1px solid #e5e7eb; }
                    .warn-missing { color:#b45309; font-size:12px; }
                </style>
            </head>
            <body>
            <div class="container" style="max-width:950px; margin:40px auto;">
                <h2>پیش‌نمایش فاکتور طلب از شعبه</h2>
                <p><strong>شعبه همکار (بدهکار):</strong> <?= htmlspecialchars($partnerName) ?></p>
                <p><strong>بازه:</strong> <?= htmlspecialchars($periodLabel) ?></p>
                <div class="preview-box">
                    <form id="preview-form" method="post" action="">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token ?? ($_SESSION['_csrf_token'] ?? '')) ?>">
                        <input type="hidden" name="branch_id" value="<?= (int) $branchId ?>">
                        <input type="hidden" name="month" value="<?= (int) $month ?>">
                        <input type="hidden" name="year" value="<?= (int) $year ?>">
                        <input type="hidden" name="period_type" value="<?= htmlspecialchars($periodType) ?>">
                        <?php if ($periodType === 'custom'): ?>
                        <input type="hidden" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                        <input type="hidden" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                        <?php endif; ?>
                        <?php foreach ($grouped as $docId => $docCases): ?>
                            <?php $docName = $docCases[0]['doctor_name'] ?: '—'; ?>
                            <div class="doctor-group-title">پزشک: <?= htmlspecialchars($docName) ?></div>
                            <table>
                                <thead>
                                <tr>
                                    <th style="width:36px;">انتخاب</th>
                                    <th>کیس</th>
                                    <th>بیمار</th>
                                    <th>خدمت</th>
                                    <th>تعداد</th>
                                    <th>نرخ (تومان)</th>
                                    <th>جمع (تومان)</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($docCases as $c): ?>
                                    <tr>
                                        <td><input type="checkbox" class="case-checkbox" name="case_ids[]" value="<?= (int) $c['id'] ?>" checked></td>
                                        <td>#<?= (int) $c['id'] ?></td>
                                        <td><?= htmlspecialchars($c['patient_name'] ?: '—') ?></td>
                                        <td><?= htmlspecialchars($c['_bill_service_title'] ?: '—') ?></td>
                                        <td><?= toPersianDigits((int) $c['_bill_qty']) ?></td>
                                        <td><?= formatAmountToman((float) $c['unit_rate']) ?><?php if ($c['unit_rate'] === null): ?> <span class="warn-missing">(نرخ ثبت نشده)</span><?php endif; ?></td>
                                        <td><?= formatAmountToman((float) $c['_bill_amount']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endforeach; ?>
                        <p style="margin-top:14px; font-weight:bold;">جمع کل: <span style="color:#166534;"><?= formatAmountToman($total) ?> تومان</span></p>
                        <div style="display:flex; gap:10px; margin-top:16px;">
                            <button type="submit" name="preview_selected" value="1" style="background:#6366f1; color:#fff;">فاکتور موارد انتخاب‌شده</button>
                            <button type="submit" name="generate" value="1" style="background:#059669; color:#fff;">صدور فاکتور (همه موارد)</button>
                            <a href="generate_branch_receivable.php" class="btn" style="background:#E5E7EB; color:#0F172A;">تغییر بازه/شعبه</a>
                        </div>
                    </form>
                </div>
            </div>
            </body>
            </html>
            <?php
            exit;
        }
    }

    // ─── Create ───
    if (empty($message) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate']) && $startDate !== '' && $endDate !== '' && $branchId) {
        $cases = getUninvoicedInboundPartnerCases($branchId, $startDate, $endDate);
        if (!empty($cases)) {
            $generatedInvoiceId = createBranchReceivable($branchId, $cases, date('Y-m-d'), $periodLabel);
            $message = 'فاکتور طلب از شعبه با موفقیت صادر شد.';
        }
    }
}

panel_layout_start('صدور فاکتور طلب از شعبه');
?>
<div style="margin-bottom:18px; display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn" href="branch_receivables.php" style="background:#0F172A; color:#fff;">فهرست فاکتورهای طلب</a>
    <a class="btn" href="case_expenses.php" style="background:#E5E7EB; color:#0F172A;">کیس‌های مخارج</a>
    <a class="btn" href="expenses.php" style="background:#E5E7EB; color:#0F172A;">فاکتورهای مخارج (بدهی‌ها)</a>
</div>

<?php if ($message): ?>
    <div class="form-card" style="background:<?= $generatedInvoiceId ? '#f0fdf4' : '#fffbeb' ?>; border:1px solid <?= $generatedInvoiceId ? '#bbf7d0' : '#fde68a' ?>; padding:14px 18px; margin-bottom:18px;">
        <?= htmlspecialchars($message) ?>
        <?php if ($generatedInvoiceId): ?>
            <br><a href="branch_receivable_pdf.php?id=<?= (int) $generatedInvoiceId ?>" target="_blank" class="btn" style="margin-top:10px;">مشاهده PDF</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="form-card">
    <h3 style="margin-bottom:16px;">صدور فاکتور طلب از شعبه همکار</h3>
    <p style="color:#525252; margin-bottom:16px;">کیس‌هایی که شعبه همکار به شما برون‌سپاری کرده است (کار از لابراتوار همکار) — مبلغ نرخ برون‌سپاری را به شما بدهکار است. با این فرم همان مبلغ را به‌صورت فاکتور طلب صادر می‌کنید.</p>
    <form method="post" action="" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:14px;">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token ?? ($_SESSION['_csrf_token'] ?? '')) ?>">
        <div class="form-group" style="margin:0;">
            <label>شعبه همکار (بدهکار)</label>
            <select name="branch_id" required>
                <option value="">انتخاب شعبه...</option>
                <?php foreach ($branches as $b): ?>
                    <option value="<?= (int) $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label>بازه زمانی</label>
            <select name="period_type" id="receivable-period-type" onchange="document.getElementById('receivable-month-row').style.display=this.value==='monthly'?'':'none';document.getElementById('receivable-custom-row').style.display=this.value==='custom'?'':'none';">
                <option value="monthly">ماهانه</option>
                <option value="custom">بازه دلخواه</option>
            </select>
        </div>
        <div class="form-group" style="margin:0;" id="receivable-month-row">
            <label>ماه / سال</label>
            <div style="display:flex; gap:8px;">
                <select name="month" style="flex:1;">
                    <?php foreach ($jalaliMonths as $mn => $mnLabel): ?>
                        <option value="<?= $mn ?>" <?= $mn === $defaultMonth ? 'selected' : '' ?>><?= $mnLabel ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="year" value="<?= $defaultYear ?>" min="1300" max="1500" style="width:90px;">
            </div>
        </div>
        <div class="form-group" style="margin:0; display:none;" id="receivable-custom-row">
            <label>از تاریخ (تا تاریخ)</label>
            <div style="display:flex; gap:8px;">
                <input type="text" name="date_from" placeholder="۱۴۰۳/۰۱/۰۱">
                <input type="text" name="date_to" placeholder="۱۴۰۳/۰۱/۳۱">
            </div>
        </div>
        <div class="form-group" style="margin:0; align-self:end;">
            <button type="submit" name="generate" value="1" style="background:#059669; color:#fff;">پیش‌نمایش و صدور</button>
        </div>
    </form>
</div>
<?php panel_layout_end(); ?>
