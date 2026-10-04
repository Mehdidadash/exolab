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

// کیس‌هایی که می‌توان به این فاکتور اضافه کرد (همهٔ ماه‌ها، فاکتورنشده).
// این همان قابلیتی است که فاکتور پزشک/طراح دارد: افزودن کیس از ماه‌های دیگر.
$partnerBranchId = (int) ($invoice['partner_branch_id'] ?? 0);
$availableCases = getBranchReceivableAvailableCases($invoiceId, $partnerBranchId ?: null);

// ردیف‌های «یتیم»: آیتمی که کیسش دیگر در سیستم نیست (بعد از پاک شدن کیس).
// این‌ها نباید نرخ‌شان صفر شود؛ نرخ ذخیره‌شده را نگه می‌داریم.
$itemCaseIds = array_values(array_filter(array_map(function ($it) { return (int) ($it['case_id'] ?? 0); }, $items)));
$existingCaseIds = [];
if (!empty($itemCaseIds)) {
    $ph = implode(',', array_fill(0, count($itemCaseIds), '?'));
    $cs = db()->prepare("SELECT id FROM cases WHERE id IN ($ph)");
    $cs->execute($itemCaseIds);
    $existingCaseIds = array_map('intval', array_column($cs->fetchAll(), 'id'));
}
$orphanCaseIds = array_flip(array_diff($itemCaseIds, $existingCaseIds));

// ماه‌های فاکتور از تاریخِ دریافت خودِ کیس‌ها استخراج می‌شود (روش فاکتور پزشک).
// اگر بعداً کیسی از ماه دیگری اضافه شود، همین‌جا خودبه‌خود دیده می‌شود.
$caseMonthsPhrase = invoiceItemsMonthPart($items, $invoice['invoice_date'] ?? null);

$flash = '';
if (isset($_GET['ok'])) {
    $flash = '<div style="margin-bottom:16px; padding:14px 18px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; color:#166534;">تغییرات با موفقیت ذخیره شد.</div>';
} elseif (isset($_GET['added'])) {
    $flash = '<div style="margin-bottom:16px; padding:14px 18px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; color:#1e40af;">' . (int) $_GET['added'] . ' کیس به فاکتور اضافه شد.</div>';
} elseif (isset($_GET['error']) && $_GET['error'] === 'nopick') {
    $flash = '<div style="margin-bottom:16px; padding:14px 18px; background:#fffbeb; border:1px solid #fde68a; border-radius:8px; color:#92400e;">هیچ کیسی انتخاب نشده بود.</div>';
}

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

<?= $flash ?>

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
            <small style="color:#525252;">
                اگر کیسی از ماه دیگری به فاکتور اضافه شود، ماهِ آن خودکار از تاریخ کیس‌ها استخراج و در فاکتور نشان داده می‌شود.
            </small>
        </div>
        <div class="form-group">
            <label>یادداشت‌ها</label>
            <textarea name="notes" rows="3"><?= htmlspecialchars($notes) ?></textarea>
        </div>

        <?php if ($caseMonthsPhrase !== ''): ?>
        <div style="margin:14px 0; padding:10px 14px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; color:#075985; font-size:0.88rem;">
            <strong>ماه‌های این فاکتور (از تاریخ کیس‌ها):</strong> <?= htmlspecialchars($caseMonthsPhrase) ?>
            <?php if ($periodLabel !== '' && $caseMonthsPhrase !== $periodLabel): ?>
                <br><span style="color:#0369a1;">برچسب ثبت‌شده: «<?= htmlspecialchars($periodLabel) ?>» — در فاکتور، ماه‌های واقعی کیس‌ها نشان داده می‌شود.</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <h4 style="margin:22px 0 10px;">آیتم‌های فاکتور</h4>
        <p style="color:#6b7280; font-size:0.85rem;">تعداد و نرخ را ویرایش کنید؛ با تیک «حذف» ردیف از فاکتور حذف و کیس آن برای صدور مجدد آزاد می‌شود. جمع کل و وضعیت پرداخت پس از ذخیره دوباره محاسبه می‌شود.</p>

        <?php
        renderInvoiceItemsEditor($items, [
            'mode'            => 'edit',
            'invoice_id'      => $invoiceId,
            'orphan_case_ids' => $orphanCaseIds,
            'empty_message'   => 'این فاکتور آیتمی ندارد.',
            'table_id'        => 'receivable-items-edit',
        ]);
        ?>

        <div style="margin-top:18px; display:flex; gap:12px; align-items:center;">
            <button type="submit" class="btn" style="background:#06B6D4; color:#fff;">ذخیره تغییرات</button>
            <a href="branch_receivables.php" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</a>
        </div>
    </div>
</form>

<!-- ─── افزودن کیس از ماه‌های دیگر (مثل فاکتور پزشک/طراح) ─── -->
<div class="form-card" style="margin-top:22px;">
    <h4 style="margin:0 0 6px;">افزودن کیس از ماه‌های دیگر</h4>
    <p style="color:#6b7280; font-size:0.85rem; margin-bottom:12px;">
        کیس‌های برون‌سپاری‌شده از <strong><?= htmlspecialchars($invoice['partner_branch_name'] ?? 'این شعبه') ?></strong>
        که هنوز در هیچ فاکتور طلبی نیامده‌اند (بدون محدودیت ماه). انتخاب کنید و «افزودن به فاکتور» را بزنید.
    </p>

    <?php
    renderInvoiceItemsEditor($availableCases, [
        'mode'          => 'add',
        'form_action'   => 'add_branch_receivable_cases.php',
        'invoice_id'    => $invoiceId,
        'empty_message' => 'کیس فاکتورنشدهٔ دیگری از این شعبه وجود ندارد.',
        'table_id'      => 'receivable-items-add',
    ]);
    ?>
</div>

<script>
(function () {
    if (window.persianDatepicker) {
        window.persianDatepicker('#invoice_date', { format: 'YYYY/MM/DD', autoClose: true });
    }
})();
</script>
<?php invoiceItemsEditorScript(); ?>
<?php panel_layout_end(); ?>
