<?php
// panel/fixture_types.php
// «انواع فیکسچر» — برای کیس‌های کاستوم اباتمنت کره‌ای/اروپایی.
//
// دسترسی:
//   • مدیر سیستم / مدیر شعبه → دسترسی کامل (افزودن، ویرایش، حذف).
//   • بقیهٔ کاربران مجاز به مشاهده → فقط لیست.
require_once __DIR__ . '/auth.php';
require_login();

if (!canViewFixtureTypes()) {
    http_response_code(403);
    die('دسترسی غیرمجاز');
}

$canManage = canManageFixtureTypes();
$types = getFixtureTypes(true);

// تعداد استفادهٔ هر نوع در کیس‌ها
$usage = [];
foreach ($types as $t) {
    $usage[(int) $t['id']] = fixtureTypeUsage((int) $t['id']);
}

$msg = trim((string) ($_GET['msg'] ?? ''));
$err = trim((string) ($_GET['error'] ?? ''));

// ویرایشِ درجا: اگر ?edit=<id> باشد، فرم بالای صفحه با مقادیر آن پر می‌شود.
$editId = (int) ($_GET['edit'] ?? 0);
$editing = ($canManage && $editId > 0) ? getFixtureType($editId) : null;

$errMessages = [
    'name'      => 'نام نوع فیکسچر الزامی است.',
    'duplicate' => 'نوعی با این نام قبلاً ثبت شده است.',
    'in_use'    => 'این نوع فیکسچر در کیس‌ها استفاده می‌شود و قابل حذف نیست. می‌توانید آن را «غیرفعال» کنید.',
    'notfound'  => 'نوع فیکسچر یافت نشد.',
];

panel_layout_start('انواع فیکسچر');
?>
<style>
    .ftx-desc { color:#64748b; font-size:.8rem; margin-top:3px; line-height:1.7; }
</style>

<?php if ($canManage): ?>
<div class="form-card" style="max-width:1000px; margin:0 auto 20px;">
    <h3 style="margin-top:0;"><?= $editing ? 'ویرایش نوع فیکسچر' : 'افزودن نوع فیکسچر جدید' ?></h3>
    <p style="color:#555; margin:6px 0 12px; line-height:1.9;">
        انواع «فیکسچر» را این‌جا تعریف کنید. برای خدماتِ کاستوم اباتمنت (کره‌ای/اروپایی) که در
        <a href="prices.php">قیمت‌ها</a> تیکِ «نیازمند انتخاب نوع فیکسچر» دارند، این فهرست در فرم ثبت/ویرایش کیس
        نمایش داده می‌شود. به‌صورت پیش‌فرض هیچ گزینه‌ای انتخاب نمی‌شود.
    </p>
    <form method="post" action="save_fixture_type.php">
        <?= csrf_field() ?>
        <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
        <?php endif; ?>
        <div style="display:grid; grid-template-columns:1fr 100px 110px; gap:8px; align-items:end;">
            <div class="form-group" style="margin:0;">
                <label>نام نوع فیکسچر *</label>
                <input type="text" name="name" required placeholder="مثلاً: دیو رگولار"
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
            <label for="description">توضیحات (اختیاری)</label>
            <textarea id="description" name="description" rows="2" placeholder="مثلاً: مناسب سیستم‌های اباتمنت کره‌ای"><?= htmlspecialchars((string) ($editing['description'] ?? '')) ?></textarea>
        </div>
        <div style="display:flex; gap:6px;">
            <button class="btn" style="background:#0F172A; color:#fff;"><?= $editing ? 'بروزرسانی' : 'افزودن' ?></button>
            <?php if ($editing): ?>
                <a class="btn" href="fixture_types.php" style="background:#E5E7EB; color:#0F172A;">انصراف</a>
            <?php endif; ?>
        </div>
    </form>
    <small style="display:block; color:#525252; margin-top:8px;">
        «ترتیب» (عدد کوچک‌تر = بالاتر) در همهٔ فهرست‌های انتخابی رعایت می‌شود. موارد «غیرفعال» در فرم‌ها
        نمایش داده نمی‌شوند ولی در کیس‌های قبلی باقی می‌مانند.
    </small>
</div>
<?php else: ?>
<div class="form-card" style="max-width:1000px; margin:0 auto 20px; background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; line-height:1.9;">
    شما در حالت <b>مشاهده</b> هستید. افزودن/ویرایش/حذف فقط توسط مدیر سیستم و مدیران شعبه انجام می‌شود.
</div>
<?php endif; ?>

<?php if ($msg === 'saved'): ?><p style="color:#166534; font-weight:bold;">ذخیره شد.</p><?php endif; ?>
<?php if ($msg === 'deleted'): ?><p style="color:#166534; font-weight:bold;">حذف شد.</p><?php endif; ?>
<?php if ($err !== '' && isset($errMessages[$err])): ?>
    <p style="color:#b91c1c; font-weight:bold;"><?= htmlspecialchars($errMessages[$err]) ?></p>
<?php endif; ?>

<div class="form-card" style="max-width:1100px; margin:0 auto;">
    <table class="datatable display" style="width:100%">
        <thead>
        <tr>
            <th>ترتیب</th>
            <th>نام</th>
            <th>وضعیت</th>
            <th title="تعداد کیس‌هایی که از این نوع استفاده می‌کنند">استفاده</th>
            <?php if ($canManage): ?><th>عملیات</th><?php endif; ?>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($types)): ?>
            <tr><td colspan="<?= $canManage ? 5 : 4 ?>" style="text-align:center; color:#6b7280; padding:16px;">هنوز نوعی ثبت نشده است.</td></tr>
        <?php else: foreach ($types as $t): $used = $usage[(int) $t['id']] ?? 0; ?>
            <tr>
                <td><?= toPersianDigits((int) $t['sort_order']) ?></td>
                <td>
                    <span style="display:inline-flex; align-items:center; gap:5px; background:#eef2ff; color:#3730a3; border-radius:6px; padding:2px 8px; font-weight:600;">
                        🔩 <span><?= htmlspecialchars((string) $t['name']) ?></span>
                    </span>
                    <?php if (trim((string) ($t['description'] ?? '')) !== ''): ?>
                        <div class="ftx-desc"><?= nl2br(htmlspecialchars((string) $t['description'])) ?></div>
                    <?php endif; ?>
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
                    <a class="btn" href="fixture_types.php?edit=<?= (int) $t['id'] ?>" style="background:#e0f2fe; color:#0369a1; padding:4px 10px;">ویرایش</a>
                    <?php if ($used === 0): ?>
                        <form method="post" action="delete_fixture_type.php" style="display:inline-block;" onsubmit="return confirm('این نوع فیکسچر حذف شود؟');">
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
