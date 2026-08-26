<?php
// panel/branch_receivables.php
// Receivable invoices to partner branches (فاکتور طلب از شعبه همکار):
// amounts that other branches owe US for work they outsourced to us.
require_once __DIR__ . '/auth.php';
require_admin();

$invoices = getAllBranchReceivables();

$totalReceivables = array_sum(array_map(function ($inv) { return (float) $inv['total_amount']; }, $invoices));
$totalPaid = 0;
$totalDue = 0;
foreach ($invoices as $inv) {
    $paid = getBranchReceivablePaid((int) $inv['id']);
    $totalPaid += $paid;
    $totalDue += (float) $inv['total_amount'] - $paid;
}

panel_layout_start('فاکتورهای طلب از شعبه‌ها');
?>
<div style="margin-bottom:18px; display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn" href="generate_branch_receivable.php" style="background:#059669; color:#fff;">صدور فاکتور طلب</a>
    <a class="btn" href="case_expenses.php" style="background:#E5E7EB; color:#0F172A;">کیس‌های مخارج</a>
    <a class="btn" href="financial_overview.php" style="background:#0F172A; color:#fff;">بررسی درآمد و هزینه</a>
    <a class="btn" href="expense_payments.php" style="background:#E5E7EB; color:#0F172A;">پرداخت‌های هزینه</a>
</div>

<div style="display:flex; gap:14px; flex-wrap:wrap; margin-bottom:18px;">
    <div style="flex:1; min-width:200px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; padding:14px 18px;">
        <div style="font-size:0.85rem; color:#166534;">جمع فاکتورهای طلب</div>
        <div style="font-size:1.3rem; font-weight:bold; color:#15803d; margin-top:4px;"><?= formatAmountToman($totalReceivables) ?> <small style="font-size:0.75rem;">تومان</small></div>
    </div>
    <div style="flex:1; min-width:200px; background:#fff7ed; border:1px solid #fed7aa; border-radius:12px; padding:14px 18px;">
        <div style="font-size:0.85rem; color:#9a3412;">دریافت‌نشده (باقی‌مانده)</div>
        <div style="font-size:1.3rem; font-weight:bold; color:#c2410c; margin-top:4px;"><?= formatAmountToman($totalDue) ?> <small style="font-size:0.75rem;">تومان</small></div>
    </div>
</div>

<?php if (empty($invoices)): ?>
    <p class="empty">هیچ فاکتور طلبی از شعبه‌ها صادر نشده است. از دکمه «صدور فاکتور طلب» می‌توانید فاکتور صادر کنید.</p>
<?php else: ?>
<table class="datatable display" data-order="3">
    <thead>
    <tr>
        <th>شماره</th>
        <th>شعبه همکار</th>
        <th>بازه</th>
        <th>تاریخ</th>
        <th>مبلغ</th>
        <th>پرداخت</th>
        <th>وضعیت</th>
        <th>عملیات</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($invoices as $inv): ?>
        <?php
        $paid = getBranchReceivablePaid((int) $inv['id']);
        $st = $inv['payment_status'] ?? 'unpaid';
        $payments = db()->prepare('SELECT * FROM branch_receivable_payments WHERE receivable_id = ? ORDER BY payment_date DESC, id DESC');
        $payments->execute([(int) $inv['id']]);
        $payRows = $payments->fetchAll();
        ?>
        <tr>
            <td style="font-weight:bold;"><?= htmlspecialchars($inv['invoice_number']) ?></td>
            <td><?= htmlspecialchars($inv['partner_branch_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($inv['period_label'] ?? '—') ?></td>
            <td><?= $inv['invoice_date'] ? toJalaliDateFormatted($inv['invoice_date']) : '—' ?></td>
            <td style="font-weight:bold; color:#15803d;"><?= formatAmountToman($inv['total_amount']) ?></td>
            <td><?= formatAmountToman($paid) ?> <small style="color:#525252;">از <?= formatAmountToman($inv['total_amount']) ?></small></td>
            <td>
                <?php if ($st === 'paid'): ?>
                    <span class="badge" style="background:#dcfce7; color:#166534;">تسویه شده</span>
                <?php elseif ($st === 'partial'): ?>
                    <span class="badge" style="background:#fef3c7; color:#92400e;">جزئی</span>
                <?php else: ?>
                    <span class="badge" style="background:#fee2e2; color:#991b1b;">دریافت نشده</span>
                <?php endif; ?>
            </td>
            <td class="actions" style="white-space:nowrap;">
                <button type="button" class="btn receivable-pay-toggle" data-inv="<?= (int) $inv['id'] ?>" style="background:#06B6D4; color:#fff; padding:4px 10px;">💳 ثبت دریافت</button>
                <a class="btn" href="branch_receivable_pdf.php?id=<?= (int) $inv['id'] ?>" target="_blank" style="background:#E5E7EB; color:#0F172A; padding:4px 10px; text-decoration:none;">PDF</a>
                <?= action_dropdown(null, null, 'delete_branch_receivable.php', (int) $inv['id']) ?>
            </td>
        </tr>
        <?php if (!empty($payRows)): foreach ($payRows as $pr): ?>
        <tr style="background:#f8fafc;">
            <td colspan="8" style="font-size:0.85rem; color:#525252; padding:4px 16px;">
                <span class="badge" style="background:#e0f2fe; color:#075985;">دریافتی: <?= formatAmountToman($pr['amount']) ?></span>
                <?= $pr['payment_date'] ? 'تاریخ: ' . toJalaliDateFormatted($pr['payment_date']) : '' ?>
                <?= !empty($pr['method']) ? ' — روش: ' . htmlspecialchars($pr['method']) : '' ?>
                <?= !empty($pr['reference']) ? ' — ارجاع: ' . htmlspecialchars($pr['reference']) : '' ?>
                <?= !empty($pr['notes']) ? ' — ' . htmlspecialchars($pr['notes']) : '' ?>
                <form method="post" action="delete_branch_receivable_payment.php" style="display:inline; margin-right:8px;">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="id" value="<?= (int) $pr['id'] ?>">
                    <button type="submit" style="background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; padding:2px 8px; font-size:0.8rem;">حذف</button>
                </form>
            </td>
        </tr>
        <?php endforeach; endif; ?>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- Inline payment form (shown when a "ثبت دریافت" button is clicked) -->
<div id="receivable-pay-form" class="modal" style="display:none;">
    <div class="modal-content form-card" style="max-width:460px; margin:auto; padding:24px;">
        <h3 style="margin-bottom:16px;">ثبت دریافت از شعبه همکار</h3>
        <form method="post" action="save_branch_receivable_payment.php">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="receivable_id" id="pay-receivable-id" value="">
            <div class="form-group">
                <label for="pay-amount">مبلغ دریافت (تومان)</label>
                <input type="number" name="amount" id="pay-amount" min="0" step="1" required>
            </div>
            <div class="form-group">
                <label for="pay-date">تاریخ دریافت</label>
                <input type="text" name="payment_date" id="pay-date" placeholder="۱۴۰۳/۰۱/۰۱">
            </div>
            <div class="form-group">
                <label for="pay-method">روش پرداخت</label>
                <select name="method" id="pay-method">
                    <option value="">—</option>
                    <option value="card">کارت به کارت</option>
                    <option value="cash">نقدی</option>
                    <option value="pos">POS</option>
                    <option value="transfer">حواله/پایا</option>
                </select>
            </div>
            <div class="form-group">
                <label for="pay-ref">شماره ارجاع/پیگیری</label>
                <input type="text" name="reference" id="pay-ref">
            </div>
            <div class="form-group">
                <label for="pay-notes">توضیحات</label>
                <input type="text" name="notes" id="pay-notes">
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:16px;">
                <button type="button" id="pay-cancel" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</button>
                <button type="submit" class="btn" style="background:#06B6D4; color:#fff;">ثبت دریافت</button>
            </div>
        </form>
    </div>
</div>

<link rel="stylesheet" href="../assets/css/persian-datepicker.min.css">
<script src="../assets/js/persian-date.min.js"></script>
<script src="../assets/js/persian-datepicker.min.js"></script>
<script>
(function(){
    var modal = document.getElementById('receivable-pay-form');
    var invInput = document.getElementById('pay-receivable-id');
    document.querySelectorAll('.receivable-pay-toggle').forEach(function(btn){
        btn.addEventListener('click', function(){
            invInput.value = btn.getAttribute('data-inv');
            document.getElementById('pay-amount').value = '';
            document.getElementById('pay-ref').value = '';
            document.getElementById('pay-notes').value = '';
            document.getElementById('pay-method').value = '';
            modal.style.display = 'flex';
            modal.style.alignItems = 'center';
            modal.style.justifyContent = 'center';
        });
    });
    document.getElementById('pay-cancel').addEventListener('click', function(){ modal.style.display = 'none'; });
    if (window.persianDatepicker) {
        window.persianDatepicker('#pay-date', { format: 'YYYY/MM/DD', autoClose: true });
    }
})();
</script>
<?php panel_layout_end(); ?>
