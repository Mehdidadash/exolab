<?php
// panel/doctor_view.php
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();
$doctorId = !empty($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$doctorId) {
    header('Location: doctors.php');
    exit;
}

$isDesigner = ($user['role'] === 'designer');
$canAccess = has_role('admin')
    || (is_designer_user($user) && designerCanAccessUser($doctorId))
    || (has_role('doctor') && (int) $doctorId === (int) $user['id'])
    || (has_role('clinic') && canAccessDoctor($doctorId));
if (!$canAccess) {
    http_response_code(403);
    die('دسترسی غیرمجاز');
}

$doctor = getDoctor($doctorId);
if (!$doctor) {
    header('Location: doctors.php?error=notfound');
    exit;
}

$showSensitiveContact = has_role('admin') || in_array($user['role'] ?? '', ['clinic', 'staff', 'secretary', 'technician'], true) || (has_role('doctor') && (int) $doctorId === (int) $user['id']);

// ─── آمار ───
$totalCases = db()->prepare("SELECT COUNT(*) FROM cases WHERE doctor_id = ?");
$totalCases->execute([$doctorId]);
$totalCases = (int) $totalCases->fetchColumn();

$activeCases = db()->prepare("SELECT COUNT(*) FROM cases c JOIN case_statuses s ON c.status_id = s.id WHERE c.doctor_id = ? AND s.name NOT IN ('Delivered','Cancelled')");
$activeCases->execute([$doctorId]);
$activeCases = (int) $activeCases->fetchColumn();

$totalInvoices = db()->prepare("SELECT COUNT(*) FROM doctor_invoices WHERE doctor_id = ?");
$totalInvoices->execute([$doctorId]);
$totalInvoices = (int) $totalInvoices->fetchColumn();

$unpaidInvoices = db()->prepare("SELECT COUNT(*) FROM doctor_invoices WHERE doctor_id = ? AND payment_status = 'unpaid'");
$unpaidInvoices->execute([$doctorId]);
$unpaidInvoices = (int) $unpaidInvoices->fetchColumn();

$totalRevenue = db()->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM doctor_invoices WHERE doctor_id = ? AND payment_status = 'paid'");
$totalRevenue->execute([$doctorId]);
$totalRevenue = (float) $totalRevenue->fetchColumn();

$totalDebt = db()->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM doctor_invoices WHERE doctor_id = ? AND payment_status = 'unpaid'");
$totalDebt->execute([$doctorId]);
$totalDebt = (float) $totalDebt->fetchColumn();

$totalPayments = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM doctor_payments WHERE doctor_id = ?");
$totalPayments->execute([$doctorId]);
$totalPayments = (float) $totalPayments->fetchColumn();

// ─── آخرین کیس‌ها ───
$stmt = db()->prepare(
    "SELECT c.*, p.title AS service_title, cs.name AS status_name
     FROM cases c
     LEFT JOIN site_prices p ON c.service_id = p.id
     LEFT JOIN case_statuses cs ON c.status_id = cs.id
     WHERE c.doctor_id = ?
     ORDER BY c.created_at DESC LIMIT 10"
);
$stmt->execute([$doctorId]);
$recentCases = $stmt->fetchAll();

// ─── آخرین فاکتورها ───
$stmt = db()->prepare(
    "SELECT * FROM doctor_invoices WHERE doctor_id = ? ORDER BY invoice_date DESC LIMIT 10"
);
$stmt->execute([$doctorId]);
$recentInvoices = $stmt->fetchAll();

// ─── آخرین پرداخت‌ها ───
$stmt = db()->prepare(
    "SELECT p.*, b.bank_name, b.account_owner_name
     FROM doctor_payments p
     LEFT JOIN bank_accounts b ON p.bank_account_id = b.id
     WHERE p.doctor_id = ?
     ORDER BY p.payment_date DESC LIMIT 10"
);
$stmt->execute([$doctorId]);
$recentPayments = $stmt->fetchAll();

panel_layout_start('نمایه پزشک: ' . $doctor['name']);
?>
<?php $showFinancial = !$isDesigner; ?>
<div style="margin-bottom:18px;">
    <a class="btn" href="doctors.php">بازگشت به لیست پزشکان</a>
    <?php if (!$isDesigner): ?>
    <a class="btn" href="doctor_form.php?id=<?= $doctorId ?>" style="background:#0F172A; color:#fff;">ویرایش پزشک</a>
    <?php endif; ?>
</div>

<!-- Info Card -->
<div class="form-card" style="margin-bottom:24px;">
    <div style="display:flex; gap:20px; flex-wrap:wrap; justify-content:space-between;">
        <div>
            <h3 style="margin:0 0 8px;"><?= htmlspecialchars($doctor['name']) ?></h3>
            <?php if ($showSensitiveContact): ?>
                <p style="margin:4px 0;"><strong>تلفن:</strong> <?= htmlspecialchars($doctor['phone'] ?? '—') ?></p>
                <p style="margin:4px 0;"><strong>ایمیل:</strong> <?= htmlspecialchars($doctor['email'] ?? '—') ?></p>
            <?php else: ?>
                <p style="margin:4px 0;"><strong>تلفن:</strong> —</p>
                <p style="margin:4px 0;"><strong>ایمیل:</strong> —</p>
            <?php endif; ?>
            <p style="margin:4px 0;"><strong>وضعیت:</strong> <?= $doctor['active'] ? 'فعال' : 'غیرفعال' ?></p>
            <?php if (!empty($doctor['clinic_id'])): ?>
                <?php $clinicOfDoc = db()->prepare('SELECT id, full_name FROM users WHERE id = ? AND role = "clinic"'); $clinicOfDoc->execute([(int) $doctor['clinic_id']]); $clinicRow = $clinicOfDoc->fetch(); ?>
                <p style="margin:4px 0;"><strong>عضویت در کلینیک:</strong>
                    <?= $clinicRow ? htmlspecialchars($clinicRow['full_name']) : htmlspecialchars((string) $doctor['clinic_id']) ?>
                </p>
            <?php else: ?>
                <p style="margin:4px 0;"><strong>عضویت در کلینیک:</strong> —</p>
            <?php endif; ?>
            <?php if (!empty($doctor['lab_id'])): ?>
                <?php $labOfDoc = db()->prepare("SELECT id, full_name FROM users WHERE id = ? AND role IN ('outsource_lab','customer_lab','partner_lab','lab')"); $labOfDoc->execute([(int) $doctor['lab_id']]); $labRow = $labOfDoc->fetch(); ?>
                <p style="margin:4px 0;"><strong>زیرمجموعه لابراتوار:</strong>
                    <?= $labRow ? htmlspecialchars($labRow['full_name']) : htmlspecialchars((string) $doctor['lab_id']) ?>
                </p>
            <?php else: ?>
                <p style="margin:4px 0;"><strong>زیرمجموعه لابراتوار:</strong> —</p>
            <?php endif; ?>
            <?php if ($doctor['notes']): ?>
                <p style="margin:4px 0;"><strong>یادداشت:</strong></p>
                <div style="white-space:pre-wrap; direction:rtl; text-align:right; unicode-bidi:plaintext; line-height:1.9;"><?= nl2br(htmlspecialchars($doctor['notes'])) ?></div>
            <?php endif; ?>
        </div>
        <div style="text-align:left;">
            <p style="margin:4px 0; font-size:0.9rem;">آخرین ورود: <?= !empty($doctor['last_login']) ? toJalaliDateFormatted($doctor['last_login']) : '—' ?></p>
        </div>
    </div>
</div>

<?php
// Doctor profile gallery – visible only to designers, admins and internal staff
$canViewGallery = in_array($user['role'] ?? '', ['admin', 'designer', 'staff', 'secretary'], true);
$canManageGallery = in_array($user['role'] ?? '', ['admin', 'staff', 'secretary'], true);
if ($canViewGallery):
    $gallery = getDoctorGallery($doctorId);
?>
<div class="form-card" style="margin-bottom:24px;">
    <h3>گالری سلیقه / نحوه کار پزشک</h3>
    <?php if (empty($gallery)): ?>
        <p class="empty">هنوز عکسی ثبت نشده است.</p>
    <?php else: ?>
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:16px; margin-top:12px;">
            <?php foreach ($gallery as $g): ?>
                <div style="border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; background:#fff;">
                    <a href="serve_doctor_image.php?did=<?= (int) $doctorId ?>&f=<?= rawurlencode($g['image_path']) ?>" target="_blank">
                        <img src="serve_doctor_image.php?did=<?= (int) $doctorId ?>&f=<?= rawurlencode($g['image_path']) ?>" alt="gallery" style="width:100%; height:160px; object-fit:cover; display:block;">
                    </a>
                    <?php if (!empty($g['caption'])): ?>
                        <div style="padding:10px 12px; font-size:0.9rem; line-height:1.9; white-space:pre-wrap; direction:rtl; text-align:right; unicode-bidi:plaintext;"><?= nl2br(htmlspecialchars($g['caption'])) ?></div>
                    <?php endif; ?>
                    <?php if ($canManageGallery): ?>
                        <div style="padding:8px 12px; border-top:1px solid #eee; display:flex; gap:8px; align-items:center;">
                            <button type="button" class="btn" style="padding:4px 10px; font-size:0.8rem; background:#E5E7EB; color:#0F172A;" onclick='editGalleryItem(<?= (int) $g['id'] ?>, <?= htmlspecialchars(json_encode((string)($g['caption'] ?? '')), ENT_QUOTES) ?>)'>ویرایش</button>
                            <form method="post" action="delete_doctor_gallery.php" style="margin:0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                                <button class="btn" style="background:#fee2e2; color:#991b1b; padding:4px 10px; font-size:0.8rem;">حذف</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($canManageGallery): ?>
    <div style="margin-top:18px; padding:14px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:10px;">
        <strong>افزودن / ویرایش عکس و توضیحات</strong>
        <form method="post" action="save_doctor_gallery.php" enctype="multipart/form-data" style="margin-top:10px;">
            <?= csrf_field() ?>
            <input type="hidden" name="doctor_id" value="<?= (int) $doctorId ?>">
            <input type="hidden" name="id" id="gallery-id" value="">
            <div class="form-group">
                <label for="gallery-image">عکس</label>
                <input type="file" id="gallery-image" name="image" accept=".jpg,.jpeg,.png,.gif,.webp,.bmp">
                <small style="color:#525252;">برای افزودن عکس جدید، فایل را انتخاب کنید؛ برای ویرایش فقط توضیحات، بدون انتخاب فایل ذخیره کنید.</small>
            </div>
            <div class="form-group">
                <label for="gallery-caption">توضیحات</label>
                <textarea id="gallery-caption" name="caption" rows="3" placeholder="توضیح سلیقه / نحوه کار این پزشک..."></textarea>
            </div>
            <button type="submit" class="btn" style="background:#06B6D4; color:#fff;">ذخیره</button>
            <button type="button" class="btn" style="background:#E5E7EB; color:#0F172A;" onclick="resetGalleryForm()">انصراف از ویرایش</button>
        </form>
    </div>
    <script>
    function editGalleryItem(id, caption){
        document.getElementById('gallery-id').value = id;
        document.getElementById('gallery-caption').value = caption || '';
        document.getElementById('gallery-image').value = '';
        var form = document.getElementById('gallery-id').closest('.form-card');
        if (form) form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    function resetGalleryForm(){
        document.getElementById('gallery-id').value = '';
        document.getElementById('gallery-caption').value = '';
        document.getElementById('gallery-image').value = '';
    }
    </script>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($showFinancial): ?>
<!-- Stats Grid -->
<div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); margin-bottom:24px;">
    <div class="card" style="text-align:center; border-right:4px solid #06B6D4;">
        <h4 style="margin:0; color:#525252; font-size:0.85rem;">کل کیس‌ها</h4>
        <p style="font-size:1.8rem; margin:8px 0 0; font-weight:700;"><?= toPersianDigits($totalCases) ?></p>
    </div>
    <div class="card" style="text-align:center; border-right:4px solid #f59e0b;">
        <h4 style="margin:0; color:#525252; font-size:0.85rem;">کیس‌های فعال</h4>
        <p style="font-size:1.8rem; margin:8px 0 0; font-weight:700;"><?= toPersianDigits($activeCases) ?></p>
    </div>
    <div class="card" style="text-align:center; border-right:4px solid #10b981;">
        <h4 style="margin:0; color:#525252; font-size:0.85rem;">فاکتورها</h4>
        <p style="font-size:1.8rem; margin:8px 0 0; font-weight:700;"><?= toPersianDigits($totalInvoices) ?></p>
        <small style="color:#ef4444;"><?= toPersianDigits($unpaidInvoices) ?> پرداخت نشده</small>
    </div>
    <div class="card" style="text-align:center; border-right:4px solid #8b5cf6;">
        <h4 style="margin:0; color:#525252; font-size:0.85rem;">دریافتی</h4>
        <p style="font-size:1.1rem; margin:8px 0 0; font-weight:700;"><?= formatAmountToman($totalPayments) ?></p>
    </div>
    <div class="card" style="text-align:center; border-right:4px solid #ef4444;">
        <h4 style="margin:0; color:#525252; font-size:0.85rem;">بدهی</h4>
        <p style="font-size:1.1rem; margin:8px 0 0; font-weight:700; color:#ef4444;"><?= formatAmountToman($totalDebt) ?></p>
    </div>
</div>
<?php endif; ?>

<?php if ($showFinancial): ?>
<div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
    <!-- Recent Cases -->
    <div class="form-card">
        <h3>آخرین کیس‌ها</h3>
        <table style="width:100%; font-size:0.9rem;">
            <thead><tr><th>#</th><th>بیمار</th><th>خدمت</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
            <tbody>
            <?php foreach ($recentCases as $c): ?>
                <tr>
                    <td><a href="view_case.php?id=<?= $c['id'] ?>"><?= $c['id'] ?></a></td>
                    <td><?= htmlspecialchars($c['patient_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($c['service_title'] ?? '') ?></td>
                    <td><span class="badge"><?= htmlspecialchars($c['status_name'] ?? '') ?></span></td>
                    <td><?= toJalaliDateFormatted($c['received_date']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recentCases)): ?><tr><td colspan="5" class="empty">هیچ کیسی ثبت نشده</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Recent Invoices -->
    <div class="form-card">
        <h3>آخرین فاکتورها</h3>
        <table style="width:100%; font-size:0.9rem;">
            <thead><tr><th>شماره</th><th>تاریخ</th><th>مبلغ</th><th>وضعیت</th></tr></thead>
            <tbody>
            <?php foreach ($recentInvoices as $inv): ?>
                <tr>
                    <td><a href="invoice_form.php?id=<?= $inv['id'] ?>"><?= htmlspecialchars($inv['invoice_number']) ?></a></td>
                    <td><?= toJalaliDateFormatted($inv['invoice_date']) ?></td>
                    <td><?= formatAmountToman($inv['total_amount']) ?></td>
                    <td><span class="badge" style="background:<?= $inv['payment_status'] === 'paid' ? '#dcfce7' : '#fef3c7' ?>; color:<?= $inv['payment_status'] === 'paid' ? '#166534' : '#92400e' ?>;">
                        <?= $inv['payment_status'] === 'paid' ? 'پرداخت شده' : 'پرداخت نشده' ?>
                    </span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recentInvoices)): ?><tr><td colspan="4" class="empty">فاکتوری صادر نشده</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Recent Payments -->
    <div class="form-card" style="grid-column:1/-1;">
        <h3>آخرین پرداخت‌ها</h3>
        <table style="width:100%; font-size:0.9rem;">
            <thead><tr><th>مبلغ</th><th>روش</th><th>تاریخ</th><th>شماره تراکنش</th><th>حساب بانکی</th></tr></thead>
            <tbody>
            <?php foreach ($recentPayments as $pay): ?>
                <tr>
                    <td><?= formatAmountToman($pay['amount']) ?></td>
                    <td><?= htmlspecialchars($pay['payment_method']) ?></td>
                    <td><?= toJalaliDateFormatted($pay['payment_date']) ?></td>
                    <td><?= htmlspecialchars($pay['transaction_number'] ?? '—') ?></td>
                    <td><?= htmlspecialchars(($pay['bank_name'] ?? '') . ' - ' . ($pay['account_owner_name'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recentPayments)): ?><tr><td colspan="5" class="empty">پرداختی ثبت نشده</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="form-card" style="margin-top:24px;">
    <h3>پیام‌ها و کامنت‌ها</h3>
    <form method="post" action="save_comment.php" style="margin-bottom:16px;">
        <?= csrf_field() ?>
        <input type="hidden" name="entity_type" value="doctor">
        <input type="hidden" name="entity_id" value="<?= (int) $doctorId ?>">
        <textarea name="message" rows="3" placeholder="پیام یا یادداشت برای این پروفایل..." required style="width:100%;"></textarea>
        <button type="submit" class="btn" style="margin-top:8px; background:#06B6D4; color:#fff;">ارسال پیام</button>
    </form>

    <?php $doctorComments = getEntityComments('doctor', $doctorId); ?>
    <?php if (!empty($doctorComments)): ?>
        <div style="display:flex; flex-direction:column; gap:10px;">
            <?php foreach ($doctorComments as $comment): ?>
                <div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:10px 12px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:6px; flex-wrap:wrap;">
                        <strong><?= htmlspecialchars($comment['user_name'] ?? 'کاربر') ?></strong>
                        <span style="font-size:0.8rem; color:#6b7280;"><?= toJalaliDateTimeFormatted($comment['created_at']) ?></span>
                    </div>
                    <div style="white-space:pre-wrap; line-height:1.8;"><?= htmlspecialchars($comment['message']) ?></div>
                    <?php if ((int) $comment['user_id'] === (int) $user['id'] || has_role('admin')): ?>
                        <form method="post" action="save_comment.php" style="margin-top:8px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="comment_id" value="<?= (int) $comment['id'] ?>">
                            <button type="submit" class="btn" style="background:#fee2e2; color:#991b1b; padding:4px 10px; font-size:0.8rem;">حذف</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="empty">هنوز پیامی ثبت نشده است.</p>
    <?php endif; ?>
</div>

<?php panel_layout_end(); ?>
