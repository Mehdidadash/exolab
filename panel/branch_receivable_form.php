<?php
// panel/branch_receivable_form.php — ویرایش فاکتور طلب از شعبه همکار
require_once __DIR__ . '/auth.php';
require_admin();

$invoiceId = (int) ($_GET['id'] ?? 0);
$invoice = getBranchReceivable($invoiceId);
if (!$invoice) {
    header('Location: branch_receivables.php?error=notfound');
    exit;
}
$items = getBranchReceivableItems($invoiceId);
$paid = getBranchReceivablePaid($invoiceId);

$invoiceDate = toJalaliDateFormatted($invoice['invoice_date'] ?? date('Y-m-d'));
$periodLabel = (string) ($invoice['period_label'] ?? '');
$notes = (string) ($invoice['notes'] ?? '');

panel_layout_start('ویرایش فاکتور طلب از شعبه');
?>
<link rel="stylesheet" href="../assets/css/persian-datepicker.min.css">
<script src="../assets/js/persian-date.min.js"></script>
<script src="../assets/js/persian-datepicker.min.js"></script>
<div style="margin-bottom:18px; display:flex; gap:10px; flex-wrap:wrap; justify-content:space-between; align-items:center;">
    <div>
        <a class="btn" href="branch_receivables.php" style="background:#E5E7EB; color:#0F172A;">بازگشت به فاکتورهای طلب</a>
        <a class="btn" href="branch_receivable_pdf.php?id=<?= (int) $invoiceId ?>" target="_blank" style="background:#0F172A; color:#fff;">PDF</a>
    </div>
</div>

<?php if (isset($_GET['ok'])): ?>
    <div style="margin-bottom:16px; padding:14px 18px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; color:#166534;">تغییرات با موفقیت ذخیره شد.</div>
<?php endif; ?>

<form method="post" action="save_branch_receivable.php">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $invoiceId ?>">

    <div class="form-card">
        <h3 style="margin-bottom:6px;">ویرایش فاکتور طلب — <?= htmlspecialchars($invoice['invoice_number']) ?></h3>
        <p style="color:#525252; margin-bottom:4px;">شعبه همکار (بدهکار): <strong><?= htmlspecialchars($invoice['partner_branch_name'] ?? '—') ?></strong></p>
        <p style="color:#525252; margin-bottom:16px;">
            دریافت‌شده: <?= formatAmountToman($paid) ?> —
            مبلغ فعلی: <?= formatAmountToman($invoice['total_amount']) ?> تومان
        </p>

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
        <p style="color:#6b7280; font-size:0.85rem;">تعداد و نرخ را ویرایش کنید؛ با تیک «حذف» ردیف از فاکتور حذف و کیس آن برای صدور مجدد آزاد می‌شود. جمع کل و وضعیت پرداخت پس از ذخیره دوباره محاسبه می‌شود.</p>

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
                <th style="border:1px solid #e5e7eb; background:#f3f4f6; padding:8px; text-align:right;">نرخ (تومان)</th>
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
                <td style="border:1px solid #e5e7eb; padding:6px 8px; text-align:center;">
                    <input type="checkbox" class="row-remove" name="items[<?= $iid ?>][remove]" value="1" title="حذف این ردیف">
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div style="margin-top:18px; display:flex; gap:12px; align-items:center;">
            <button type="submit" class="btn" style="background:#06B6D4; color:#fff;">ذخیره تغییرات</button>
            <a href="branch_receivables.php" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</a>
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
