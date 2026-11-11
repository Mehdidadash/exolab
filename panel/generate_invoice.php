<?php
// panel/generate_invoice.php — صدور فاکتور پزشک/کلینیک درمانی
//
// دو حالتِ بازه:
//   • «ماه شمسی» (مثل قبل)
//   • «بازهٔ دلخواه» (از تاریخ … تا تاریخ …) — هم‌رفتار با فاکتور کلینیک/لابراتوار/طراح
require_once __DIR__ . '/auth.php';
require_admin();

use Morilog\Jalali\Jalalian;

$doctors = getAllDoctors();
$bankAccounts = getAllBankAccounts();
$message = '';
$generatedInvoiceId = null;

$doctorId = isset($_POST['doctor_id']) ? (int) $_POST['doctor_id'] : 0;
$month = isset($_POST['month']) ? (int) $_POST['month'] : 0;
$year = isset($_POST['year']) ? (int) $_POST['year'] : 0;
$bankAccountId = isset($_POST['bank_account_id']) ? (int) $_POST['bank_account_id'] : 0;
$selectedCaseIds = isset($_POST['case_ids']) && is_array($_POST['case_ids']) ? array_map('intval', $_POST['case_ids']) : [];

$periodType = isset($_POST['period_type']) ? (string) $_POST['period_type'] : 'monthly';
if (!in_array($periodType, ['monthly', 'custom'], true)) $periodType = 'monthly';
$dateFrom = trim((string) ($_POST['date_from'] ?? ''));
$dateTo   = trim((string) ($_POST['date_to'] ?? ''));

// ماه/سالِ پیش‌فرض (ماه قبل)
$nowJalali = Jalalian::now();
$defaultYear = $nowJalali->getYear();
$defaultMonth = $nowJalali->getMonth();
$prevMonth = $defaultMonth > 1 ? $defaultMonth - 1 : 12;
$prevYear  = $defaultMonth > 1 ? $defaultYear : $defaultYear - 1;

$jalaliMonths = [
    1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
    5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
    9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
];

/**
 * بازهٔ تاریخ (میلادی) را بر اساس حالتِ انتخاب‌شده حساب می‌کند.
 * @return array [$startDate, $endDate, $rangeLabel, $error]
 */
function gi_resolve_range(string $periodType, int $month, int $year, string $dateFrom, string $dateTo, array $jalaliMonths): array
{
    if ($periodType === 'custom') {
        $s = parseJalaliToGregorian($dateFrom);
        $e = parseJalaliToGregorian($dateTo);
        if ($s === '' || $e === '') {
            return ['', '', '', 'لطفاً تاریخ شروع و پایان بازه را وارد کنید.'];
        }
        if ($s > $e) {
            return ['', '', '', 'تاریخ شروع نباید بعد از تاریخ پایان باشد.'];
        }
        return [$s, $e, toJalaliDateFormatted($s) . ' تا ' . toJalaliDateFormatted($e), ''];
    }
    if (!$month || !$year) {
        return ['', '', '', 'لطفاً ماه و سال را انتخاب کنید.'];
    }
    if ($year < 1300 || $year > 1500 || $month < 1 || $month > 12) {
        return ['', '', '', 'سال یا ماه نامعتبر است.'];
    }
    try {
        $start = Jalalian::fromFormat('Y/m/d', sprintf('%04d/%02d/01', $year, $month));
        return [
            $start->toCarbon()->toDateString(),
            $start->addMonths(1)->subDay()->toCarbon()->toDateString(),
            ($jalaliMonths[$month] ?? '') . ' ' . toPersianDigits($year),
            '',
        ];
    } catch (\Throwable $e) {
        return ['', '', '', 'تاریخ وارد شده معتبر نیست.'];
    }
}

$selectedCasesOnly = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_selected']));

// ─── پیش‌نمایش ───
if (($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) || $selectedCasesOnly) {

    if (!$doctorId) {
        $message = 'لطفاً پزشک را انتخاب کنید.';
    } else {
        [$startDate, $endDate, $rangeLabel, $rangeError] = gi_resolve_range($periodType, $month, $year, $dateFrom, $dateTo, $jalaliMonths);
        if ($rangeError !== '') {
            $message = $rangeError;
        } else {
            $cases = getUninvoicedCasesForDoctor($doctorId, $startDate, $endDate);
            $balance = getOutstandingBalance($doctorId);

            if (empty($cases) && $balance == 0) {
                $message = 'هیچ کیس فاکتورنشده‌ای برای این پزشک در بازه انتخابی وجود ندارد و بدهی هم ندارد.';
            } else {
                if ($selectedCasesOnly && !empty($selectedCaseIds)) {
                    $cases = array_values(array_filter($cases, function ($c) use ($selectedCaseIds) {
                        return in_array((int) $c['id'], $selectedCaseIds);
                    }));
                }
                $total = $balance + array_sum(array_column($cases, 'total_price'));
                ?>
                <!DOCTYPE html>
                <html dir="rtl" lang="fa">
                <head>
                    <meta charset="UTF-8">
                    <title>پیش‌نمایش فاکتور</title>
                    <link rel="stylesheet" href="../assets/css/style.css">
                    <style>
                        .preview-box { background: #f9fafb; padding: 20px; border-radius: 12px; margin: 20px 0; }
                        .preview-box table { width: 100%; border-collapse: collapse; }
                        .preview-box th, .preview-box td { padding: 8px 12px; border: 1px solid #e5e7eb; text-align: right; }
                        .preview-box .total-row { font-weight: bold; background: #e5e7eb; }
                        .case-checkbox { width: 20px; height: 20px; cursor: pointer; }
                        .selected-count { margin-right: 8px; font-weight: bold; color: #06B6D4; }
                    </style>
                </head>
                <body>
                <div class="container" style="max-width: 900px; margin: 40px auto;">
                    <h2>پیش‌نمایش فاکتور پزشک</h2>
                    <p><strong>پزشک:</strong> <?= htmlspecialchars(getDoctor($doctorId)['name'] ?? '') ?></p>
                    <p><strong><?= $periodType === 'custom' ? 'بازه' : 'ماه' ?>:</strong> <?= htmlspecialchars($rangeLabel) ?></p>
                    <?php if ($balance > 0): ?>
                        <p><strong>مانده بدهی قبلی:</strong> <?= formatAmountToman($balance) ?></p>
                    <?php endif; ?>
                    <div class="preview-box">
                        <form id="preview-form" method="post" action="">
                            <input type="hidden" name="doctor_id" value="<?= $doctorId ?>">
                            <input type="hidden" name="period_type" value="<?= htmlspecialchars($periodType) ?>">
                            <input type="hidden" name="month" value="<?= $month ?>">
                            <input type="hidden" name="year" value="<?= $year ?>">
                            <input type="hidden" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                            <input type="hidden" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                            <input type="hidden" name="bank_account_id" value="<?= $bankAccountId ?>">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width:40px;">انتخاب</th>
                                        <th># کیس</th>
                                        <th>بیمار</th>
                                        <th>خدمت</th>
                                        <th>قیمت</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cases as $case): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="case-checkbox" name="case_ids[]" value="<?= $case['id'] ?>" checked>
                                            </td>
                                            <td><?= $case['id'] ?></td>
                                            <td><?= htmlspecialchars($case['patient_name']) ?></td>
                                            <td><?= htmlspecialchars($case['service_title'] ?? '') ?></td>
                                            <td><?= formatAmountToman($case['total_price']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if ($balance > 0): ?>
                                        <tr>
                                            <td></td>
                                            <td colspan="3">مانده بدهی از قبل</td>
                                            <td><?= formatAmountToman($balance) ?></td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr class="total-row">
                                        <td></td>
                                        <td colspan="3">جمع کل</td>
                                        <td id="preview-total"><?= formatAmountToman($total) ?></td>
                                    </tr>
                                </tbody>
                            </table>
                            <div style="margin-top:10px;">
                                <span>تعداد انتخاب شده: <span id="selected-count" class="selected-count"><?= toPersianDigits(count($cases)) ?></span></span>
                            </div>
                            <div style="margin-top: 16px; display:flex; gap:10px; flex-wrap:wrap;">
                                <button type="submit" name="confirm" value="1" class="btn" style="background:#06B6D4;">تأیید و ایجاد فاکتور</button>
                                <button type="submit" name="preview_selected" value="1" class="btn" style="background:#0F172A; color:#fff;">به‌روزرسانی انتخاب</button>
                                <a href="generate_invoice.php" class="btn" style="background:#E5E7EB; color:#0F172A;">تغییر دوره/پزشک</a>
                            </div>
                        </form>
                    </div>
                </div>
                <script>
                (function () {
                    var checkboxes = document.querySelectorAll('.case-checkbox');
                    var countSpan = document.getElementById('selected-count');
                    checkboxes.forEach(function (cb) {
                        cb.addEventListener('change', function () {
                            if (countSpan) countSpan.textContent = document.querySelectorAll('.case-checkbox:checked').length;
                        });
                    });
                })();
                </script>
                </body>
                </html>
                <?php
                exit;
            }
        }
    }
}

// ─── ایجاد نهایی ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $_POST['confirm'] == 1) {
    $doctorId = (int) $_POST['doctor_id'];
    $bankAccountId = !empty($_POST['bank_account_id']) ? (int) $_POST['bank_account_id'] : null;
    $selectedCaseIds = isset($_POST['case_ids']) && is_array($_POST['case_ids']) ? array_map('intval', $_POST['case_ids']) : [];

    [$startDate, $endDate, $rangeLabel, $rangeError] = gi_resolve_range($periodType, $month, $year, $dateFrom, $dateTo, $jalaliMonths);

    if ($rangeError !== '') {
        $message = $rangeError;
    } else {
        $cases = getUninvoicedCasesForDoctor($doctorId, $startDate, $endDate);
        $balance = getOutstandingBalance($doctorId);

        // فقط کیس‌های تیک‌خورده
        if (!empty($selectedCaseIds)) {
            $cases = array_values(array_filter($cases, function ($c) use ($selectedCaseIds) {
                return in_array((int) $c['id'], $selectedCaseIds);
            }));
        }

        if (!empty($cases) || $balance > 0) {
            $invoiceId = createMonthlyInvoice($doctorId, $cases, $balance, date('Y-m-d'), $bankAccountId);
            $generatedInvoiceId = $invoiceId;
            $message = 'فاکتور با موفقیت ایجاد شد. شماره فاکتور: ' . getInvoice($invoiceId)['invoice_number'];
        } else {
            $message = 'هیچ موردی برای فاکتور وجود ندارد.';
        }
    }
}

panel_layout_start('صدور فاکتور پزشک');
?>

<div class="form-card" style="max-width: 640px; margin: 0 auto;">
    <?php if ($message): ?>
        <div style="margin-bottom: 20px; padding: 16px; background: <?= strpos($message, 'با موفقیت') !== false ? '#f0fdf4' : '#fee2e2' ?>; border-radius: 8px; border: 1px solid <?= strpos($message, 'با موفقیت') !== false ? '#86efac' : '#fca5a5' ?>;">
            <?= htmlspecialchars($message) ?>
            <?php if ($generatedInvoiceId): ?>
                <br><a href="invoice_pdf.php?id=<?= $generatedInvoiceId ?>" target="_blank" class="btn" style="margin-top:10px;">مشاهده PDF</a>
                <a href="invoice_form.php?id=<?= $generatedInvoiceId ?>" class="btn" style="background:#E5E7EB; color:#0F172A; margin-top:10px;">ویرایش فاکتور</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <div class="form-group">
            <label for="doctor_id">پزشک</label>
            <select id="doctor_id" name="doctor_id" required>
                <option value="">انتخاب پزشک...</option>
                <?php foreach ($doctors as $doc): ?>
                    <option value="<?= $doc['id'] ?>" <?= $doc['id'] == $doctorId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($doc['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="period_type">نوع دوره</label>
            <select id="period_type" name="period_type">
                <option value="monthly" <?= $periodType === 'monthly' ? 'selected' : '' ?>>ماه شمسی</option>
                <option value="custom" <?= $periodType === 'custom' ? 'selected' : '' ?>>بازهٔ دلخواه (از تاریخ … تا تاریخ …)</option>
            </select>
        </div>

        <div id="monthly-fields" style="<?= $periodType === 'custom' ? 'display:none;' : '' ?>">
            <div class="form-group">
                <label for="year">سال شمسی</label>
                <input type="number" id="year" name="year" value="<?= $year ?: $prevYear ?>" min="1300" max="1500">
            </div>
            <div class="form-group">
                <label for="month">ماه شمسی</label>
                <select id="month" name="month">
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
                <input type="text" id="date_from" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" placeholder="۱۴۰۵/۰۷/۰۱" autocomplete="off" style="cursor:pointer;">
            </div>
            <div class="form-group">
                <label for="date_to">تا تاریخ</label>
                <input type="text" id="date_to" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" placeholder="۱۴۰۵/۰۷/۳۰" autocomplete="off" style="cursor:pointer;">
            </div>
        </div>

        <div class="form-group">
            <label for="bank_account_id">حساب بانکی (برای درج در فاکتور)</label>
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
        <a href="issue_invoice.php" class="btn" style="background:#E5E7EB; color:#0F172A;">بازگشت</a>
    </form>
</div>

<link rel="stylesheet" href="../assets/css/persian-datepicker.min.css">
<script src="../assets/js/persian-date.min.js"></script>
<script src="../assets/js/persian-datepicker.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var periodSel = document.getElementById('period_type');
    var monthly = document.getElementById('monthly-fields');
    var custom = document.getElementById('custom-fields');
    function togglePeriod() {
        var v = periodSel ? periodSel.value : 'monthly';
        if (monthly) monthly.style.display = (v === 'monthly') ? '' : 'none';
        if (custom) custom.style.display = (v === 'custom') ? '' : 'none';
    }
    if (periodSel) { periodSel.addEventListener('change', togglePeriod); togglePeriod(); }

    function initJalaliPicker(selector) {
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
