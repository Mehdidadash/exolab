<?php
// panel/invoices.php
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();
$isAdmin = is_admin();
$isDoctor = ($user['role'] === 'doctor');
$isDesigner = ($user['role'] === 'designer');
$isLab = in_array($user['role'] ?? '', ['outsource_lab', 'partner_lab', 'customer_lab', 'lab'], true);
$isOwnView = $isDoctor || $isDesigner || $isLab;
$doctorId = $isDoctor ? $user['id'] : null;

// External parties (طراح / لابراتوار) may ONLY see their own invoices — no editing.
if ($isDesigner || $isLab) {
    if (!has_permission('view_own_invoices') && !has_permission('view_invoices') && !is_admin()) {
        http_response_code(403);
        die('دسترسی غیرمجاز');
    }
    $invoices = $isDesigner
        ? getInvoicesForDesigner((int) $user['id'])
        : getInvoicesForLab((int) $user['id']);
} else {
    // Branch scoping for staff/branch admins
    $invBranchFilter = '';
    $invBranchParams = [];
    if (is_branch_scoped() && !$isDoctor) {
        $bid = currentBranchId();
        $granted = accessibleDoctorIds();
        $invBranchFilter = ' AND (i.branch_id = ' . (int) $bid;
        if (!empty($granted)) {
            $ph = implode(',', array_fill(0, count($granted), '?'));
            $invBranchFilter .= " OR i.doctor_id IN ({$ph})";
            $invBranchParams = array_merge($invBranchParams, $granted);
        }
        $invBranchFilter .= ')';
    }

    if ($isDoctor) {
        $invoices = getInvoicesForDoctor($doctorId);
    } elseif (has_permission('view_clinic_invoices') && $user['role'] === 'clinic') {
        $clinicScope = getClinicScope('i');
        $stmt = db()->prepare(
            "SELECT i.*, COALESCE(u.full_name, i.doctor_name) AS doctor_name
             FROM doctor_invoices i
             LEFT JOIN users u ON i.doctor_id = u.id
             WHERE {$clinicScope['sql']}
             ORDER BY i.invoice_date DESC, i.id DESC"
        );
        $stmt->execute($clinicScope['params']);
        $invoices = $stmt->fetchAll();
    } else {
        // Any other role with access: staff/secretary/finance/technician/admin — full list
        if (!is_admin() && !has_permission('view_invoices') && !has_permission('view_clinic_invoices') && !has_permission('view_own_invoices')) {
            http_response_code(403);
            die('دسترسی غیرمجاز');
        }
        $stmt = db()->prepare(
            "SELECT i.*, COALESCE(u.full_name, i.doctor_name) AS doctor_name
             FROM doctor_invoices i
             LEFT JOIN users u ON i.doctor_id = u.id
             WHERE 1=1{$invBranchFilter}
             ORDER BY i.invoice_date DESC, i.id DESC"
        );
        $stmt->execute($invBranchParams);
        $invoices = $stmt->fetchAll();
    }
}

$pdfFile = $isDesigner ? 'designer_invoice_pdf.php' : ($isLab ? 'outsource_invoice_pdf.php' : 'invoice_pdf.php');

panel_layout_start($isOwnView ? 'فاکتورهای من' : 'لیست فاکتورها');
?>
<?php if ($isAdmin): ?>
<div style="margin-bottom: 18px; display: flex; gap: 10px; flex-wrap: wrap; justify-content: space-between; align-items: center;">
    <div>
        <a class="btn" href="invoice_form.php">ایجاد فاکتور جدید</a>
        <a class="btn" href="generate_invoice.php" style="background: #0F172A; color: #fff;">صدور فاکتور ماهانه</a>
        <a class="btn" href="generate_clinic_invoice.php" style="background: #059669; color: #fff;">صدور فاکتور کلینیک</a>
        <a class="btn" href="generate_lab_invoice.php" style="background: #7c3aed; color: #fff;">صدور فاکتور لابراتوار</a>
        <a class="btn" href="designer_invoices.php" style="background: #d97706; color: #fff;">صدور فاکتور طراحی</a>
        <a class="btn" href="expenses.php" style="background: #b91c1c; color: #fff;">فاکتورهای مخارج (بدهی‌ها)</a>
        <a class="btn" href="bank_accounts.php" style="background: #0F172A; color: #fff;">مدیریت حسابهای بانکی</a>
    </div>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<div class="form-card" style="margin-bottom: 16px; padding: 14px 18px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
    <strong>حساب بانکی پیش‌فرض برای فاکتورهای جدید:</strong>
    <select id="default-bank-account" style="width: auto; flex: 1; min-width: 200px;" onchange="setDefaultBankAccount(this.value)">
        <option value="">بدون حساب</option>
        <?php foreach (getAllBankAccounts() as $acc): ?>
            <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['bank_name'] . ' - ' . $acc['account_owner_name']) ?></option>
        <?php endforeach; ?>
    </select>
    <small style="color: #525252;">حساب انتخاب‌شده در فرم ایجاد فاکتور و فاکتور ماهانه به‌عنوان پیش‌فرض تنظیم می‌شود.</small>
</div>
<script>
function setDefaultBankAccount(id) {
    sessionStorage.setItem('default_bank_account_id', id);
}
(function() {
    var saved = sessionStorage.getItem('default_bank_account_id');
    if (saved) {
        document.getElementById('default-bank-account').value = saved;
    }
})();
</script>
<?php endif; ?>

<table class="datatable display" data-order="<?= $isAdmin ? 2 : 1 ?>">
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
            <td data-sort="<?= htmlspecialchars($invoice['invoice_date']) ?>"><?= toJalaliDateFormatted($invoice['invoice_date']) ?></td>
            <td data-sort="<?= (float)$invoice['total_amount'] ?>"><?= formatAmountToman($invoice['total_amount']) ?></td>
            <td><span class="badge" style="background: <?= $invoice['payment_status'] === 'paid' ? '#dcfce7' : '#fef3c7' ?>; color: <?= $invoice['payment_status'] === 'paid' ? '#166534' : '#92400e' ?>;">
                <?= $invoice['payment_status'] === 'paid' ? 'پرداخت شده' : 'پرداخت نشده' ?>
            </span></td>
            <td class="actions">
                <?php if ($isAdmin):
                    $pdfLink = '<a class="action-icon" href="invoice_pdf.php?id=' . $invoice['id'] . '" target="_blank">' . svg_icon('pdf', 'icon-sm') . '</a>';
                    echo action_dropdown(null, 'invoice_form.php?id=' . $invoice['id'], 'delete_invoice.php', $invoice['id'], $pdfLink);
                else: ?>
                    <a class="btn" href="<?= $pdfFile ?>?id=<?= $invoice['id'] ?>" target="_blank">PDF</a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php panel_layout_end(); ?>