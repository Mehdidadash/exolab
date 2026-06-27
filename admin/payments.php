<?php
require_once __DIR__ . '/auth.php';
$payments = getAllPayments();
admin_layout_start('لیست پرداخت‌ها');
?>
<div style="margin-bottom: 18px;">
    <a class="btn" href="payment_form.php">ثبت پرداخت جدید</a>
</div>
<table>
    <thead>
    <tr>
        <th>نام دکتر</th>
        <th>مبلغ</th>
        <th>روش پرداخت</th>
        <th>تاریخ</th>
        <th>شماره تراکنش</th>
        <th>حساب بانکی</th>
        <th>عملیات</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($payments as $payment): ?>
        <tr>
            <td><?= htmlspecialchars($payment['doctor_name']) ?></td>
            <td><?= formatAmountToman($payment['amount']) ?></td>
            <td><?= htmlspecialchars($payment['payment_method']) ?></td>
            <td><?= toJalaliDateFormatted($payment['payment_date']) ?></td>
            <td><small><?= htmlspecialchars($payment['transaction_number'] ?? '—') ?></small></td>
            <td><small><?= htmlspecialchars($payment['account_owner_name'] ?? '—') ?></small></td>
            <td class="actions">
                <a class="btn" href="payment_form.php?id=<?= $payment['id'] ?>">ویرایش</a>
                <form method="post" action="delete_payment.php" style="display:inline;" onsubmit="return confirm('آیا مطمئن هستید؟');">
                    <input type="hidden" name="id" value="<?= $payment['id'] ?>">
                    <button type="submit">حذف</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php admin_layout_end();
