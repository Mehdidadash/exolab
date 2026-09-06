<?php
// panel\payments.php
// هر نقش فقط پرداخت‌های مربوط به خودش را می‌بیند:
//   طراح/لابراتوار → پرداخت‌هایی که ما به آن‌ها انجام داده‌ایم (هزینه طراحی/برون‌سپاری)
//   پزشک → پرداخت‌های ثبت‌شده برای خودش
//   کلینیک → پرداخت‌های پزشک‌های زیرمجموعه‌اش
//   مدیر/کارمندان → همه
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();
$isAdmin = is_admin();
$isDoctor = ($user['role'] === 'doctor');
$isDesigner = ($user['role'] === 'designer');
$isLab = in_array($user['role'] ?? '', ['outsource_lab', 'partner_lab', 'customer_lab', 'lab'], true);
$isClinic = ($user['role'] === 'clinic');

if ($isDesigner || $isLab) {
    if (!has_permission('view_own_payments') && !has_permission('view_payments') && !is_admin()) {
        http_response_code(403);
        die('دسترسی غیرمجاز');
    }
    $kind = $isDesigner ? 'designer' : 'outsource';
    $payments = getExpensePaymentsForParty($kind, (int) $user['id']);
    $title = 'پرداخت‌های من';
} elseif ($isDoctor) {
    $payments = getPaymentsForDoctor((int) $user['id']);
    $title = 'پرداخت‌های من';
} elseif ($isClinic) {
    if (!has_permission('view_clinic_payments') && !has_permission('view_payments') && !is_admin()) {
        http_response_code(403);
        die('دسترسی غیرمجاز');
    }
    $clinicScope = getClinicScope('p');
    $stmt = db()->prepare('SELECT p.*, b.bank_name, b.account_owner_name,
            COALESCE(u.full_name, p.doctor_name) AS doctor_name,
            GROUP_CONCAT(DISTINCT i.invoice_number SEPARATOR ", ") AS linked_invoices
        FROM doctor_payments p
        LEFT JOIN bank_accounts b ON p.bank_account_id = b.id
        LEFT JOIN users u ON p.doctor_id = u.id
        LEFT JOIN doctor_payment_invoices pi ON pi.payment_id = p.id
        LEFT JOIN doctor_invoices i ON i.id = pi.invoice_id
        WHERE ' . $clinicScope['sql'] . '
        GROUP BY p.id
        ORDER BY p.payment_date DESC, p.id DESC');
    $stmt->execute($clinicScope['params']);
    $payments = $stmt->fetchAll();
    $title = 'پرداخت‌های کلینیک';
} else {
    if (!is_admin() && !has_permission('view_payments')) {
        http_response_code(403);
        die('دسترسی غیرمجاز');
    }
    $payments = getAllPayments();
    $title = 'لیست پرداخت‌ها';
}

panel_layout_start($title);
?>
<?php if ($isAdmin): ?>
<div style="margin-bottom: 18px; display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn" href="payment_form.php">ثبت پرداخت جدید</a>
    <a class="btn" href="expense_payment_form.php" style="background:#06B6D4; color:#fff;">ثبت پرداخت هزینه</a>
    <a class="btn" href="expense_payments.php" style="background:#E5E7EB; color:#0F172A;">پرداخت‌های هزینه (ما به دیگران)</a>
</div>
<?php endif; ?>

<?php if ($isDesigner || $isLab): ?>
<div class="form-card" style="margin-bottom:16px; padding:12px 16px;">
    <p style="color:#525252; margin:0;">پرداخت‌هایی که مجموعه به شما (<?= $isDesigner ? 'هزینه طراحی' : 'برون‌سپاری' ?>) انجام داده است. این صفحه فقط برای مشاهده است — ویرایش و حذف توسط شما امکان‌پذیر نیست.</p>
</div>
<table class="datatable display">
    <thead>
    <tr>
        <th>نوع</th>
        <th>فاکتور</th>
        <th>مبلغ</th>
        <th>روش پرداخت</th>
        <th>تاریخ</th>
        <th>شماره تراکنش</th>
        <th>حساب گیرنده</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($payments as $payment): ?>
        <tr>
            <td><span class="badge" style="background:<?= $payment['expense_type'] === 'outsource' ? '#fef3c7' : '#ede9fe' ?>; color:<?= $payment['expense_type'] === 'outsource' ? '#92400e' : '#5b21b6' ?>;"><?= $payment['expense_type'] === 'outsource' ? 'برون‌سپاری' : 'طراحی' ?></span></td>
            <td><?= htmlspecialchars($payment['invoice_number'] ?? '—') ?></td>
            <td><?= formatAmountToman($payment['amount']) ?></td>
            <td><?= htmlspecialchars($payment['payment_method'] ?? '—') ?></td>
            <td><?= $payment['payment_date'] ? toJalaliDateFormatted($payment['payment_date']) : '—' ?></td>
            <td><small><?= htmlspecialchars($payment['transaction_number'] ?? '—') ?></small></td>
            <td style="font-size:0.8rem;">
                <?php if (!empty($payment['recipient_bank'])): ?><div><?= htmlspecialchars($payment['recipient_bank']) ?></div><?php endif; ?>
                <?php if (!empty($payment['recipient_card'])): ?><div dir="ltr" style="text-align:right;"><?= htmlspecialchars($payment['recipient_card']) ?></div><?php endif; ?>
                <?php if (empty($payment['recipient_bank']) && empty($payment['recipient_card'])): ?>—<?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<table class="datatable display">
    <thead>
    <tr>
        <th>نام</th>
        <th>مبلغ</th>
        <th>روش پرداخت</th>
        <th>تاریخ</th>
        <th>شماره تراکنش</th>
        <th>حساب بانکی</th>
        <?php if ($isAdmin): ?><th>عملیات</th><?php endif; ?>
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
            <?php if ($isAdmin): ?>
            <td class="actions">
                <?= action_dropdown(null, 'payment_form.php?id=' . $payment['id'], 'delete_payment.php', $payment['id']) ?>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php panel_layout_end();
