<?php
// panel/generate_lab_invoice.php
// Monthly invoice for partner/outsource labs, grouped by doctor, with +/- amounts
require_once __DIR__ . '/auth.php';
require_role('admin');

use Morilog\Jalali\Jalalian;

$labs = db()->query("SELECT id, full_name FROM users WHERE role IN ('outsource_lab','partner_lab','customer_lab','lab') AND active=1 ORDER BY full_name")->fetchAll();
$bankAccounts = getAllBankAccounts();
$message = '';
$generatedInvoiceId = null;

$labId = isset($_POST['lab_id']) ? (int)$_POST['lab_id'] : 0;
$month = isset($_POST['month']) ? (int)$_POST['month'] : 0;
$year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
$bankAccountId = isset($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : 0;
$selectedCaseIds = isset($_POST['case_ids']) && is_array($_POST['case_ids']) ? array_map('intval', $_POST['case_ids']) : [];
$periodType = isset($_POST['period_type']) ? (string)$_POST['period_type'] : 'monthly';
if (!in_array($periodType, ['monthly', 'custom'], true)) $periodType = 'monthly';
$dateFrom = trim((string)($_POST['date_from'] ?? ''));
$dateTo = trim((string)($_POST['date_to'] ?? ''));

$nowJalali = Jalalian::now();
$defaultYear = $nowJalali->getYear();
$defaultMonth = $nowJalali->getMonth();
$prevMonth = $defaultMonth > 1 ? $defaultMonth - 1 : 12;
$prevYear = $defaultMonth > 1 ? $defaultYear : $defaultYear - 1;

$jalaliMonths = [
    1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
    5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
    9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
];

$selectedCasesOnly = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_selected']));

// ─── Preview ───
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
    } elseif ($labId && $month && $year) {
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
        $message = 'لطفاً لابراتوار و بازه زمانی را انتخاب کنید.';
    }

    if (empty($message) && $startDate !== '' && $endDate !== '') {
                $cases = getUninvoicedCasesForLab($labId, $startDate, $endDate);

                if ($selectedCasesOnly && !empty($selectedCaseIds)) {
                    $cases = array_values(array_filter($cases, function($c) use ($selectedCaseIds) {
                        return in_array((int)$c['id'], $selectedCaseIds);
                    }));
                }

                if (empty($cases)) {
                    $message = 'هیچ کیس فاکتورنشده‌ای برای این لابراتوار در بازه انتخابی وجود ندارد.';
                } else {
                    // Group by doctor
                    $groups = [];
                    foreach ($cases as $c) {
                        $gKey = $c['doctor_id'] ?: 0;
                        $gName = $c['doctor_name'] ?: 'بدون پزشک';
                        if (!isset($groups[$gKey])) $groups[$gKey] = ['name' => $gName, 'cases' => []];
                        $groups[$gKey]['cases'][] = $c;
                    }
                    $total = 0;
                    foreach ($cases as $c) {
                        $price = getLabApplicablePrice($labId, (int)$c['service_id']);
                        $sign = ($c['case_type'] === 'lab_out') ? -1 : 1;
                        $total += $price * (int)($c['quantity'] ?? 1) * $sign;
                    }

                    $lab = db()->prepare('SELECT full_name FROM users WHERE id = ?');
                    $lab->execute([$labId]);
                    $labName = $lab->fetchColumn() ?: 'لابراتوار';
                    ?>
                    <!DOCTYPE html>
                    <html dir="rtl" lang="fa">
                    <head>
                        <meta charset="UTF-8">
                        <title>پیش‌نمایش فاکتور لابراتوار</title>
                        <link rel="stylesheet" href="../assets/css/style.css">
                        <style>
                            .preview-box { background:#f9fafb; padding:20px; border-radius:12px; margin:20px 0; }
                            .preview-box table { width:100%; border-collapse:collapse; }
                            .preview-box th,.preview-box td { padding:8px 12px; border:1px solid #e5e7eb; text-align:right; }
                            .doctor-section { background:#0F172A; color:#fff; padding:8px 12px; margin-top:14px; border-radius:6px; font-weight:bold; }
                            .total-row { font-weight:bold; background:#e5e7eb; }
                            .neg { color:#b91c1c; }
                            .pos { color:#166534; }
                        </style>
                    </head>
                    <body>
                    <div class="container" style="max-width:900px; margin:40px auto;">
                        <h2>پیش‌نمایش فاکتور لابراتوار</h2>
                        <p><strong>لابراتوار:</strong> <?= htmlspecialchars($labName) ?></p>
                        <p><strong>بازه:</strong> <?= htmlspecialchars($periodLabel) ?></p>
                        <div class="preview-box">
                            <form id="preview-form" method="post" action="">
                                <input type="hidden" name="lab_id" value="<?= $labId ?>">
                                <input type="hidden" name="period_type" value="<?= htmlspecialchars($periodType) ?>">
                                <input type="hidden" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                                <input type="hidden" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                                <input type="hidden" name="month" value="<?= $month ?>">
                                <input type="hidden" name="year" value="<?= $year ?>">
                                <input type="hidden" name="bank_account_id" value="<?= $bankAccountId ?>">
                                <?php foreach ($groups as $gKey => $group): ?>
                                    <div class="doctor-section">پزشک: <?= htmlspecialchars($group['name']) ?></div>
                                    <table>
                                        <thead><tr><th style="width:40px;">انتخاب</th><th>#</th><th>بیمار</th><th>خدمت</th><th>شرح</th><th>تعداد</th><th>قیمت</th><th>جمع</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($group['cases'] as $c):
                                            $price = getLabApplicablePrice($labId, (int)$c['service_id']);
                                            $sign = ($c['case_type'] === 'lab_out') ? -1 : 1;
                                            $lineTotal = round($price * (int)($c['quantity'] ?? 1) * $sign);
                                            $cls = $sign < 0 ? 'neg' : 'pos';
                                        ?>
                                            <tr>
                                                <td><input type="checkbox" class="case-checkbox" name="case_ids[]" value="<?= $c['id'] ?>" checked></td>
                                                <td><?= $c['id'] ?></td>
                                                <td><?= htmlspecialchars($c['patient_name']) ?></td>
                                                <td><?= htmlspecialchars($c['service_title'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($c['case_type'] === 'lab_out' ? 'برونسپاری' : 'کار از لابراتوار') ?></td>
                                                <td><?= toPersianDigits($c['quantity'] ?? 1) ?></td>
                                                <td class="<?= $cls ?>"><?= formatAmountToman($price * $sign) ?></td>
                                                <td class="<?= $cls ?>"><?= formatAmountToman($lineTotal) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endforeach; ?>
                                <div style="margin-top:14px; font-weight:bold; font-size:1.1rem;">
                                    جمع کل: <span class="<?= $total < 0 ? 'neg' : 'pos' ?>"><?= formatAmountToman($total) ?></span>
                                </div>
                                <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
                                    <button type="submit" name="confirm" value="1" class="btn" style="background:#06B6D4;">تأیید و ایجاد فاکتور</button>
                                    <button type="submit" name="preview_selected" value="1" class="btn" style="background:#0F172A; color:#fff;">به‌روزرسانی انتخاب</button>
                                    <a href="generate_lab_invoice.php" class="btn" style="background:#E5E7EB; color:#0F172A;">تغییر بازه/لابراتوار</a>
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
}

// ─── Confirm (create) ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $_POST['confirm'] == 1) {
    $labId = (int)$_POST['lab_id'];
    $month = (int)$_POST['month'];
    $year = (int)$_POST['year'];
    $bankAccountId = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
    $selectedCaseIds = isset($_POST['case_ids']) && is_array($_POST['case_ids']) ? array_map('intval', $_POST['case_ids']) : [];
    $periodType = isset($_POST['period_type']) ? (string)$_POST['period_type'] : 'monthly';
    if (!in_array($periodType, ['monthly', 'custom'], true)) $periodType = 'monthly';
    $dateFrom = trim((string)($_POST['date_from'] ?? ''));
    $dateTo = trim((string)($_POST['date_to'] ?? ''));
    $startDate = '';
    $endDate = '';
    $periodLabel = '';
    if ($periodType === 'custom') {
        $startDate = parseJalaliToGregorian($dateFrom);
        $endDate = parseJalaliToGregorian($dateTo);
        if ($startDate === '' || $endDate === '') {
            $message = 'لطفاً تاریخ شروع و پایان (بازه) را وارد کنید.';
            goto show_message;
        }
        $periodLabel = toJalaliDateFormatted($startDate) . ' تا ' . toJalaliDateFormatted($endDate);
    } else {
        $jalaliDateStr = sprintf('%04d/%02d/01', $year, $month);
        try {
            $jalaliStart = Jalalian::fromFormat('Y/m/d', $jalaliDateStr);
            $startDate = $jalaliStart->toCarbon()->toDateString();
            $endDate = $jalaliStart->addMonths(1)->subDay()->toCarbon()->toDateString();
            $periodLabel = $jalaliMonths[$month] . ' ' . $year;
        } catch (\Exception $e) {
            $message = 'تاریخ نامعتبر';
            goto show_message;
        }
    }
    $cases = getUninvoicedCasesForLab($labId, $startDate, $endDate);
    if (!empty($selectedCaseIds)) {
        $cases = array_values(array_filter($cases, function($c) use ($selectedCaseIds) {
            return in_array((int)$c['id'], $selectedCaseIds);
        }));
    }

    if (!empty($cases)) {
        $invoiceId = createMonthlyLabInvoice($labId, $cases, 0, date('Y-m-d'), $bankAccountId, $periodLabel);
        $generatedInvoiceId = $invoiceId;
        $message = 'فاکتور لابراتوار با موفقیت ایجاد شد.';
    } else {
        $message = 'هیچ موردی برای فاکتور وجود ندارد.';
    }
}
show_message:

panel_layout_start('صدور فاکتور ماهانه لابراتوار');
?>
<div class="form-card" style="max-width:600px; margin:0 auto;">
    <?php if ($message): ?>
        <div style="margin-bottom:20px; padding:16px; background:<?= strpos($message, 'با موفقیت') !== false ? '#f0fdf4' : '#fee2e2' ?>; border-radius:8px; border:1px solid <?= strpos($message, 'با موفقیت') !== false ? '#86efac' : '#fca5a5' ?>;">
            <?= htmlspecialchars($message) ?>
            <?php if ($generatedInvoiceId): ?>
                <br><a href="invoice_pdf.php?id=<?= $generatedInvoiceId ?>" target="_blank" class="btn" style="margin-top:10px;">مشاهده PDF</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <div class="form-group">
            <label for="lab_id">لابراتوار</label>
            <select id="lab_id" name="lab_id" required>
                <option value="">انتخاب لابراتوار...</option>
                <?php foreach ($labs as $lab): ?>
                    <option value="<?= $lab['id'] ?>" <?= $lab['id'] == $labId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($lab['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="period_type">نوع دوره</label>
            <select id="period_type" name="period_type">
                <option value="monthly" <?= $periodType === 'monthly' ? 'selected' : '' ?>>ماهانه</option>
                <option value="custom" <?= $periodType === 'custom' ? 'selected' : '' ?>>بازه دلخواه (هفتگی / روزانه)</option>
            </select>
        </div>
        <div id="monthly-fields" style="<?= $periodType === 'custom' ? 'display:none;' : '' ?>">
            <div class="form-group">
                <label for="year">سال شمسی</label>
                <input type="number" id="year" name="year" value="<?= $year ?: $defaultYear ?>" required min="1300" max="1500">
            </div>
            <div class="form-group">
                <label for="month">ماه شمسی</label>
                <select id="month" name="month" required>
                    <option value="">انتخاب ماه...</option>
                    <?php foreach ($jalaliMonths as $mNum => $mName): ?>
                        <option value="<?= $mNum ?>" <?= ($month ?: $prevMonth) == $mNum ? 'selected' : '' ?>>
                            <?= $mName ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div id="custom-fields" style="<?= $periodType === 'custom' ? '' : 'display:none;' ?>">
            <div class="form-group">
                <label for="date_from">از تاریخ</label>
                <input type="text" id="date_from" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" placeholder="۱۴۰۳/۰۱/۰۱" style="cursor:pointer;">
            </div>
            <div class="form-group">
                <label for="date_to">تا تاریخ</label>
                <input type="text" id="date_to" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" placeholder="۱۴۰۳/۰۱/۰۱" style="cursor:pointer;">
            </div>
        </div>
        <div class="form-group">
            <label for="bank_account_id">حساب بانکی</label>
            <select id="bank_account_id" name="bank_account_id">
                <option value="">بدون حساب</option>
                <?php foreach ($bankAccounts as $acc): ?>
                    <option value="<?= $acc['id'] ?>" <?= $bankAccountId == $acc['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($acc['bank_name'] . ' - ' . $acc['account_owner_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" name="generate" class="btn" style="background:#0F172A; color:#fff;">پیش‌نمایش</button>
        <a href="invoices.php" class="btn" style="background:#E5E7EB; color:#0F172A;">بازگشت</a>
    </form>
</div>
<link rel="stylesheet" href="../assets/css/persian-datepicker.min.css">
<script src="../assets/js/persian-date.min.js"></script>
<script src="../assets/js/persian-datepicker.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
    var periodSel = document.getElementById('period_type');
    var monthly = document.getElementById('monthly-fields');
    var custom = document.getElementById('custom-fields');
    function togglePeriod(){
        var v = periodSel ? periodSel.value : 'monthly';
        if (monthly) monthly.style.display = (v === 'monthly') ? '' : 'none';
        if (custom) custom.style.display = (v === 'custom') ? '' : 'none';
    }
    if (periodSel) {
        periodSel.addEventListener('change', togglePeriod);
        togglePeriod();
    }
    function initJalaliPicker(selector){
        if (window.jQuery && typeof jQuery.fn.persianDatepicker === 'function') {
            jQuery(selector).persianDatepicker({
                format: 'YYYY/MM/DD',
                calendarType: 'persian',
                initialValueType: 'jalali',
                persianDigit: true,
                autoClose: true,
                toolbox: {
                    enabled: true,
                    todayButton: { enabled: true, text: { fa: 'امروز', en: 'Today' } },
                    submitButton: { enabled: true, text: { fa: 'تایید', en: 'Submit' } }
                }
            });
        }
    }
    initJalaliPicker('#date_from');
    initJalaliPicker('#date_to');
});
</script>
<?php panel_layout_end(); ?>
