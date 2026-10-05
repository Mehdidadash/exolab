<?php
// panel/outsource_invoices.php
// List of outsourcing invoices (what we pay labs).
require_once __DIR__ . '/auth.php';
require_admin();

$invoices = getAllOutsourceInvoices();

// مبلغ پرداخت‌شدهٔ هر فاکتور — برای هشدارِ حذف (حذف فاکتور پرداخت‌شده خطرناک است)
$paidMap = [];
foreach ($invoices as $inv) {
    $paidMap[(int) $inv['id']] = getExpenseInvoicePaid('outsource', (int) $inv['id']);
}

// پیام نتیجهٔ حذف
$deletedItems = isset($_GET['deleted']) ? (int) $_GET['deleted'] : -1;
$delReason    = (string) ($_GET['reason'] ?? '');

panel_layout_start('فاکتورهای برون‌سپاری');
?>
<?php if ($deletedItems > 0): ?>
    <div style="margin-bottom:16px; padding:12px 16px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; color:#166534;">
        ✅ فاکتور حذف شد.
        <span style="font-size:0.87rem;">
            — آیتم‌های حذف‌شده: <strong><?= toPersianDigits((string) $deletedItems) ?></strong>
            — کیس‌های آزادشده: <strong><?= toPersianDigits((string) (int) ($_GET['cases'] ?? 0)) ?></strong>
            <?php if (!empty($_GET['payments'])): ?>
                — پرداخت‌های حذف‌شده: <strong><?= formatAmountToman((float) $_GET['payments']) ?> تومان</strong>
            <?php endif; ?>
        </span>
        <div style="font-size:0.85rem; margin-top:4px;">کیس‌های آزادشده اکنون در فهرست «افزودن کیس به فاکتور» سایر فاکتورها دیده می‌شوند.</div>
    </div>
<?php elseif ($deletedItems === 0): ?>
    <div style="margin-bottom:16px; padding:12px 16px; background:#fffbeb; border:1px solid #fde68a; border-radius:8px; color:#92400e;">
        ⚠️ فاکتور حذف نشد<?= $delReason === 'notfound' ? ' — فاکتور یافت نشد.' : ($delReason === 'has_payments' ? ' — روی این فاکتور پرداخت ثبت شده است؛ برای حذف باید تأیید کنید.' : '.') ?>
    </div>
<?php endif; ?>

<div style="margin-bottom: 18px; display: flex; gap: 10px; flex-wrap: wrap; justify-content: space-between; align-items: center;">
    <div>
        <a class="btn" href="generate_outsource_invoice.php" style="background: #059669; color: #fff;">صدور فاکتور برون‌سپاری</a>
        <a class="btn" href="outsource_rates.php" style="background: #0F172A; color: #fff;">نرخ‌های برون‌سپاری</a>
        <a class="btn" href="expenses.php" style="background: #b45309; color: #fff;">فاکتورهای مخارج</a>
        <a class="btn" href="invoices.php" style="background: #E5E7EB; color: #0F172A;">بازگشت به فاکتورها</a>
    </div>
</div>

<?php if (empty($invoices)): ?>
    <p class="empty">هیچ فاکتور برون‌سپاری ثبت نشده است.</p>
<?php else: ?>
<table class="datatable display" data-order="2">
    <thead>
    <tr>
        <th>شماره</th>
        <th>لابراتوار</th>
        <th>بازه</th>
        <th>تاریخ</th>
        <th>مبلغ</th>
        <th>وضعیت پرداخت</th>
        <th>عملیات</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($invoices as $inv):
        $invId = (int) $inv['id'];
        $paid  = (float) ($paidMap[$invId] ?? 0);
        $total = (float) ($inv['total_amount'] ?? 0);
        $payStatus = (string) ($inv['payment_status'] ?? 'unpaid');
        $payMeta = [
            'paid'    => ['پرداخت‌شده', '#dcfce7', '#166534'],
            'partial' => ['پرداخت جزئی', '#fef3c7', '#92400e'],
            'unpaid'  => ['پرداخت‌نشده', '#f3f4f6', '#374151'],
        ][$payStatus] ?? ['—', '#f3f4f6', '#374151'];
    ?>
        <tr>
            <td><?= htmlspecialchars($inv['invoice_number']) ?></td>
            <td><?= htmlspecialchars($inv['lab_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($inv['period_label'] ?? '—') ?></td>
            <td><?= toJalaliDateFormatted($inv['invoice_date']) ?></td>
            <td><?= formatAmountToman($inv['total_amount']) ?></td>
            <td>
                <span class="badge" style="background:<?= $payMeta[1] ?>; color:<?= $payMeta[2] ?>;"><?= htmlspecialchars($payMeta[0]) ?></span>
                <?php if ($paid > 0): ?>
                    <div style="font-size:0.75rem; color:#6b7280; margin-top:2px;">پرداخت‌شده: <?= formatAmountToman($paid) ?></div>
                <?php endif; ?>
            </td>
            <td class="actions" style="white-space:nowrap;">
                <a class="btn" href="outsource_invoice_form.php?id=<?= $invId ?>" title="ویرایش" style="background:#eef2ff; color:#3730a3; padding:4px 10px; text-decoration:none;">✏️</a>
                <a class="btn" href="outsource_invoice_pdf.php?id=<?= $invId ?>" target="_blank" style="background:#E5E7EB; color:#0F172A; padding:4px 10px; text-decoration:none;">PDF</a>
                <button type="button" class="btn js-del-outsource-inv"
                        style="background:#fee2e2; color:#991b1b; padding:4px 10px;"
                        title="حذف فاکتور"
                        data-id="<?= $invId ?>"
                        data-number="<?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES) ?>"
                        data-lab="<?= htmlspecialchars((string) ($inv['lab_name'] ?? ''), ENT_QUOTES) ?>"
                        data-total="<?= htmlspecialchars(number_format($total), ENT_QUOTES) ?>"
                        data-paid="<?= htmlspecialchars(number_format($paid), ENT_QUOTES) ?>">🗑</button>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php // ── مودال تأیید حذف ── ?>
<style>
    .oi-modal { position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,0.45); z-index:9999; }
    .oi-modal .oi-box { background:#fff; border-radius:10px; max-width:520px; width:94%; padding:20px; box-shadow:0 10px 30px rgba(0,0,0,0.25); }
    .oi-modal h3 { margin:0 0 10px; color:#991b1b; }
</style>
<div id="oi-del-modal" class="oi-modal">
    <div class="oi-box">
        <h3>🗑 حذف فاکتور برون‌سپاری</h3>
        <p style="line-height:1.9;">
            فاکتور <strong id="oi-del-number"></strong>
            <?php // eslint-disable-next-line ?>
            برای <strong id="oi-del-lab"></strong> با مبلغ <strong id="oi-del-total"></strong> تومان حذف شود؟
        </p>
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; font-size:0.87rem; line-height:1.9; color:#334155;">
            این عملیات برگشت‌ناپذیر است و موارد زیر هم انجام می‌شود:
            <ul style="margin:6px 0 0; padding-inline-start:20px;">
                <li>همهٔ آیتم‌های این فاکتور حذف می‌شوند.</li>
                <li>کیس‌های متصل <strong>آزاد</strong> می‌شوند و می‌توان دوباره برایشان فاکتور صادر کرد.</li>
                <li>پرداخت‌های ثبت‌شده روی این فاکتور حذف می‌شوند.</li>
            </ul>
        </div>
        <div id="oi-del-pay-warn" style="display:none; margin-top:10px; background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:10px 12px; color:#92400e; font-size:0.87rem;">
            ⚠️ روی این فاکتور <strong id="oi-del-paid"></strong> تومان پرداخت ثبت شده است.
            <label style="display:flex; align-items:center; gap:8px; margin-top:8px; cursor:pointer; font-weight:700;">
                <input type="checkbox" id="oi-del-confirm-pay">
                می‌دانم و می‌خواهم پرداخت‌ها هم حذف شوند
            </label>
        </div>
        <form method="post" action="delete_outsource_invoice.php" id="oi-del-form" style="margin-top:16px;">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="oi-del-id">
            <input type="hidden" name="confirm_payments" id="oi-del-confirm-hidden" value="">
            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" id="oi-del-cancel" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</button>
                <button type="submit" id="oi-del-submit" class="btn" style="background:#dc2626; color:#fff;">حذف فاکتور</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal   = document.getElementById('oi-del-modal');
    var idEl    = document.getElementById('oi-del-id');
    var numEl   = document.getElementById('oi-del-number');
    var labEl   = document.getElementById('oi-del-lab');
    var totEl   = document.getElementById('oi-del-total');
    var paidEl  = document.getElementById('oi-del-paid');
    var warnBox = document.getElementById('oi-del-pay-warn');
    var payCb   = document.getElementById('oi-del-confirm-pay');
    var hidPay  = document.getElementById('oi-del-confirm-hidden');
    var submit  = document.getElementById('oi-del-submit');
    if (!modal) return;

    function openModal(btn) {
        idEl.value  = btn.getAttribute('data-id') || '';
        numEl.textContent = btn.getAttribute('data-number') || '';
        labEl.textContent = btn.getAttribute('data-lab') || '—';
        totEl.textContent = btn.getAttribute('data-total') || '0';
        paidEl.textContent = btn.getAttribute('data-paid') || '0';

        var paid = parseFloat((btn.getAttribute('data-paid') || '0').replace(/[^0-9.]/g, '')) || 0;
        // اگر پرداختی هست، چک‌باکس تأیید لازم است و دکمه تا تیک‌خوردن غیرفعال می‌ماند
        warnBox.style.display = paid > 0 ? 'block' : 'none';
        if (payCb) payCb.checked = false;
        hidPay.value = '';
        submit.disabled = paid > 0;
        submit.style.opacity = paid > 0 ? '.55' : '1';
        submit.style.cursor  = paid > 0 ? 'not-allowed' : 'pointer';

        modal.style.display = 'flex';
    }
    function closeModal() { modal.style.display = 'none'; }

    document.querySelectorAll('.js-del-outsource-inv').forEach(function (b) {
        b.addEventListener('click', function () { openModal(b); });
    });
    var cancel = document.getElementById('oi-del-cancel');
    if (cancel) cancel.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Escape' || e.key === 'Esc') && modal.style.display === 'flex') closeModal();
    });
    if (payCb) payCb.addEventListener('change', function () {
        hidPay.value = payCb.checked ? '1' : '';
        submit.disabled = !payCb.checked;
        submit.style.opacity = payCb.checked ? '1' : '.55';
        submit.style.cursor  = payCb.checked ? 'pointer' : 'not-allowed';
    });
})();
</script>
<?php endif; ?>
<?php panel_layout_end(); ?>

