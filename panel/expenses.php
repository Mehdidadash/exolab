<?php
// panel/expenses.php
// Unified "مخارج" (payables/expenses) page.
// Everything we OWE to others: freelance designer (design-fee) invoices and
// outsourcing invoices (costs paid to labs). One page instead of two separate ones.
require_once __DIR__ . '/auth.php';
require_login();
if (!is_admin()) {
    die('دسترسی غیرمجاز');
}

$designerInvoices = getAllDesignerInvoices();
$outsourceInvoices = getAllOutsourceInvoices();

// Merge into one list with a common shape for the table
$rows = [];
foreach ($designerInvoices as $inv) {
    $paid = getExpenseInvoicePaid('designer', (int) $inv['id']);
    $rows[] = [
        'type' => 'designer',
        'type_label' => 'هزینه طراحی',
        'invoice_number' => $inv['invoice_number'],
        'party' => $inv['designer_name'] ?? '—',
        'period_label' => $inv['period_label'] ?? '—',
        'invoice_date' => $inv['invoice_date'],
        'total_amount' => (float) $inv['total_amount'],
        'paid_amount' => $paid,
        'payment_status' => $inv['payment_status'] ?? 'unpaid',
        'invoice_id' => (int) $inv['id'],
        'pdf' => 'designer_invoice_pdf.php?id=' . (int) $inv['id'],
        'generation' => 'generate_designer_invoice.php',
    ];
}
foreach ($outsourceInvoices as $inv) {
    $paid = getExpenseInvoicePaid('outsource', (int) $inv['id']);
    $rows[] = [
        'type' => 'outsource',
        'type_label' => 'برون‌سپاری',
        'invoice_number' => $inv['invoice_number'],
        'party' => $inv['lab_name'] ?? '—',
        'period_label' => $inv['period_label'] ?? '—',
        'invoice_date' => $inv['invoice_date'],
        'total_amount' => (float) $inv['total_amount'],
        'paid_amount' => $paid,
        'payment_status' => $inv['payment_status'] ?? 'unpaid',
        'invoice_id' => (int) $inv['id'],
        'pdf' => 'outsource_invoice_pdf.php?id=' . (int) $inv['id'],
        'generation' => 'generate_outsource_invoice.php',
    ];
}
usort($rows, function ($a, $b) {
    return strcmp($b['invoice_date'], $a['invoice_date']);
});

$totalExpenses = array_sum(array_column($rows, 'total_amount'));

panel_layout_start('فاکتورهای مخارج (بدهی‌ها)');
?>
<div style="margin-bottom: 18px; display: flex; gap: 10px; flex-wrap: wrap; justify-content: space-between; align-items: center;">
    <div>
        <a class="btn" href="generate_designer_invoice.php" style="background: #d97706; color: #fff;">صدور فاکتور طراحی</a>
        <a class="btn" href="generate_outsource_invoice.php" style="background: #059669; color: #fff;">صدور فاکتور برون‌سپاری</a>
        <a class="btn" href="outsource_rates.php" style="background: #0F172A; color: #fff;">نرخ‌های برون‌سپاری</a>
        <a class="btn" href="invoices.php" style="background: #E5E7EB; color: #0F172A;">بازگشت به فاکتورها</a>
    </div>
</div>

<?php if (!empty($rows)): ?>
<div class="form-card" style="margin-bottom:16px; padding:14px 18px; display:flex; gap:20px; flex-wrap:wrap; align-items:center;">
    <strong>جمع کل مخارج (بدهی‌ها): <span style="color:#b91c1c;"><?= formatAmountToman($totalExpenses) ?> تومان</span></strong>
    <span style="color:#525252; font-size:0.85rem;"><?= toPersianDigits(count($rows)) ?> فاکتور</span>
</div>
<?php endif; ?>

<?php if (empty($rows)): ?>
    <p class="empty">هیچ فاکتور مخارجی ثبت نشده است. از دکمه‌های بالا می‌توانید فاکتور طراحی یا برون‌سپاری صادر کنید.</p>
<?php else: ?>
<table class="datatable display" data-order="3">
    <thead>
    <tr>
        <th>نوع</th>
        <th>شماره</th>
        <th>طرف (گیرنده)</th>
        <th>بازه</th>
        <th>تاریخ</th>
        <th>مبلغ</th>
        <th>پرداخت</th>
        <th>عملیات</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td>
                <?php if ($r['type'] === 'designer'): ?>
                    <span class="badge" style="background:#fef3c7; color:#92400e;"><?= htmlspecialchars($r['type_label']) ?></span>
                <?php else: ?>
                    <span class="badge" style="background:#dcfce7; color:#166534;"><?= htmlspecialchars($r['type_label']) ?></span>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($r['invoice_number']) ?></td>
            <td><?= htmlspecialchars($r['party']) ?></td>
            <td><?= htmlspecialchars($r['period_label']) ?></td>
            <td><?= toJalaliDateFormatted($r['invoice_date']) ?></td>
            <td style="font-weight:bold; color:#b91c1c;"><?= formatAmountToman($r['total_amount']) ?></td>
            <td>
                <?php
                $st = $r['payment_status'];
                if ($st === 'paid'): ?>
                    <span class="badge" style="background:#dcfce7; color:#166534;">پرداخت شده (<?= formatAmountToman($r['paid_amount']) ?>)</span>
                <?php elseif ($st === 'partial'): ?>
                    <span class="badge" style="background:#fef3c7; color:#92400e;">جزئی (<?= formatAmountToman($r['paid_amount']) ?>)</span>
                <?php else: ?>
                    <span class="badge" style="background:#fee2e2; color:#991b1b;">پرداخت نشده</span>
                <?php endif; ?>
            </td>
            <td class="actions">
                <a class="btn" href="expense_payment_form.php?type=<?= $r['type'] ?>&invoice_id=<?= $r['invoice_id'] ?>" style="background:#06B6D4; color:#fff; padding:4px 10px; text-decoration:none;">💳 ثبت پرداخت</a>
                <a class="btn" href="<?= htmlspecialchars($r['pdf']) ?>" target="_blank" style="background:#E5E7EB; color:#0F172A; padding:4px 10px; text-decoration:none;">PDF</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php panel_layout_end(); ?>
