<?php
// panel\payments.php

require_once __DIR__ . '/auth.php';
$payments = getAllPayments();
panel_layout_start('لیست پرداخت‌ها');
?>
<div style="margin-bottom: 18px;">
    <a class="btn" href="payment_form.php">ثبت پرداخت جدید</a>
</div>
<table class="datatable display">
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
                <?= action_dropdown(null, 'payment_form.php?id=' . $payment['id'], 'delete_payment.php', $payment['id']) ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php panel_layout_end();
