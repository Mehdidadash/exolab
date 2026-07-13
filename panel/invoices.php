<?php
// panel/invoices.php
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();
$isAdmin = ($user['role'] === 'admin');
$isDoctor = ($user['role'] === 'doctor');
$doctorId = $isDoctor ? $user['id'] : null;

// Pagination & Search
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$search = trim($_GET['search'] ?? '');

if ($isDoctor) {
    if ($search) {
        $stmt = db()->prepare(
            "SELECT i.*, COALESCE(u.full_name, i.doctor_name) AS doctor_name
             FROM doctor_invoices i
             LEFT JOIN users u ON i.doctor_id = u.id
             WHERE i.doctor_id = ?
             AND (i.invoice_number LIKE ? OR i.doctor_name LIKE ?)
             ORDER BY i.invoice_date DESC, i.id DESC
             LIMIT " . (int) $perPage . " OFFSET " . (int) $offset
        );
        $stmt->execute([$doctorId, "%{$search}%", "%{$search}%"]);
        $invoices = $stmt->fetchAll();

        $countStmt = db()->prepare(
            "SELECT COUNT(*) FROM doctor_invoices i
             WHERE i.doctor_id = ?
             AND (i.invoice_number LIKE ? OR i.doctor_name LIKE ?)"
        );
        $countStmt->execute([$doctorId, "%{$search}%", "%{$search}%"]);
    } else {
        $invoices = getInvoicesForDoctor($doctorId);
        $totalCount = count($invoices);
        $invoices = array_slice($invoices, $offset, $perPage);
        $countStmt = null;
    }
} else {
    if ($search) {
        $stmt = db()->prepare(
            "SELECT i.*, COALESCE(u.full_name, i.doctor_name) AS doctor_name
             FROM doctor_invoices i
             LEFT JOIN users u ON i.doctor_id = u.id
             WHERE i.invoice_number LIKE ? OR i.doctor_name LIKE ? OR COALESCE(u.full_name, '') LIKE ?
             ORDER BY i.invoice_date DESC, i.id DESC
             LIMIT " . (int) $perPage . " OFFSET " . (int) $offset
        );
        $stmt->execute(["%{$search}%", "%{$search}%", "%{$search}%"]);
        $invoices = $stmt->fetchAll();

        $countStmt = db()->prepare(
            "SELECT COUNT(*) FROM doctor_invoices i
             LEFT JOIN users u ON i.doctor_id = u.id
             WHERE i.invoice_number LIKE ? OR i.doctor_name LIKE ? OR COALESCE(u.full_name, '') LIKE ?"
        );
        $countStmt->execute(["%{$search}%", "%{$search}%", "%{$search}%"]);
    } else {
        $stmt = db()->prepare(
            "SELECT i.*, COALESCE(u.full_name, i.doctor_name) AS doctor_name
             FROM doctor_invoices i
             LEFT JOIN users u ON i.doctor_id = u.id
             ORDER BY i.invoice_date DESC, i.id DESC
             LIMIT " . (int) $perPage . " OFFSET " . (int) $offset
        );
        $stmt->execute();
        $invoices = $stmt->fetchAll();

        $countStmt = db()->prepare("SELECT COUNT(*) FROM doctor_invoices");
        $countStmt->execute();
    }
}

$totalCount = $countStmt ? (int) $countStmt->fetchColumn() : count($invoices);
$totalPages = (int) ceil($totalCount / $perPage);

panel_layout_start($isDoctor ? 'فاکتورهای من' : 'لیست فاکتورها');
?>
<?php if ($isAdmin): ?>
<div style="margin-bottom: 18px; display: flex; gap: 10px; flex-wrap: wrap; justify-content: space-between; align-items: center;">
    <div>
        <a class="btn" href="invoice_form.php">ایجاد فاکتور جدید</a>
        <a class="btn" href="generate_invoice.php" style="background: #0F172A; color: #fff;">صدور فاکتور ماهانه</a>
        <a class="btn" href="bank_accounts.php" style="background: #0F172A; color: #fff;">مدیریت حسابهای بانکی</a>
    </div>
</div>
<?php endif; ?>

<!-- Search Form -->
<form method="get" style="margin-bottom: 16px; display: flex; gap: 8px; flex-wrap: wrap;">
    <input type="text" name="search" placeholder="جستجو در شماره فاکتور یا نام دکتر..." value="<?= htmlspecialchars($search) ?>" style="flex: 1; min-width: 200px;">
    <button type="submit" style="background: #0F172A;">جستجو</button>
    <?php if ($search): ?>
        <a href="invoices.php" class="btn" style="background: #E5E7EB; color: #0F172A;">پاک کردن</a>
    <?php endif; ?>
</form>

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
    // Store in sessionStorage so invoice_form.php and generate_invoice.php can read it
    sessionStorage.setItem('default_bank_account_id', id);
}
// Restore on page load
(function() {
    var saved = sessionStorage.getItem('default_bank_account_id');
    if (saved) {
        document.getElementById('default-bank-account').value = saved;
    }
})();
</script>
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
            <td><span class="badge" style="background: <?= $invoice['payment_status'] === 'paid' ? '#dcfce7' : '#fef3c7' ?>; color: <?= $invoice['payment_status'] === 'paid' ? '#166534' : '#92400e' ?>;">
                <?= $invoice['payment_status'] === 'paid' ? 'پرداخت شده' : 'پرداخت نشده' ?>
            </span></td>
            <td class="actions">
                <?php if ($isAdmin):
                    $pdfLink = '<a class="action-icon" href="invoice_pdf.php?id=' . $invoice['id'] . '" target="_blank">' . svg_icon('pdf', 'icon-sm') . '</a>';
                    echo action_dropdown(null, 'invoice_form.php?id=' . $invoice['id'], 'delete_invoice.php', $invoice['id'], $pdfLink);
                else: ?>
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

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div style="display: flex; justify-content: center; gap: 6px; margin-top: 20px; flex-wrap: wrap;">
    <?php if ($page > 1): ?>
        <a class="btn" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>" style="background: #E5E7EB; color: #0F172A;">قبلی</a>
    <?php endif; ?>
    
    <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
        <a class="btn" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>" 
           style="<?= $i === $page ? 'background: #0F172A; color: #fff;' : 'background: #E5E7EB; color: #0F172A;' ?>">
            <?= toPersianDigits($i) ?>
        </a>
    <?php endfor; ?>
    
    <?php if ($page < $totalPages): ?>
        <a class="btn" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>" style="background: #E5E7EB; color: #0F172A;">بعدی</a>
    <?php endif; ?>
</div>
<div style="text-align: center; margin-top: 8px; color: #525252;">
    نمایش <?= toPersianDigits(($offset + 1)) ?> تا <?= toPersianDigits(min($offset + $perPage, $totalCount)) ?> از <?= toPersianDigits($totalCount) ?> فاکتور
</div>
<?php endif; ?>
<?php panel_layout_end(); ?>