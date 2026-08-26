<?php
// panel/generate_invoice.php
require_once __DIR__ . '/auth.php';
require_admin();

use Morilog\Jalali\Jalalian;

$doctors = getAllDoctors();
$bankAccounts = getAllBankAccounts();
$message = '';
$doctorId = isset($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : 0;
$month = isset($_POST['month']) ? (int)$_POST['month'] : 0;
$year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
$bankAccountId = isset($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : 0;
$selectedCaseIds = isset($_POST['case_ids']) && is_array($_POST['case_ids']) ? array_map('intval', $_POST['case_ids']) : [];
$generatedInvoiceId = null;

// Default Jalali year (1405) and previous month
$nowJalali = Jalalian::now();
$defaultYear = $nowJalali->getYear();    // 1405
$defaultMonth = $nowJalali->getMonth(); // current month (e.g. 4 = Tir)
// Previous month (if current is 1 = Farvardin, then 12 = Esfand of prev year)
$prevMonth = $defaultMonth > 1 ? $defaultMonth - 1 : 12;
$prevYear = $defaultMonth > 1 ? $defaultYear : $defaultYear - 1;

$jalaliMonths = [
    1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
    5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
    9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
];

// Check if we're previewing with selected cases only
$selectedCasesOnly = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_selected']));

// Process form submission
if (($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) || $selectedCasesOnly) {
    if ($doctorId && $month && $year) {
        // Validate year and month
        if ($year < 1300 || $year > 1500 || $month < 1 || $month > 12) {
            $message = 'سال یا ماه نامعتبر است.';
        } else {
            // Convert jalali month/year to gregorian range
            $jalaliDateStr = sprintf('%04d/%02d/01', $year, $month);
            try {
                $jalaliStart = \Morilog\Jalali\Jalalian::fromFormat('Y/m/d', $jalaliDateStr);
                $startDate = $jalaliStart->toCarbon()->toDateString();
                // End of Jalali month = first of next month minus 1 day
                $endDate = $jalaliStart->addMonths(1)->subDay()->toCarbon()->toDateString();
            } catch (\Exception $e) {
                $message = 'تاریخ وارد شده معتبر نیست: ' . $e->getMessage();
                // fallback to show the form again
                panel_layout_start('صدور فاکتور ماهانه');
                ?>
                <div class="form-card" style="max-width: 600px; margin: 0 auto;">
                    <div class="error-box"><?= htmlspecialchars($message) ?></div>
                    <!-- form again (same as below) -->
                    <form method="post" action="">
                        <!-- ... same form fields ... -->
                    </form>
                </div>
                <?php panel_layout_end(); exit;
            }

            $cases = getUninvoicedCasesForDoctor($doctorId, $startDate, $endDate);
            $balance = getOutstandingBalance($doctorId);
            $total = $balance + array_sum(array_column($cases, 'total_price'));

            if (empty($cases) && $balance == 0) {
                $message = 'هیچ کیس فاکتورنشده‌ای برای این پزشک در بازه انتخابی وجود ندارد و بدهی هم ندارد.';
            } else {
                // If preview_selected, filter cases to only selected ones
                if ($selectedCasesOnly && !empty($selectedCaseIds)) {
                    $cases = array_filter($cases, function($c) use ($selectedCaseIds) {
                        return in_array((int)$c['id'], $selectedCaseIds);
                    });
                }
                $total = $balance + array_sum(array_column($cases, 'total_price'));
                // Show preview and confirm button
                ?>
                <!DOCTYPE html>
                <html dir="rtl" lang="fa">
                <head>
                    <meta charset="UTF-8">
                    <title>پیش‌نمایش فاکتور ماهانه</title>
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
                    <h2>پیش‌نمایش فاکتور ماهانه</h2>
                    <p><strong>پزشک:</strong> <?= htmlspecialchars(getDoctor($doctorId)['name'] ?? '') ?></p>
                    <p><strong>ماه:</strong> <?= htmlspecialchars($jalaliMonths[$month] ?? $month) . ' ' . toPersianDigits($year) ?></p>
                    <?php if ($balance > 0): ?>
                        <p><strong>مانده بدهی قبلی:</strong> <?= formatAmountToman($balance) ?></p>
                    <?php endif; ?>
                    <div class="preview-box">
                        <form id="preview-form" method="post" action="">
                            <input type="hidden" name="doctor_id" value="<?= $doctorId ?>">
                            <input type="hidden" name="month" value="<?= $month ?>">
                            <input type="hidden" name="year" value="<?= $year ?>">
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
                                <a href="generate_invoice.php" class="btn" style="background:#E5E7EB; color:#0F172A;">تغییر ماه/پزشک</a>
                            </div>
                        </form>
                    </div>
                </div>
                <script>
                (function() {
                    var checkboxes = document.querySelectorAll('.case-checkbox');
                    var countSpan = document.getElementById('selected-count');
                    var totalSpan = document.getElementById('preview-total');
                    
                    function updateSummary() {
                        var checked = document.querySelectorAll('.case-checkbox:checked').length;
                        countSpan.textContent = checked;
                        
                        // Calculate total from checked rows
                        var total = 0;
                        document.querySelectorAll('.case-checkbox:checked').forEach(function(cb) {
                            var row = cb.closest('tr');
                            var priceCell = row.querySelector('td:last-child');
                            if (priceCell) {
                                var numStr = priceCell.textContent.replace(/[^0-9]/g, '');
                                total += parseInt(numStr) || 0;
                            }
                        });
                        // Add balance if present
                        var balanceRow = document.querySelector('.total-row');
                        if (balanceRow) {
                            var prevRow = balanceRow.previousElementSibling;
                            if (prevRow && prevRow.querySelector('td:first-child') && 
                                prevRow.querySelector('td:first-child').textContent.trim() === '') {
                                // has balance row
                            }
                        }
                    }
                    
                    checkboxes.forEach(function(cb) {
                        cb.addEventListener('change', updateSummary);
                    });
                })();
                </script>
                </body>
                </html>
                <?php
                exit;
            }
        }
    } else {
        $message = 'لطفاً پزشک و ماه/سال را انتخاب کنید.';
    }
}

// Handle confirmation (actual creation)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $_POST['confirm'] == 1) {
    $doctorId = (int)$_POST['doctor_id'];
    $month = (int)$_POST['month'];
    $year = (int)$_POST['year'];
    $bankAccountId = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
    $selectedCaseIds = isset($_POST['case_ids']) && is_array($_POST['case_ids']) ? array_map('intval', $_POST['case_ids']) : [];
    $jalaliDateStr = sprintf('%04d/%02d/01', $year, $month);
    try {
        $jalaliStart = \Morilog\Jalali\Jalalian::fromFormat('Y/m/d', $jalaliDateStr);
        $startDate = $jalaliStart->toCarbon()->toDateString();
        $endDate = $jalaliStart->addMonths(1)->subDay()->toCarbon()->toDateString();
    } catch (\Exception $e) {
        $message = 'تاریخ نامعتبر: ' . $e->getMessage();
        $generatedInvoiceId = null;
        goto show_message;
    }
    $cases = getUninvoicedCasesForDoctor($doctorId, $startDate, $endDate);
    $balance = getOutstandingBalance($doctorId);
    
    // Filter to only selected cases if provided
    if (!empty($selectedCaseIds)) {
        $cases = array_filter($cases, function($c) use ($selectedCaseIds) {
            return in_array((int)$c['id'], $selectedCaseIds);
        });
    }
    
    if (!empty($cases) || $balance > 0) {
        $invoiceId = createMonthlyInvoice($doctorId, $cases, $balance, date('Y-m-d'), $bankAccountId);
        $generatedInvoiceId = $invoiceId;
        $message = 'فاکتور با موفقیت ایجاد شد. شماره فاکتور: ' . getInvoice($invoiceId)['invoice_number'];
    } else {
        $message = 'هیچ موردی برای فاکتور وجود ندارد.';
    }
}
show_message:

panel_layout_start('صدور فاکتور ماهانه');
?>

<div class="form-card" style="max-width: 600px; margin: 0 auto;">
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
        <a href="invoices.php" class="btn" style="background:#E5E7EB; color:#0F172A;">بازگشت</a>
    </form>
</div>

<?php panel_layout_end(); ?>