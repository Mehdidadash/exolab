<?php
// panel/expense_payments.php
// List payments we made to designers / outsource labs (expenses).
require_once __DIR__ . '/auth.php';
require_admin();

$payments = getAllExpensePayments();

panel_layout_start('پرداخت‌های ما (هزینه)');
?>
<div style="margin-bottom:18px; display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn" href="expense_payment_form.php" style="background:#06B6D4; color:#fff;">ثبت پرداخت جدید (هزینه)</a>
    <a class="btn" href="payments.php" style="background:#E5E7EB; color:#0F172A;">پرداخت‌های دریافتی (دکتر)</a>
    <a class="btn" href="expenses.php" style="background:#E5E7EB; color:#0F172A;">فاکتورهای مخارج</a>
</div>

<div class="form-card">
    <p style="color:#525252;">پرداختی‌هایی که ما به طراحان و لابراتوارها انجام داده‌ایم (هزینه طراحی / برون‌سپاری). مشخصات حساب گیرنده می‌تواند دستی وارد شده باشد.</p>
    <?php if (empty($payments)): ?>
        <p class="empty">هیچ پرداخت هزینه‌ای ثبت نشده است.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="datatable display">
        <thead>
        <tr>
            <th>نوع</th>
            <th>فاکتور</th>
            <th>گیرنده</th>
            <th>مبلغ</th>
            <th>روش</th>
            <th>تاریخ</th>
            <th>حساب گیرنده</th>
            <th>عملیات</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
            <tr>
                <td><span class="badge" style="background:<?= $p['expense_type'] === 'outsource' ? '#fef3c7' : '#ede9fe' ?>; color:<?= $p['expense_type'] === 'outsource' ? '#92400e' : '#5b21b6' ?>;"><?= $p['expense_type'] === 'outsource' ? 'برون‌سپاری' : 'طراحی' ?></span></td>
                <td><?= htmlspecialchars($p['invoice_number'] ?? '—') ?></td>
                <td><?= htmlspecialchars($p['party_name'] ?? '—') ?></td>
                <td><?= formatAmountToman($p['amount']) ?></td>
                <td><?= htmlspecialchars($p['payment_method'] ?? '—') ?></td>
                <td><?= $p['payment_date'] ? toJalaliDateFormatted($p['payment_date']) : '—' ?></td>
                <td style="font-size:0.8rem;">
                    <?php if (!empty($p['recipient_bank'])): ?><div><?= htmlspecialchars($p['recipient_bank']) ?></div><?php endif; ?>
                    <?php if (!empty($p['recipient_card'])): ?><div dir="ltr" style="text-align:right;"><?= htmlspecialchars($p['recipient_card']) ?></div><?php endif; ?>
                    <?php if (empty($p['recipient_bank']) && empty($p['recipient_card'])): ?>—<?php endif; ?>
                </td>
                <td class="actions">
                    <?= action_dropdown(null, 'expense_payment_form.php?id=' . $p['id'], 'delete_expense_payment.php', $p['id']) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php panel_layout_end(); ?>
