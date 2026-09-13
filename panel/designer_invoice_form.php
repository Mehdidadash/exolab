<?php
// panel/designer_invoice_form.php — ویرایش فاکتور طراحی (سربرگ + آیتم‌ها)
require_once __DIR__ . '/auth.php';
require_admin();

$invoiceId = (int) ($_GET['id'] ?? 0);
$invoice = getDesignerInvoice($invoiceId);
if (!$invoice) {
    header('Location: designer_invoices.php?error=notfound');
    exit;
}
$items = getDesignerInvoiceItems($invoiceId);

$invoiceDate = toJalaliDateFormatted($invoice['invoice_date'] ?? date('Y-m-d'));
$periodLabel = (string) ($invoice['period_label'] ?? '');
$notes = (string) ($invoice['notes'] ?? '');

// کیس‌های بدون فاکتورِ این طراح که می‌توان به این فاکتور اضافه کرد.
$designerId = (int) ($invoice['designer_id'] ?? 0);
$payerBranch = currentBranchId();
if ($payerBranch === null) $payerBranch = 1; // مدیر کل = شعبهٔ مرکزی
$addableCases = getUninvoicedCasesForDesignerAll($designerId, $payerBranch);

panel_layout_start('ویرایش فاکتور طراحی');
?>
<link rel="stylesheet" href="../assets/css/persian-datepicker.min.css">
<script src="../assets/js/persian-date.min.js"></script>
<script src="../assets/js/persian-datepicker.min.js"></script>
<div style="margin-bottom:18px; display:flex; gap:10px; flex-wrap:wrap; justify-content:space-between; align-items:center;">
    <div>
        <a class="btn" href="designer_invoices.php" style="background:#E5E7EB; color:#0F172A;">بازگشت به فاکتورهای طراحی</a>
        <a class="btn" href="designer_invoice_pdf.php?id=<?= (int) $invoiceId ?>" target="_blank" style="background:#0F172A; color:#fff;">PDF</a>
    </div>
</div>

<?php if (isset($_GET['ok'])): ?>
    <div style="margin-bottom:16px; padding:14px 18px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; color:#166534;">تغییرات با موفقیت ذخیره شد.</div>
<?php endif; ?>

<form method="post" action="save_designer_invoice.php">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $invoiceId ?>">

    <div class="form-card">
        <h3 style="margin-bottom:6px;">ویرایش فاکتور طراحی — <?= htmlspecialchars($invoice['invoice_number']) ?></h3>
        <p style="color:#525252; margin-bottom:16px;">طراح: <strong><?= htmlspecialchars($invoice['designer_name'] ?? '—') ?></strong></p>

        <div class="form-group">
            <label>تاریخ فاکتور</label>
            <input type="text" id="invoice_date" name="invoice_date" value="<?= htmlspecialchars($invoiceDate) ?>" autocomplete="off" required>
        </div>
        <div class="form-group">
            <label>بازه (برچسب)</label>
            <input type="text" name="period_label" value="<?= htmlspecialchars($periodLabel) ?>" placeholder="مثلاً مرداد ۱۴۰۵">
        </div>
        <div class="form-group">
            <label>یادداشت‌ها</label>
            <textarea name="notes" rows="3"><?= htmlspecialchars($notes) ?></textarea>
        </div>

        <h4 style="margin:22px 0 10px;">آیتم‌های فاکتور</h4>
        <p style="color:#6b7280; font-size:0.85rem;">تعداد و فی طراحی را ویرایش کنید؛ با تیک «حذف» ردیف از فاکتور حذف و کیس آن برای صدور مجدد آزاد می‌شود. جمع کل پس از ذخیره دوباره محاسبه می‌شود.</p>

        <?php if (empty($items)): ?>
            <p class="empty">این فاکتور آیتمی ندارد.</p>
        <?php else: ?>
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem; margin-top:8px;">
            <thead>
            <tr>
                <th style="border:1px solid #e5e7eb; background:#f3f4f6; padding:8px; text-align:right;"># کیس</th>
                <th style="border:1px solid #e5e7eb; background:#f3f4f6; padding:8px; text-align:right;">بیمار</th>
                <th style="border:1px solid #e5e7eb; background:#f3f4f6; padding:8px; text-align:right;">پزشک</th>
                <th style="border:1px solid #e5e7eb; background:#f3f4f6; padding:8px; text-align:right;">خدمت</th>
                <th style="border:1px solid #e5e7eb; background:#f3f4f6; padding:8px; text-align:right;">تعداد</th>
                <th style="border:1px solid #e5e7eb; background:#f3f4f6; padding:8px; text-align:right;">فی طراحی (تومان)</th>
                <th style="border:1px solid #e5e7eb; background:#f3f4f6; padding:8px; text-align:center;">حذف</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $it):
                $iid = (int) $it['id']; ?>
            <tr data-row="<?= $iid ?>">
                <td style="border:1px solid #e5e7eb; padding:6px 8px;"><?= (int) ($it['case_id'] ?? 0) ?: '—' ?></td>
                <td style="border:1px solid #e5e7eb; padding:6px 8px;"><?= htmlspecialchars($it['patient_name'] ?? '—') ?></td>
                <td style="border:1px solid #e5e7eb; padding:6px 8px;"><?= htmlspecialchars($it['doctor_name'] ?? '—') ?></td>
                <td style="border:1px solid #e5e7eb; padding:6px 8px;"><?= htmlspecialchars($it['service_title'] ?? '—') ?></td>
                <td style="border:1px solid #e5e7eb; padding:6px 8px;">
                    <input type="number" class="row-qty" name="items[<?= $iid ?>][qty]" value="<?= (int) ($it['quantity'] ?? 1) ?>" min="1" step="1" style="width:70px;">
                </td>
                <td style="border:1px solid #e5e7eb; padding:6px 8px;">
                    <input type="number" class="row-unit" name="items[<?= $iid ?>][unit]" value="<?= round((float) ($it['unit_design_fee'] ?? 0)) ?>" min="0" step="1" style="width:130px;">
                </td>
                <td style="border:1px solid #e5e7eb; padding:6px 8px; text-align:center;">
                    <input type="checkbox" class="row-remove" name="items[<?= $iid ?>][remove]" value="1" title="حذف این ردیف">
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <h4 style="margin:22px 0 10px;">افزودن کیس به این فاکتور</h4>
        <?php if (empty($addableCases)): ?>
            <p class="empty">کیسِ بدون فاکتوری برای این طراح وجود ندارد.</p>
        <?php else: ?>
            <p style="color:#6b7280; font-size:0.85rem; margin:0 0 6px;">کیس‌های دارای طراحی و بدون فاکتور را انتخاب کن (با نگه‌داشتن Ctrl چندتا). با ذخیره، به همین فاکتور اضافه می‌شوند.</p>
            <select name="add_case_ids[]" multiple size="8" style="width:100%; min-height:170px; padding:6px; border:1px solid #d1d5db; border-radius:6px;">
                <?php foreach ($addableCases as $c): ?>
                    <option value="<?= (int) $c['id'] ?>">
                        #<?= (int) $c['id'] ?> — <?= htmlspecialchars($c['patient_name'] ?? '—') ?> — <?= htmlspecialchars($c['service_title'] ?? '—') ?> — پزشک: <?= htmlspecialchars($c['doctor_name'] ?? '—') ?> — تعداد <?= toPersianDigits((int) ($c['quantity'] ?? 1)) ?> — فی طراحی <?= formatAmountToman((float) ($c['unit_design_fee'] ?? 0)) ?> تومان
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <div style="margin-top:18px; display:flex; gap:12px; align-items:center;">
            <button type="submit" class="btn" style="background:#06B6D4; color:#fff;">ذخیره تغییرات</button>
            <a href="designer_invoices.php" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</a>
        </div>
    </div>
</form>

<script>
(function () {
    if (window.persianDatepicker) {
        window.persianDatepicker('#invoice_date', { format: 'YYYY/MM/DD', autoClose: true });
    }
    document.querySelectorAll('tr[data-row]').forEach(function (tr) {
        var rm = tr.querySelector('.row-remove');
        if (rm) {
            rm.addEventListener('change', function () {
                tr.style.opacity = rm.checked ? '0.4' : '1';
            });
        }
    });
})();
</script>
<?php panel_layout_end(); ?>
