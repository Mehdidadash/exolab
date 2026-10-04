<?php
// panel/scan_body_types.php
// «انواع اسکن‌بادی» (فیکسچر ایمپلنت) + کتابخانهٔ دانلود (لینک یا فایل).
//
// دسترسی:
//   • مدیر سیستم / مدیر شعبه → دسترسی کامل (افزودن، ویرایش، حذف، بارگذاری فایل).
//   • طراح (و کاربرانی با پرچمِ «طراح») → فقط مشاهدهٔ لیست و دانلود کتابخانه.
require_once __DIR__ . '/auth.php';
require_login();

if (!canViewScanBodyLibrary()) {
    http_response_code(403);
    die('دسترسی غیرمجاز');
}

$canManage = canManageScanBodyTypes();
$types = getScanBodyTypes(true);

// تعداد استفادهٔ هر نوع در نوبت‌های اسکن و کیس‌ها
$usage = [];
foreach ($types as $t) {
    $usage[(int) $t['id']] = scanBodyTypeUsage((int) $t['id']);
}

$msg = trim((string) ($_GET['msg'] ?? ''));
$err = trim((string) ($_GET['error'] ?? ''));

// ویرایشِ درجا: اگر ?edit=<id> باشد، فرم بالای صفحه با مقادیر آن پر می‌شود.
$editId = (int) ($_GET['edit'] ?? 0);
$editing = ($canManage && $editId > 0) ? getScanBodyType($editId) : null;

$errMessages = [
    'name'            => 'نام نوع اسکن‌بادی الزامی است.',
    'duplicate'       => 'نوعی با این نام قبلاً ثبت شده است.',
    'in_use'          => 'این نوع اسکن‌بادی در نوبت‌ها یا کیس‌ها استفاده می‌شود و قابل حذف نیست. می‌توانید آن را «غیرفعال» کنید.',
    'bad_url'         => 'لینک کتابخانه باید با http:// یا https:// شروع شود.',
    'notfound'        => 'نوع اسکن‌بادی یافت نشد.',
    'nofile'          => 'فایلی انتخاب نشده بود.',
    'upload'          => 'بارگذاری فایل ناموفق بود.',
    'toobig'          => 'حجم فایل بیش از ۲۰۰ مگابایت است.',
    'badext'          => 'پسوند این فایل مجاز نیست.',
    'library_deleted' => 'فایل کتابخانه حذف شد.',
];

panel_layout_start('انواع اسکن‌بادی');
?>
<style>
    .sbt-lib { display:inline-flex; align-items:center; gap:6px; background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; border-radius:6px; padding:3px 8px; font-size:.8rem; font-weight:600; text-decoration:none; white-space:nowrap; }
    .sbt-lib:hover { background:#d1fae5; }
    .sbt-nolib { color:#9ca3af; font-size:.82rem; }
    .sbt-desc { color:#64748b; font-size:.8rem; margin-top:3px; line-height:1.7; }
    .sbt-lib-box { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; }
</style>

<?php if ($canManage): ?>
<div class="form-card" style="max-width:1000px; margin:0 auto 20px;">
    <h3 style="margin-top:0;"><?= $editing ? 'ویرایش نوع اسکن‌بادی' : 'افزودن نوع اسکن‌بادی جدید' ?></h3>
    <p style="color:#555; margin:6px 0 12px; line-height:1.9;">
        انواع «اسکن‌بادی» (فیکسچر ایمپلنت) را این‌جا تعریف کنید. وقتی در فرمِ نوبتِ اسکن یا در کیسِ خدماتی که
        «نیازمند اسکن‌بادی» است، این فهرست به کاربر نشان داده می‌شود تا نوع اسکن‌بادی انتخاب شود.
        می‌توانید برای هر نوع، <b>کتابخانهٔ دانلود</b> (فایل یا لینک) بگذارید تا طراح‌ها آن را دانلود کنند.
    </p>
    <form method="post" action="save_scan_body_type.php">
        <?= csrf_field() ?>
        <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
        <?php endif; ?>
        <div style="display:grid; grid-template-columns:1fr 100px 110px; gap:8px; align-items:end;">
            <div class="form-group" style="margin:0;">
                <label>نام نوع اسکن‌بادی *</label>
                <input type="text" name="name" required placeholder="مثلاً: اویتا"
                       value="<?= htmlspecialchars($editing['name'] ?? '') ?>">
            </div>
            <div class="form-group" style="margin:0;">
                <label>ترتیب</label>
                <input type="number" name="sort_order" step="1"
                       value="<?= (int) ($editing['sort_order'] ?? (count($types) + 1)) ?>">
            </div>
            <div class="form-group" style="margin:0;">
                <label>وضعیت</label>
                <select name="active">
                    <option value="1" <?= (!isset($editing['active']) || (int) $editing['active'] === 1) ? 'selected' : '' ?>>فعال</option>
                    <option value="0" <?= (isset($editing['active']) && (int) $editing['active'] === 0) ? 'selected' : '' ?>>غیرفعال</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label for="library_url">لینک دانلود کتابخانه (اختیاری)</label>
            <input type="url" id="library_url" name="library_url" placeholder="https://example.com/scan-body-library.zip"
                   value="<?= htmlspecialchars((string) ($editing['library_url'] ?? '')) ?>">
            <small style="display:block; color:#525252; margin-top:4px;">لینک خارجی (مثلاً درایو/سایتِ سازنده). هنگام «دانلود» کاربر به این آدرس هدایت می‌شود.</small>
        </div>
        <div class="form-group">
            <label for="description">توضیحات (اختیاری)</label>
            <textarea id="description" name="description" rows="2" placeholder="مثلاً: مناسب سیستم‌های اباتمنت کره‌ای"><?= htmlspecialchars((string) ($editing['description'] ?? '')) ?></textarea>
        </div>
        <div style="display:flex; gap:6px;">
            <button class="btn" style="background:#0F172A; color:#fff;"><?= $editing ? 'بروزرسانی' : 'افزودن' ?></button>
            <?php if ($editing): ?>
                <a class="btn" href="scan_body_types.php" style="background:#E5E7EB; color:#0F172A;">انصراف</a>
            <?php endif; ?>
        </div>
    </form>
    <small style="display:block; color:#525252; margin-top:8px;">
        «ترتیب» (عدد کوچک‌تر = بالاتر) در همهٔ فهرست‌های انتخابی رعایت می‌شود. موارد «غیرفعال» در فرم‌ها
        نمایش داده نمی‌شوند ولی در نوبت‌ها و کیس‌های قبلی باقی می‌مانند.
    </small>
</div>
<?php else: ?>
<div class="form-card" style="max-width:1000px; margin:0 auto 20px; background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; line-height:1.9;">
    شما در حالت <b>مشاهده</b> هستید: فهرست انواع اسکن‌بادی و کتابخانه‌های دانلود را می‌بینید.
    افزودن/ویرایش/حذف فقط توسط مدیر سیستم و مدیران شعبه انجام می‌شود.
</div>
<?php endif; ?>

<?php if ($msg === 'saved'): ?><p style="color:#166534; font-weight:bold;">ذخیره شد.</p><?php endif; ?>
<?php if ($msg === 'deleted'): ?><p style="color:#166534; font-weight:bold;">حذف شد.</p><?php endif; ?>
<?php if ($msg === 'library_saved'): ?><p style="color:#166534; font-weight:bold;">فایل کتابخانه بارگذاری شد.</p><?php endif; ?>
<?php if ($err !== '' && isset($errMessages[$err])): ?>
    <p style="color:#b91c1c; font-weight:bold;"><?= htmlspecialchars($errMessages[$err]) ?></p>
<?php endif; ?>

<div class="form-card" style="max-width:1100px; margin:0 auto;">
    <table class="datatable display" style="width:100%">
        <thead>
        <tr>
            <th>ترتیب</th>
            <th>نام</th>
            <th>کتابخانه / دانلود</th>
            <th>وضعیت</th>
            <th title="تعداد نوبت‌ها و کیس‌هایی که از این نوع استفاده می‌کنند">استفاده</th>
            <?php if ($canManage): ?><th>عملیات</th><?php endif; ?>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($types)): ?>
            <tr><td colspan="<?= $canManage ? 6 : 5 ?>" style="text-align:center; color:#6b7280; padding:16px;">هنوز نوعی ثبت نشده است.</td></tr>
        <?php else: foreach ($types as $t): $used = $usage[(int) $t['id']] ?? 0; ?>
            <tr>
                <td><?= toPersianDigits((int) $t['sort_order']) ?></td>
                <td>
                    <span style="display:inline-flex; align-items:center; gap:5px; background:#eef2ff; color:#3730a3; border-radius:6px; padding:2px 8px; font-weight:600;">
                        🧩 <span><?= htmlspecialchars((string) $t['name']) ?></span>
                    </span>
                    <?php if (trim((string) ($t['description'] ?? '')) !== ''): ?>
                        <div class="sbt-desc"><?= nl2br(htmlspecialchars((string) $t['description'])) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="sbt-lib-box" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                        <?php if (scanBodyTypeHasLibrary($t)): ?>
                            <a class="sbt-lib" href="download_scan_body_library.php?id=<?= (int) $t['id'] ?>"
                               title="<?= !empty($t['library_path']) ? 'دانلود فایل کتابخانه' : 'باز کردن لینک کتابخانه' ?>">
                                <?= !empty($t['library_path']) ? '⬇' : '🔗' ?>
                                <span><?= htmlspecialchars(scanBodyLibraryLabel($t)) ?></span>
                            </a>
                            <?php if (!empty($t['library_path'])): ?>
                                <span style="color:#94a3b8; font-size:.74rem;">(فایل روی سرور)</span>
                            <?php endif; ?>
                            <?php if (!empty($t['library_url'])): ?>
                                <span style="color:#94a3b8; font-size:.74rem; word-break:break-all;" title="<?= htmlspecialchars((string) $t['library_url']) ?>">لینک ثبت شده</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="sbt-nolib">— کتابخانه‌ای ثبت نشده —</span>
                        <?php endif; ?>

                        <?php if ($canManage): ?>
                            <form method="post" action="upload_scan_body_library.php" enctype="multipart/form-data"
                                  style="display:inline-flex; gap:4px; align-items:center; flex-wrap:wrap;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                <input type="file" name="library_file" required style="font-size:.75rem; max-width:200px;">
                                <button class="btn" style="background:#e0f2fe; color:#0369a1; padding:3px 8px; font-size:.78rem;">⬆ بارگذاری</button>
                            </form>
                            <?php if (!empty($t['library_path'])): ?>
                                <form method="post" action="delete_scan_body_library.php" style="display:inline-block;"
                                      onsubmit="return confirm('فایل کتابخانهٔ این نوع حذف شود؟ (لینک خارجی دست‌نخورده می‌ماند)');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                    <button class="btn" style="background:#fee2e2; color:#991b1b; padding:3px 8px; font-size:.78rem;">✕ حذف فایل</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <?php if ((int) $t['active'] === 1): ?>
                        <span class="badge" style="background:#dcfce7; color:#166534;">فعال</span>
                    <?php else: ?>
                        <span class="badge" style="background:#fee2e2; color:#991b1b;">غیرفعال</span>
                    <?php endif; ?>
                </td>
                <td><?= toPersianDigits($used) ?></td>
                <?php if ($canManage): ?>
                <td style="white-space:nowrap;">
                    <a class="btn" href="scan_body_types.php?edit=<?= (int) $t['id'] ?>" style="background:#e0f2fe; color:#0369a1; padding:4px 10px;">ویرایش</a>
                    <?php if ($used === 0): ?>
                        <form method="post" action="delete_scan_body_type.php" style="display:inline-block;" onsubmit="return confirm('این نوع اسکن‌بادی حذف شود؟');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <button class="btn" style="background:#fee2e2; color:#991b1b; padding:4px 10px;">حذف</button>
                        </form>
                    <?php else: ?>
                        <span style="color:#9ca3af; font-size:0.8rem;">در استفاده</span>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php panel_layout_end(); ?>
