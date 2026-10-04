<?php
// panel/user_form.php
// فرم افزودن/ویرایش کاربر. مدیر کل: همه | مدیر شعبه: فقط کاربرانِ شعبهٔ خودش.
require_once __DIR__ . '/auth.php';
require_login();
if (!can_manage_users()) { http_response_code(403); die('دسترسی غیرمجاز'); }

$isRoot     = is_root_admin();
$myBranchId = managed_users_branch_id();

// نقش‌های قابل انتساب برای این کاربر (مدیر شعبه نمی‌تواند admin/branch_admin بسازد)
$assignableRoles = assignable_user_roles();

$editing = !empty($_GET['id']);
$user = null;
if ($editing) {
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int)$_GET['id']]);
    $user = $stmt->fetch();
    if (!$user) {
        header('Location: users.php?error=notfound');
        exit;
    }
    // مدیر شعبه فقط کاربرانِ شعبهٔ خودش را می‌تواند ویرایش کند
    if (!can_manage_target_user($user)) {
        header('Location: users.php?error=not_allowed');
        exit;
    }
}

// نقش‌هایی که به کاربرِ در حال ویرایش نشان داده می‌شوند: اگر نقشِ فعلی قابل انتساب نیست
// (مثلاً مدیر شعبه‌ای که کاربر با نقش قدیمی را می‌بیند)، همان نقش هم به لیست اضافه می‌شود
// تا مقدارش از دست نرود (select با value نامعتبر خالی می‌شد).
$roleOptions = $assignableRoles;
$currentRole = (string) ($user['role'] ?? '');
if ($currentRole !== '' && !array_key_exists($currentRole, $roleOptions)) {
    $roleOptions[$currentRole] = $currentRole;
}

panel_layout_start($editing ? 'ویرایش کاربر' : 'افزودن کاربر جدید');
?>
<?php
$frmErr = trim((string) ($_GET['error'] ?? ''));
$frmErrMsg = [
    'missing'            => 'نام و نام کاربری الزامی است.',
    'missing_password'   => 'برای کاربر جدید، رمز عبور الزامی است.',
    'duplicate_username' => 'این نام کاربری قبلاً ثبت شده است.',
    'bad_role'           => 'انتخاب این نقش برای شما مجاز نیست.',
][$frmErr] ?? '';
?>
<?php if ($frmErrMsg !== ''): ?>
    <p style="color:#b91c1c; font-weight:bold;"><?= htmlspecialchars($frmErrMsg) ?></p>
<?php endif; ?>
<form method="post" action="save_user.php">
    <?= csrf_field() ?>
    <?php if ($editing): ?>
        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
    <?php endif; ?>

    <div class="form-card">
        <?php if (!$isRoot): ?>
            <div style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; border-radius:8px; padding:10px 12px; margin-bottom:14px; line-height:1.9;">
                شما به‌عنوان <b>مدیر شعبه</b> کاربرانِ شعبهٔ «<?= htmlspecialchars((string) (getBranch((int) $myBranchId)['name'] ?? '—')) ?>» را مدیریت می‌کنید.
                کاربرِ جدید به‌صورت خودکار به همین شعبه تعلق می‌گیرد.
            </div>
        <?php endif; ?>
        <div class="form-group">
            <label for="full_name">نام و نام خانوادگی</label>
            <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="username">نام کاربری</label>
            <input type="text" id="username" name="username" value="<?= htmlspecialchars($user['username'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="email">ایمیل</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="phone">تلفن</label>
            <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="role">نقش</label>
            <select id="role" name="role" required onchange="toggleClinicField(this.value)">
                <?php foreach ($roleOptions as $key => $label): ?>
                    <option value="<?= $key ?>" <?= ($user['role'] ?? '') === $key ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (!$isRoot): ?>
                <small style="color:#525252;">ساخت یا ویرایشِ «مدیر سیستم» و «مدیر شعبه» فقط توسط مدیر کل انجام می‌شود.</small>
            <?php endif; ?>
        </div>
        <div class="form-group" id="clinic-field" style="display:none;">
            <label for="clinic_id">کلینیک اصلی (فقط برای پزشکان)</label>
            <select id="clinic_id" name="clinic_id">
                <option value="">بدون کلینیک</option>
                <?php $clinics = db()->query("SELECT id, full_name FROM users WHERE role='clinic' AND active=1 ORDER BY full_name"); foreach ($clinics as $clinic): ?>
                    <option value="<?= $clinic['id'] ?>" <?= ($user['clinic_id'] ?? '') == $clinic['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($clinic['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small style="color:#525252;">⚠️ توجه: نقش کاربر باید «دندانپزشک» باشد. نقش «کلینیک» فقط برای حساب کلینیک (سازمان مادر) است، نه برای پزشکان.</small>
        </div>
        <?php
        // کلینیک‌های دیگرِ همین کاربر (عضویت چندگانه): یک پزشک می‌تواند در چند کلینیک کار کند.
        $memberClinicIds = $editing ? getUserClinicIds((int) $user['id']) : [];
        $primaryClinicId = (int) ($user['clinic_id'] ?? 0);
        ?>
        <div class="form-group" id="clinic-extra-field" style="display:none;">
            <label>عضویت در کلینیک‌های دیگر (پزشکی که در چند کلینیک کار می‌کند)</label>
            <div style="display:flex; flex-wrap:wrap; gap:10px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:10px;" id="clinic-extra-list">
                <?php $allClinicsForUser = getAllClinics(); ?>
                <?php if (empty($allClinicsForUser)): ?>
                    <span style="color:#64748b;">کلینیکی ثبت نشده است.</span>
                <?php else: foreach ($allClinicsForUser as $cl): ?>
                    <label style="display:inline-flex; align-items:center; gap:6px; font-weight:400; cursor:pointer;" data-clinic-id="<?= (int) $cl['id'] ?>">
                        <input type="checkbox" name="clinic_ids[]" value="<?= (int) $cl['id'] ?>"
                            <?= in_array((int) $cl['id'], array_map('intval', $memberClinicIds), true) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($cl['full_name']) ?>
                    </label>
                <?php endforeach; endif; ?>
            </div>
            <small style="color:#525252;">هر کلینیکی را که این پزشک در آن کار می‌کند علامت بزنید؛ «کلینیک اصلی» بالا فقط برای پیش‌فرضِ کیس‌های جدید است.</small>
        </div>
        <script>
        // کلینیک اصلی انتخاب‌شده نباید همزمان «عضویتِ دیگر» باشد (تناقض در داده)
        (function(){
            var primary = document.getElementById('clinic_id');
            var list = document.getElementById('clinic-extra-field');
            if (!primary || !list) return;
            function sync() {
                var pid = primary.value;
                list.querySelectorAll('label[data-clinic-id]').forEach(function(lb){
                    var same = lb.getAttribute('data-clinic-id') === pid;
                    var cb = lb.querySelector('input[type=checkbox]');
                    if (same && cb) cb.checked = true;
                    lb.style.opacity = same ? '0.6' : '1';
                });
            }
            primary.addEventListener('change', sync);
            sync();
        })();
        </script>
        <div class="form-group">
            <label for="branch_id">شعبه</label>
            <select id="branch_id" name="branch_id"<?= $isRoot ? '' : ' disabled' ?>>
                <?php if ($isRoot): ?>
                    <option value="">— کل/سراسری (فقط ادمین اصلی) —</option>
                <?php endif; ?>
                <?php foreach (getAllBranches() as $b): ?>
                    <option value="<?= (int) $b['id'] ?>" <?= (int) ($user['branch_id'] ?? ($isRoot ? 0 : $myBranchId)) === (int) $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!$isRoot): ?>
                <!-- مقدارِ شعبه همیشه ارسال شود (select غیرفعال مقدار نمی‌فرستد) -->
                <input type="hidden" name="branch_id" value="<?= (int) $myBranchId ?>">
                <small style="color:#525252;">مدیر شعبه فقط می‌تواند کاربرانِ شعبهٔ خودش را مدیریت کند.</small>
            <?php else: ?>
                <small style="color:#525252;">کاربران شعب‌های فقط داده‌های همان شعبه را می‌بینند.</small>
            <?php endif; ?>
        </div>
        <script>
        function toggleClinicField(role) {
            document.getElementById('clinic-field').style.display = (role === 'doctor') ? 'block' : 'none';
            var extra = document.getElementById('clinic-extra-field');
            if (extra) extra.style.display = (role === 'doctor') ? 'block' : 'none';
        }
        toggleClinicField('<?= htmlspecialchars($user['role'] ?? '') ?>');
        </script>
        <div class="form-group">
            <label for="password">
                رمز عبور
                <?= $editing ? '<small style="color:#525252;">(خالی بگذارید تا تغییری نکند)</small>' : '' ?>
            </label>
            <input type="password" id="password" name="password" <?= $editing ? '' : 'required' ?>>
        </div>
        <div class="form-group">
            <label for="active">فعال</label>
            <select id="active" name="active">
                <option value="1" <?= !isset($user['active']) || $user['active'] ? 'selected' : '' ?>>بله</option>
                <option value="0" <?= isset($user['active']) && !$user['active'] ? 'selected' : '' ?>>خیر</option>
            </select>
        </div>
        <div class="form-group" id="designer-field">
            <label>
                <input type="checkbox" id="is_designer" name="is_designer" value="1" <?= !empty($user['is_designer']) ? 'checked' : '' ?>>
                طراح (می‌تواند در کیس‌ها به عنوان طراح انتخاب شود)
            </label>
        </div>
        <div class="form-group" id="default-designer-field">
            <label>
                <input type="checkbox" id="is_default_designer" name="is_default_designer" value="1" <?= !empty($user['is_default_designer']) ? 'checked' : '' ?>>
                طراح پیش‌فرض (هنگام ایجاد کیس توسط لابراتوار/شعبه خودکار انتخاب شود)
            </label>
            <small style="color:#525252;">فقط یک طراح می‌تواند پیش‌فرض باشد؛ انتخاب این گزینه، قبلی را حذف می‌کند.</small>
        </div>
        <script>
        function toggleDesignerField(role) {
            var hiddenRoles = ['doctor', 'clinic'];
            document.getElementById('designer-field').style.display = hiddenRoles.includes(role) ? 'none' : 'block';
        }
        toggleDesignerField('<?= htmlspecialchars($user['role'] ?? '') ?>');
        document.getElementById('role').addEventListener('change', function(){ toggleDesignerField(this.value); });
        </script>
        <div class="form-group">
            <label for="notes">یادداشت</label>
            <textarea id="notes" name="notes" rows="3"><?= htmlspecialchars($user['notes'] ?? '') ?></textarea>
        </div>
        <button type="submit"><?= $editing ? 'بروزرسانی' : 'ذخیره' ?></button>
        <a href="users.php" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</a>
    </div>
</form>
<?php panel_layout_end(); ?>
