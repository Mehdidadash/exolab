<?php
// panel/invoices.php
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();
$isAdmin = ($user['role'] === 'admin');
$isDoctor = ($user['role'] === 'doctor');
$doctorId = $isDoctor ? $user['id'] : null;

if ($isDoctor) {
    $invoices = getInvoicesForDoctor($doctorId);
} else {
    $invoices = getAllInvoices();
}

panel_layout_start($isDoctor ? 'فاکتورهای من' : 'لیست فاکتورها');
?>
<?php if ($isAdmin): ?>
<div style="margin-bottom: 18px;">
    <a class="btn" href="invoice_form.php">ایجاد فاکتور جدید</a>
    <a class="btn" href="generate_invoice.php" style="background: #0F172A; color: #fff;">صدور فاکتور ماهانه</a>
    <a class="btn" href="bank_accounts.php" style="background: #0F172A; color: #fff;">مدیریت حسابهای بانکی</a>
</div>
<?php endif; ?>
<table>
    <thead>
    <tr>
        <th>شماره فاکتور</th>
        <?php if ($isAdmin): ?><th>نام دکتر</th><?php endif; ?>
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
            <?php if ($isAdmin): ?><td><?= htmlspecialchars($invoice['doctor_name']) ?></td><?php endif; ?>
            <td><?= toJalaliDateFormatted($invoice['invoice_date']) ?></td>
            <td><?= formatAmountToman($invoice['total_amount']) ?></td>
            <td><span class="badge"><?= $invoice['payment_status'] === 'paid' ? 'پرداخت شده' : 'پرداخت نشده' ?></span></td>
            <td class="actions">
                <?php if ($isAdmin): ?>
                    <a class="btn" href="invoice_form.php?id=<?= $invoice['id'] ?>">ویرایش</a>
                    <a class="btn" href="invoice_pdf.php?id=<?= $invoice['id'] ?>" target="_blank">PDF</a>
                    <form method="post" action="delete_invoice.php" style="display:inline;" onsubmit="return confirm('آیا مطمئن هستید؟');">
                        <input type="hidden" name="id" value="<?= $invoice['id'] ?>">
                        <button type="submit">حذف</button>
                    </form>
                <?php else: ?>
                    <a class="btn" href="invoice_pdf.php?id=<?= $invoice['id'] ?>" target="_blank">PDF</a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($invoices)): ?>
        <tr><td colspan="<?= $isAdmin ? 6 : 5 ?>" class="empty">فاکتوری ثبت نشده است.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php panel_layout_end(); ?>