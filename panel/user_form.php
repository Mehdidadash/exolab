<?php
// panel/user_form.php
require_once __DIR__ . '/auth.php';
require_role('admin');

$allRoles = [
    'admin' => 'مدیر سیستم',
    'doctor' => 'دندانپزشک',
    'staff' => 'کارمند',
    'secretary' => 'منشی',
    'designer' => 'طراح',
    'technician' => 'تکنیسین',
    'operator' => 'اپراتور دستگاه',
    'powder' => 'پودرگذار',
    'courier' => 'پیک',
    'finance' => 'امور مالی',
    'lab' => 'لابراتوار برونسپاری',
];

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
            <select id="role" name="role" required>
                <?php foreach ($allRoles as $key => $label): ?>
                    <option value="<?= $key ?>" <?= ($user['role'] ?? '') === $key ? 'selected' : '' ?>>
                        <?= $label ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
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
        <div class="form-group">
            <label for="notes">یادداشت</label>
            <textarea id="notes" name="notes" rows="3"><?= htmlspecialchars($user['notes'] ?? '') ?></textarea>
        </div>
        <button type="submit"><?= $editing ? 'بروزرسانی' : 'ذخیره' ?></button>
        <a href="users.php" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</a>
    </div>
</form>
<?php panel_layout_end(); ?>
