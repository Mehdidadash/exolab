<?php
// panel/designer_invoices.php
// List of freelance designer (design-fee) invoices.
require_once __DIR__ . '/auth.php';
require_role('admin');

$invoices = getAllDesignerInvoices();

panel_layout_start('فاکتورهای طراحی');
?>
<div style="margin-bottom: 18px; display: flex; gap: 10px; flex-wrap: wrap; justify-content: space-between; align-items: center;">
    <div>
        <a class="btn" href="generate_designer_invoice.php" style="background: #d97706; color: #fff;">صدور فاکتور طراحی</a>
        <a class="btn" href="invoices.php" style="background: #E5E7EB; color: #0F172A;">بازگشت به فاکتورها</a>
    </div>
</div>

<?php if (empty($invoices)): ?>
    <p class="empty">هیچ فاکتور طراحی ثبت نشده است.</p>
<?php else: ?>
<table class="datatable display" data-order="2">
    <thead>
    <tr>
        <th>شماره</th>
        <th>طراح</th>
        <th>بازه</th>
        <th>تاریخ</th>
        <th>مبلغ</th>
        <th>عملیات</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($invoices as $inv): ?>
        <tr>
            <td><?= htmlspecialchars($inv['invoice_number']) ?></td>
            <td><?= htmlspecialchars($inv['designer_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($inv['period_label'] ?? '—') ?></td>
            <td><?= toJalaliDateFormatted($inv['invoice_date']) ?></td>
            <td><?= formatAmountToman($inv['total_amount']) ?></td>
            <td class="actions">
                <a class="btn" href="designer_invoice_pdf.php?id=<?= (int) $inv['id'] ?>" target="_blank" style="background:#E5E7EB; color:#0F172A; padding:4px 10px; text-decoration:none;">PDF</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php panel_layout_end(); ?>
