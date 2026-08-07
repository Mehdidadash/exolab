<?php
// panel/user_form.php
require_once __DIR__ . '/auth.php';
require_role('admin');

$allRoles = [];
foreach (getAllRoles() as $r) {
    $allRoles[$r['name']] = $r['label'];
}

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
}

panel_layout_start($editing ? 'ویرایش کاربر' : 'افزودن کاربر جدید');
?>
<form method="post" action="save_user.php">
    <?= csrf_field() ?>
    <?php if ($editing): ?>
        <input type="hidden" name="id" value="<?= $user['id'] ?>">
    <?php endif; ?>

    <div class="form-card">
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
                <?php foreach ($allRoles as $key => $label): ?>
                    <option value="<?= $key ?>" <?= ($user['role'] ?? '') === $key ? 'selected' : '' ?>>
                        <?= $label ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" id="clinic-field" style="display:none;">
            <label for="clinic_id">کلینیک (فقط برای پزشکان زیرمجموعه کلینیک)</label>
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
        <script>
        function toggleClinicField(role) {
            document.getElementById('clinic-field').style.display = (role === 'doctor') ? 'block' : 'none';
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
