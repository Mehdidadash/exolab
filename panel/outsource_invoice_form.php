<?php
// panel/outsource_invoice_form.php — ویرایش فاکتور برون‌سپاری (سربرگ + آیتم‌ها)
// آینهٔ designer_invoice_form.php برای فاکتورهای برون‌سپاری (مخارج پرداختی به لابراتوارها).
require_once __DIR__ . '/auth.php';
require_admin();

$invoiceId = (int) ($_GET['id'] ?? 0);
$invoice = getOutsourceInvoice($invoiceId);
if (!$invoice) {
    header('Location: outsource_invoices.php?error=notfound');
    exit;
}
$items = getOutsourceInvoiceItems($invoiceId);

$invoiceDate = toJalaliDateFormatted($invoice['invoice_date'] ?? date('Y-m-d'));
$periodLabel = (string) ($invoice['period_label'] ?? '');
$notes = (string) ($invoice['notes'] ?? '');

// کیس‌های بدون فاکتورِ این لابراتوار که می‌توان به این فاکتور اضافه کرد.
$labId = (int) ($invoice['lab_id'] ?? 0);
$payerBranch = currentBranchId();
if ($payerBranch === null) $payerBranch = 1; // مدیر کل = شعبهٔ مرکزی
$addableCases = getUninvoicedCasesForLabAll($labId, $payerBranch);

$paid = getExpenseInvoicePaid('outsource', $invoiceId);

panel_layout_start('ویرایش فاکتور برون‌سپاری');
?>
<link rel="stylesheet" href="../assets/css/persian-datepicker.min.css">
<script src="../assets/js/persian-date.min.js"></script>
<script src="../assets/js/persian-datepicker.min.js"></script>
<div style="margin-bottom:18px; display:flex; gap:10px; flex-wrap:wrap; justify-content:space-between; align-items:center;">
    <div>
        <a class="btn" href="outsource_invoices.php" style="background:#E5E7EB; color:#0F172A;">بازگشت به فاکتورهای برون‌سپاری</a>
        <a class="btn" href="expenses.php" style="background:#E5E7EB; color:#0F172A;">فاکتورهای مخارج</a>
        <a class="btn" href="outsource_invoice_pdf.php?id=<?= (int) $invoiceId ?>" target="_blank" style="background:#0F172A; color:#fff;">PDF</a>
    </div>
</div>

<?php if (isset($_GET['ok'])): ?>
    <div style="margin-bottom:16px; padding:14px 18px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; color:#166534;">تغییرات با موفقیت ذخیره شد.</div>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
    <div style="margin-bottom:16px; padding:14px 18px; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; color:#991b1b;">
        ذخیره نشد<?= $_GET['error'] === 'date' ? ' — تاریخ فاکتور نامعتبر است.' : '.' ?>
    </div>
<?php endif; ?>

<form method="post" action="save_outsource_invoice.php">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $invoiceId ?>">

    <div class="form-card">
        <h3 style="margin-bottom:6px;">ویرایش فاکتور برون‌سپاری — <?= htmlspecialchars($invoice['invoice_number']) ?></h3>
        <p style="color:#525252; margin-bottom:16px;">لابراتوار: <strong><?= htmlspecialchars($invoice['lab_name'] ?? '—') ?></strong></p>

        <?php if ($paid > 0): ?>
            <div style="margin-bottom:16px; padding:10px 14px; background:#fffbeb; border:1px solid #fde68a; border-radius:8px; color:#92400e; font-size:0.9rem;">
                تا کنون <strong><?= formatAmountToman($paid) ?> تومان</strong> از این فاکتور پرداخت شده است؛ با کاهش جمع کل، وضعیت پرداخت خودکار بازمحاسبه می‌شود.
            </div>
        <?php endif; ?>

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
        <p style="color:#6b7280; font-size:0.85rem;">تعداد و فی برون‌سپاری را ویرایش کنید؛ با تیک «حذف» ردیف از فاکتور حذف و کیس آن برای صدور مجدد آزاد می‌شود. جمع کل پس از ذخیره دوباره محاسبه می‌شود.</p>

        <?php if (empty($items)): ?>
            <p class="empty">این فاکتور آیتمی ندارد.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem; margin-top:8px; min-width:820px;">
            <thead>
            <tr>
                <th style="border:1px solid #e5e7eb; background:#f3f4f6; padding:8px; text-align:right;"># کیس</th>
                <th style="border:1px solid #e5e7eb; background:#f3f4f6; padding:8px; text-align:right;">بیمار</th>
                <th style="border:1px solid #e5e7eb; background:#f3f4f6; padding:8px; text-align:right;">پزشک</th>
                <th style="border:1px solid #e5e7eb; background:#f3f4f6; padding:8px; text-align:right;">خدمت</th>
                <th style="border:1px solid #e5e7eb; background:#f3f4f6; padding:8px; text-align:right;">تعداد</th>
                <th style="border:1px solid #e5e7eb; background:#f3f4f6; padding:8px; text-align:right;">فی برون‌سپاری (تومان)</th>
                <th style="border:1px solid #e5e7eb; background:#f3f4f6; padding:8px; text-align:right;">جمع ردیف</th>
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
                    <input type="number" class="row-unit" name="items[<?= $iid ?>][unit]" value="<?= round((float) ($it['unit_rate'] ?? 0)) ?>" min="0" step="1" style="width:130px;">
                </td>
                <td style="border:1px solid #e5e7eb; padding:6px 8px;" class="row-total"><?= formatAmountToman((float) ($it['total_amount'] ?? 0)) ?></td>
                <td style="border:1px solid #e5e7eb; padding:6px 8px; text-align:center;">
                    <input type="checkbox" class="row-remove" name="items[<?= $iid ?>][remove]" value="1" title="حذف این ردیف">
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <th colspan="6" style="border:1px solid #e5e7eb; background:#f9fafb; padding:8px; text-align:left;">جمع کل جدید</th>
                <th colspan="2" style="border:1px solid #e5e7eb; background:#f9fafb; padding:8px; text-align:right; color:#b91c1c;" id="grand-total"><?= formatAmountToman((float) $invoice['total_amount']) ?> تومان</th>
            </tr>
            </tfoot>
        </table>
        </div>
        <script>
        (function(){
            var tbody = document.querySelector('form table tbody');
            if (!tbody) return;
            var grand = document.getElementById('grand-total');
            function faNum(n){ return Number(n||0).toLocaleString('fa-IR'); }
            function recalc(){
                var total = 0;
                tbody.querySelectorAll('tr[data-row]').forEach(function(tr){
                    var rm = tr.querySelector('.row-remove');
                    var qty = parseInt((tr.querySelector('.row-qty')||{}).value || '0', 10) || 0;
                    var unit = parseFloat((tr.querySelector('.row-unit')||{}).value || '0') || 0;
                    var amount = (rm && rm.checked) ? 0 : (qty * unit);
                    tr.querySelector('.row-total').textContent = faNum(amount);
                    total += amount;
                });
                grand.textContent = faNum(total) + ' تومان';
            }
            tbody.addEventListener('input', recalc);
            tbody.addEventListener('change', recalc);
            recalc();
        })();
        </script>
        <?php endif; ?>

        <h4 style="margin:22px 0 10px;">افزودن کیس به این فاکتور</h4>
        <?php if (empty($addableCases)): ?>
            <p class="empty">کیسِ بدون فاکتوری برای این لابراتوار وجود ندارد.</p>
        <?php else: ?>
            <p style="color:#6b7280; font-size:0.85rem; margin:0 0 6px;">کیس‌های برون‌سپاری‌شده و بدون فاکتور را انتخاب کن (با نگه‌داشتن Ctrl چندتا). با ذخیره، به همین فاکتور اضافه می‌شوند.</p>
            <select name="add_case_ids[]" multiple size="8" style="width:100%; min-height:170px; padding:6px; border:1px solid #d1d5db; border-radius:6px;">
                <?php foreach ($addableCases as $c): ?>
                    <option value="<?= (int) $c['id'] ?>">
                        #<?= (int) $c['id'] ?> — <?= htmlspecialchars($c['patient_name'] ?? '—') ?> — <?= htmlspecialchars($c['service_title'] ?? '—') ?> — پزشک: <?= htmlspecialchars($c['doctor_name'] ?? '—') ?> — تعداد <?= toPersianDigits((int) ($c['quantity'] ?? 1)) ?> — فی <?= formatAmountToman((float) ($c['unit_rate'] ?? 0)) ?> تومان — <?= htmlspecialchars(toJalaliDateFormatted($c['received_date'] ?? '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <div style="margin-top:18px; display:flex; gap:12px; align-items:center;">
            <button type="submit" class="btn" style="background:#06B6D4; color:#fff;">ذخیره تغییرات</button>
            <a href="outsource_invoices.php" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</a>
        </div>
    </div>
</form>

<script>
(function () {
    if (window.persianDatepicker) {
        window.persianDatepicker('#invoice_date', { format: 'YYYY/MM/DD', autoClose: true });
    }
})();
</script>
<?php panel_layout_end(); ?>
