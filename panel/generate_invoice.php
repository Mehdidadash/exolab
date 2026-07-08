<?php
// panel/generate_invoice.php
require_once __DIR__ . '/auth.php';
require_role('admin');

$doctors = getAllDoctors();
$message = '';
$doctorId = isset($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : 0;
$month = isset($_POST['month']) ? (int)$_POST['month'] : 0;
$year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
$generatedInvoiceId = null;

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    if ($doctorId && $month && $year) {
        // Validate year and month
        if ($year < 1300 || $year > 1500 || $month < 1 || $month > 12) {
            $message = 'سال یا ماه نامعتبر است.';
        } else {
            // Convert jalali month/year to gregorian range
            $jalaliDateStr = sprintf('%04d/%02d/01', $year, $month);
            try {
                $startDate = \Morilog\Jalali\Jalalian::fromFormat('Y/m/d', $jalaliDateStr)
                    ->toCarbon()->startOfMonth()->toDateString();
                $endDate = \Morilog\Jalali\Jalalian::fromFormat('Y/m/d', $jalaliDateStr)
                    ->toCarbon()->endOfMonth()->toDateString();
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
                $message = 'هیچ کیس تکمیل‌شده و فاکتورنشده‌ای برای این پزشک در بازه انتخابی وجود ندارد و بدهی هم ندارد.';
            } else {
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
                    </style>
                </head>
                <body>
                <div class="container" style="max-width: 900px; margin: 40px auto;">
                    <h2>پیش‌نمایش فاکتور ماهانه</h2>
                    <p><strong>پزشک:</strong> <?= htmlspecialchars(getDoctor($doctorId)['name'] ?? '') ?></p>
                    <p><strong>ماه:</strong> <?= toPersianDigits($month) . ' / ' . toPersianDigits($year) ?></p>
                    <?php if ($balance > 0): ?>
                        <p><strong>مانده بدهی قبلی:</strong> <?= formatAmountToman($balance) ?></p>
                    <?php endif; ?>
                    <div class="preview-box">
                        <table>
                            <thead>
                                <tr>
                                    <th># کیس</th>
                                    <th>بیمار</th>
                                    <th>خدمت</th>
                                    <th>قیمت</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cases as $case): ?>
                                    <tr>
                                        <td><?= $case['id'] ?></td>
                                        <td><?= htmlspecialchars($case['patient_name']) ?></td>
                                        <td><?= htmlspecialchars($case['service_title'] ?? '') ?></td>
                                        <td><?= formatAmountToman($case['total_price']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($balance > 0): ?>
                                    <tr>
                                        <td colspan="3">مانده بدهی از قبل</td>
                                        <td><?= formatAmountToman($balance) ?></td>
                                    </tr>
                                <?php endif; ?>
                                <tr class="total-row">
                                    <td colspan="3">جمع کل</td>
                                    <td><?= formatAmountToman($total) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <form method="post" action="">
                        <input type="hidden" name="doctor_id" value="<?= $doctorId ?>">
                        <input type="hidden" name="month" value="<?= $month ?>">
                        <input type="hidden" name="year" value="<?= $year ?>">
                        <input type="hidden" name="confirm" value="1">
                        <button type="submit" class="btn" style="background:#06B6D4;">تأیید و ایجاد فاکتور</button>
                        <a href="invoices.php" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</a>
                    </form>
                </div>
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
    $jalaliDateStr = sprintf('%04d/%02d/01', $year, $month);
    try {
        $startDate = \Morilog\Jalali\Jalalian::fromFormat('Y/m/d', $jalaliDateStr)
            ->toCarbon()->startOfMonth()->toDateString();
        $endDate = \Morilog\Jalali\Jalalian::fromFormat('Y/m/d', $jalaliDateStr)
            ->toCarbon()->endOfMonth()->toDateString();
    } catch (\Exception $e) {
        $message = 'تاریخ نامعتبر: ' . $e->getMessage();
        $generatedInvoiceId = null;
        goto show_message;
    }
    $cases = getUninvoicedCasesForDoctor($doctorId, $startDate, $endDate);
    $balance = getOutstandingBalance($doctorId);
    if (!empty($cases) || $balance > 0) {
        $invoiceId = createMonthlyInvoice($doctorId, $cases, $balance, date('Y-m-d'));
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
            <input type="number" id="year" name="year" value="<?= $year ?: date('Y') ?>" required min="1300" max="1500">
        </div>
        <div class="form-group">
            <label for="month">ماه شمسی</label>
            <select id="month" name="month" required>
                <option value="">انتخاب ماه...</option>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>>
                        <?= toPersianDigits($m) ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>
        <button type="submit" name="generate" class="btn" style="background:#0F172A; color:#fff;">پیش‌نمایش</button>
        <a href="invoices.php" class="btn" style="background:#E5E7EB; color:#0F172A;">بازگشت</a>
    </form>
</div>

<?php panel_layout_end(); ?>