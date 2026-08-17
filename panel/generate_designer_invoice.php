<?php
// panel/generate_designer_invoice.php
// Design fee (freelance designer) invoice: monthly or custom range,
// per-unit fee = designer's design-fee override for that service.
require_once __DIR__ . '/auth.php';
require_role('admin');

use Morilog\Jalali\Jalalian;

$designers = db()->query("SELECT id, full_name FROM users WHERE is_designer=1 AND active=1 ORDER BY full_name")->fetchAll();
$message = '';
$generatedInvoiceId = null;

$designerId = isset($_POST['designer_id']) ? (int)$_POST['designer_id'] : 0;
$month = isset($_POST['month']) ? (int)$_POST['month'] : 0;
$year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
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
    } elseif ($designerId && $month && $year) {
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
        $message = 'لطفاً طراح و بازه زمانی را انتخاب کنید.';
    }

    if (empty($message) && $startDate !== '' && $endDate !== '') {
        $cases = getUninvoicedCasesForDesigner($designerId, $startDate, $endDate);

        if ($selectedCasesOnly && !empty($selectedCaseIds)) {
            $cases = array_values(array_filter($cases, function($c) use ($selectedCaseIds) {
                return in_array((int)$c['id'], $selectedCaseIds);
            }));
        }

        if (empty($cases)) {
            $message = 'هیچ کیس فاکتورنشده‌ای برای این طراح در بازه انتخابی وجود ندارد.';
        } else {
            $total = 0;
            foreach ($cases as $c) {
                $unitFee = $c['unit_design_fee'] ?? 0;
                $total += round((float)$unitFee * (int)($c['quantity'] ?? 1));
            }
            $designer = db()->prepare('SELECT full_name FROM users WHERE id = ?');
            $designer->execute([$designerId]);
            $designerName = $designer->fetchColumn() ?: 'طراح';
            ?>
            <!DOCTYPE html>
            <html dir="rtl" lang="fa">
            <head>
                <meta charset="UTF-8">
                <title>پیش‌نمایش فاکتور طراحی</title>
                <link rel="stylesheet" href="../assets/css/style.css">
                <style>
                    .preview-box { background:#f9fafb; padding:20px; border-radius:12px; margin:20px 0; }
                    .preview-box table { width:100%; border-collapse:collapse; }
                    .preview-box th,.preview-box td { padding:8px 12px; border:1px solid #e5e7eb; text-align:right; }
                    .total-row { font-weight:bold; background:#e5e7eb; }
                    .case-checkbox { width:20px; height:20px; cursor:pointer; }
                </style>
            </head>
            <body>
            <div class="container" style="max-width:900px; margin:40px auto;">
                <h2>پیش‌نمایش فاکتور طراحی</h2>
                <p><strong>طراح:</strong> <?= htmlspecialchars($designerName) ?></p>
                <p><strong>بازه:</strong> <?= htmlspecialchars($periodLabel) ?></p>
                <div class="preview-box">
                    <form id="preview-form" method="post" action="">
                        <input type="hidden" name="designer_id" value="<?= $designerId ?>">
                        <input type="hidden" name="period_type" value="<?= htmlspecialchars($periodType) ?>">
                        <input type="hidden" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                        <input type="hidden" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                        <input type="hidden" name="month" value="<?= $month ?>">
                        <input type="hidden" name="year" value="<?= $year ?>">
                        <table>
                            <thead>
                            <tr>
                                <th style="width:40px;">انتخاب</th>
                                <th># کیس</th>
                                <th>پزشک</th>
                                <th>بیمار</th>
                                <th>خدمت</th>
                                <th>تعداد</th>
                                <th>فی طراحی</th>
                                <th>جمع</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($cases as $c):
                                $unitFee = (float)($c['unit_design_fee'] ?? 0);
                                $qty = (int)($c['quantity'] ?? 1);
                                $lineTotal = round($unitFee * $qty);
                            ?>
                                <tr>
                                    <td><input type="checkbox" class="case-checkbox" name="case_ids[]" value="<?= $c['id'] ?>" checked></td>
                                    <td><?= $c['id'] ?></td>
                                    <td><?= htmlspecialchars($c['doctor_name'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($c['patient_name']) ?></td>
                                    <td><?= htmlspecialchars($c['service_title'] ?? '—') ?></td>
                                    <td><?= toPersianDigits($qty) ?></td>
                                    <td><?= formatAmountToman($unitFee) ?></td>
                                    <td><?= formatAmountToman($lineTotal) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td></td>
                                <td colspan="6">جمع کل</td>
                                <td><?= formatAmountToman($total) ?></td>
                            </tr>
                            </tbody>
                        </table>
                        <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
                            <button type="submit" name="confirm" value="1" class="btn" style="background:#06B6D4;">تأیید و ایجاد فاکتور</button>
                            <button type="submit" name="preview_selected" value="1" class="btn" style="background:#0F172A; color:#fff;">به‌روزرسانی انتخاب</button>
                            <a href="generate_designer_invoice.php" class="btn" style="background:#E5E7EB; color:#0F172A;">تغییر بازه/طراح</a>
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
    $designerId = (int)$_POST['designer_id'];
    $month = (int)$_POST['month'];
    $year = (int)$_POST['year'];
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
    $cases = getUninvoicedCasesForDesigner($designerId, $startDate, $endDate);
    if (!empty($selectedCaseIds)) {
        $cases = array_values(array_filter($cases, function($c) use ($selectedCaseIds) {
            return in_array((int)$c['id'], $selectedCaseIds);
        }));
    }
    if (!empty($cases)) {
        $invoiceId = createDesignerInvoice($designerId, $cases, date('Y-m-d'), $periodLabel);
        $generatedInvoiceId = $invoiceId;
        $message = 'فاکتور طراحی با موفقیت ایجاد شد.';
    } else {
        $message = 'هیچ موردی برای فاکتور وجود ندارد.';
    }
}
show_message:

panel_layout_start('صدور فاکتور طراحی');
?>
<div class="form-card" style="max-width:600px; margin:0 auto;">
    <?php if ($message): ?>
        <div style="margin-bottom:20px; padding:16px; background:<?= strpos($message, 'با موفقیت') !== false ? '#f0fdf4' : '#fee2e2' ?>; border-radius:8px; border:1px solid <?= strpos($message, 'با موفقیت') !== false ? '#86efac' : '#fca5a5' ?>;">
            <?= htmlspecialchars($message) ?>
            <?php if ($generatedInvoiceId): ?>
                <br><a href="designer_invoice_pdf.php?id=<?= $generatedInvoiceId ?>" target="_blank" class="btn" style="margin-top:10px;">مشاهده PDF</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <div class="form-group">
            <label for="designer_id">طراح</label>
            <select id="designer_id" name="designer_id" required>
                <option value="">انتخاب طراح...</option>
                <?php foreach ($designers as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $d['id'] == $designerId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($d['full_name']) ?>
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
        <button type="submit" name="generate" class="btn" style="background:#0F172A; color:#fff;">پیش‌نمایش</button>
        <a href="designer_invoices.php" class="btn" style="background:#E5E7EB; color:#0F172A;">بازگشت</a>
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
    if (periodSel) { periodSel.addEventListener('change', togglePeriod); togglePeriod(); }
    function initJalaliPicker(selector){
        if (window.jQuery && typeof jQuery.fn.persianDatepicker === 'function') {
            jQuery(selector).persianDatepicker({
                format: 'YYYY/MM/DD',
                calendarType: 'persian',
                initialValueType: 'jalali',
                persianDigit: true,
                autoClose: true,
                toolbox: { enabled: true, todayButton: { enabled: true, text: { fa: 'امروز', en: 'Today' } }, submitButton: { enabled: true, text: { fa: 'تایید', en: 'Submit' } } }
            });
        }
    }
    initJalaliPicker('#date_from');
    initJalaliPicker('#date_to');
});
</script>
<?php panel_layout_end(); ?>
