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
        $payRows = getBranchReceivablePayments((int) $inv['id']);
        // بیش‌پرداخت کاملاً طبیعی است: شعبه ممکن است بیشتر از مبلغ فاکتور پرداخت کند
        $overpaid = max(0.0, $paid - (float) $inv['total_amount']);
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
                    <?php if ($overpaid > 0.5): ?>
                        <span class="badge" style="background:#fef3c7; color:#92400e;" title="مبلغ اضافه به‌صورت طلب از این شعبه در «خالص بدهی هر شعبه» لحاظ می‌شود">بیش‌پرداخت <?= formatAmountToman($overpaid) ?></span>
                    <?php endif; ?>
                <?php elseif ($st === 'partial'): ?>
                    <span class="badge" style="background:#fef3c7; color:#92400e;">جزئی</span>
                <?php else: ?>
                    <span class="badge" style="background:#fee2e2; color:#991b1b;">دریافت نشده</span>
                <?php endif; ?>
            </td>
            <td class="actions" style="white-space:nowrap;">
                <a class="btn" href="payment_form.php?party_type=branch&party_id=<?= (int) ($inv['partner_branch_id'] ?? 0) ?>&receivable_id=<?= (int) $inv['id'] ?>" title="ثبت دریافت در بخش دریافتی‌ها" style="background:#06B6D4; color:#fff; padding:4px 10px; text-decoration:none;">💳 ثبت دریافت</a>
                <a class="btn" href="branch_receivable_form.php?id=<?= (int) $inv['id'] ?>" title="ویرایش" style="background:#eef2ff; color:#3730a3; padding:4px 10px; text-decoration:none;">✏️</a>
                <a class="btn" href="branch_receivable_pdf.php?id=<?= (int) $inv['id'] ?>" target="_blank" style="background:#E5E7EB; color:#0F172A; padding:4px 10px; text-decoration:none;">PDF</a>
                <?= action_dropdown(null, null, 'delete_branch_receivable.php', (int) $inv['id']) ?>
            </td>
        </tr>
        <?php if (!empty($payRows)): foreach ($payRows as $pr): ?>
        <tr style="background:#f8fafc;">
            <td colspan="8" style="font-size:0.85rem; color:#525252; padding:4px 16px;">
                <span class="badge" style="background:#e0f2fe; color:#075985;">دریافتی: <?= formatAmountToman($pr['amount']) ?></span>
                <?= $pr['payment_date'] ? 'تاریخ: ' . toJalaliDateFormatted($pr['payment_date']) : '' ?>
                <?= !empty($pr['payment_method']) ? ' — روش: ' . htmlspecialchars($pr['payment_method']) : '' ?>
                <?= !empty($pr['transaction_number']) ? ' — تراکنش: ' . htmlspecialchars($pr['transaction_number']) : '' ?>
                <?= !empty($pr['account_owner_name']) ? ' — حساب: ' . htmlspecialchars($pr['account_owner_name']) : '' ?>
                <?= !empty($pr['notes']) ? ' — ' . htmlspecialchars($pr['notes']) : '' ?>
                <a href="payment_form.php?id=<?= (int) $pr['id'] ?>" style="margin-right:8px; font-size:0.8rem; color:#0369a1;">ویرایش در دریافتی‌ها</a>
            </td>
        </tr>
        <?php endforeach; endif; ?>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<div class="form-card" style="margin-top:16px; padding:14px 18px;">
    <p style="color:#525252; margin:0; font-size:0.9rem;">
        ثبتِ دریافت از شعبهٔ همکار از همین‌جا به بخشِ
        <a href="payments.php?type=branch">دریافتی‌ها</a> منتقل شده است: دکمهٔ «💳 ثبت دریافت» شما را به فرمِ یکپارچهٔ
        <a href="payment_form.php?party_type=branch">ثبت دریافت</a> می‌برد و رکورد با نوعِ «شعبه همکار» در همان فهرست ثبت می‌شود.
    </p>
</div>
<?php panel_layout_end(); ?>
