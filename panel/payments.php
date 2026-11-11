<?php
// panel/payments.php
// «دریافتی‌ها» — تنها محلِ مشاهدهٔ پول‌هایی که از طرف‌حساب‌ها دریافت کرده‌ایم.
// نوعِ هر رکورد در ستونِ «نوع» مشخص است: پزشک / کلینیک / لابراتوار همکار /
// طراح / شعبه همکار. ثبتِ همهٔ این‌ها از یک فرم (payment_form.php) انجام می‌شود.
//
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
$partyLabels = paymentPartyTypeLabels();
$filterType = '';

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
            GROUP_CONCAT(DISTINCT i.invoice_number SEPARATOR ", ") AS linked_invoices,
            GROUP_CONCAT(DISTINCT br.invoice_number SEPARATOR ", ") AS receivable_numbers
        FROM doctor_payments p
        LEFT JOIN bank_accounts b ON p.bank_account_id = b.id
        LEFT JOIN users u ON p.doctor_id = u.id
        LEFT JOIN doctor_payment_invoices pi ON pi.payment_id = p.id
        LEFT JOIN doctor_invoices i ON i.id = pi.invoice_id
        LEFT JOIN branch_receivables br ON br.id = p.branch_receivable_id
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
    $filterType = (string) ($_GET['type'] ?? '');
    if ($filterType !== '' && !isset($partyLabels[$filterType])) {
        $filterType = '';
    }
    $payments = getAllPayments($filterType !== '' ? $filterType : null);
    $title = 'دریافتی‌ها';
}

panel_layout_start($title);
?>
<?php if ($isAdmin): ?>
<div style="margin-bottom: 18px; display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn" href="payment_form.php">ثبت دریافت جدید</a>
    <a class="btn" href="branch_receivables.php" style="background:#059669; color:#fff;">فاکتورهای طلب از شعبه‌ها</a>
    <a class="btn" href="expense_payment_form.php" style="background:#06B6D4; color:#fff;">ثبت پرداخت هزینه</a>
    <a class="btn" href="expense_payments.php" style="background:#E5E7EB; color:#0F172A;">پرداخت‌های هزینه (ما به دیگران)</a>
</div>
<?php endif; ?>

<?php if (!$isDesigner && !$isLab && !$isDoctor && !$isClinic): ?>
<div style="margin-bottom:14px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
    <span style="color:#64748b; font-size:0.85rem;">نوع دریافت:</span>
    <?php
    $chips = ['' => 'همه'] + $partyLabels;
    foreach ($chips as $chipKey => $chipLabel):
        $isActive = ($filterType === (string) $chipKey);
        $chipHref = 'payments.php' . ($chipKey === '' ? '' : '?type=' . urlencode((string) $chipKey));
    ?>
        <a class="btn" href="<?= $chipHref ?>"
           style="padding:5px 12px; font-size:0.85rem; text-decoration:none; <?= $isActive ? 'background:#0F172A; color:#fff;' : 'background:#E5E7EB; color:#0F172A;' ?>">
            <?= htmlspecialchars($chipLabel) ?>
        </a>
    <?php endforeach; ?>
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
<?php
$listTotal = 0.0;
foreach ($payments as $p) {
    $listTotal += (float) $p['amount'];
}
?>
<div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
    <div style="background:#f0f9ff; border:1px solid #bae6fd; border-radius:10px; padding:8px 14px; font-size:0.85rem; color:#075985;">
        تعداد: <strong><?= number_format(count($payments)) ?></strong>
    </div>
    <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:8px 14px; font-size:0.85rem; color:#166534;">
        جمع دریافتی: <strong><?= formatAmountToman($listTotal) ?></strong> تومان
    </div>
</div>
<table class="datatable display">
    <thead>
    <tr>
        <th>نوع</th>
        <th>نام</th>
        <th>مبلغ</th>
        <th>روش پرداخت</th>
        <th>تاریخ</th>
        <th>فاکتورها</th>
        <th>شماره تراکنش</th>
        <th>حساب بانکی</th>
        <?php if ($isAdmin): ?><th>عملیات</th><?php endif; ?>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($payments as $payment): ?>
        <?php
        $pType = (string) ($payment['party_type'] ?: 'doctor');
        $pTypeColors = [
            'doctor'   => ['#e0f2fe', '#075985'],
            'clinic'   => ['#ede9fe', '#5b21b6'],
            'lab'      => ['#fef3c7', '#92400e'],
            'designer' => ['#fce7f3', '#9d174d'],
            'branch'   => ['#dcfce7', '#166534'],
        ];
        [$bg, $fg] = $pTypeColors[$pType] ?? ['#E5E7EB', '#0F172A'];
        $linkedNumbers = trim((string) ($payment['receivable_numbers'] ?? ''));
        if ($linkedNumbers === '' && !empty($payment['invoice_numbers'])) {
            $linkedNumbers = (string) $payment['invoice_numbers'];
        }
        if ($linkedNumbers === '' && !empty($payment['linked_invoices'])) {
            $linkedNumbers = (string) $payment['linked_invoices'];
        }
        ?>
        <tr>
            <td><span class="badge" style="background:<?= $bg ?>; color:<?= $fg ?>;"><?= htmlspecialchars(paymentPartyTypeLabel($pType)) ?></span></td>
            <td><?= htmlspecialchars($payment['doctor_name']) ?></td>
            <td><?= formatAmountToman($payment['amount']) ?></td>
            <td><?= htmlspecialchars($payment['payment_method']) ?></td>
            <td><?= toJalaliDateFormatted($payment['payment_date']) ?></td>
            <td><small><?= $linkedNumbers !== '' ? htmlspecialchars($linkedNumbers) : '—' ?></small></td>
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
