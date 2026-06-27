<?php
require_once __DIR__ . '/auth.php';
$invoices = getAllInvoices();
admin_layout_start('لیست فاکتورها');
?>
<div style="margin-bottom: 18px;">
    <a class="btn" href="invoice_form.php">ایجاد فاکتور جدید</a>
    <a class="btn" href="bank_accounts.php" style="background: #0F172A; color: #fff;">مدیریت حسابهای بانکی</a>
</div>
<table>
    <thead>
    <tr>
        <th>شماره فاکتور</th>
        <th>نام دکتر</th>
        <th>تاریخ</th>
        <th>مبلغ</th>
        <th>وضعیت</th>
        <th>عملیات</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($invoices as $invoice): ?>
        <tr>
            <td><?= htmlspecialchars($invoice['invoice_number']) ?></td>
            <td><?= htmlspecialchars($invoice['doctor_name']) ?></td>
            <td><?= toJalaliDateFormatted($invoice['invoice_date']) ?></td>
            <td><?= formatAmountToman($invoice['total_amount']) ?></td>
            <td><span class="badge"><?= $invoice['payment_status'] === 'paid' ? 'پرداخت شده' : 'پرداخت نشده' ?></span></td>
            <td class="actions">
                <a class="btn" href="invoice_form.php?id=<?= $invoice['id'] ?>">ویرایش</a>
                <a class="btn" href="invoice_pdf.php?id=<?= $invoice['id'] ?>" target="_blank">پیش‌نمایش PDF</a>
                <form method="post" action="delete_invoice.php" style="display:inline;" onsubmit="return confirm('آیا مطمئن هستید؟');">
                    <input type="hidden" name="id" value="<?= $invoice['id'] ?>">
                    <button type="submit">حذف</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php admin_layout_end();
