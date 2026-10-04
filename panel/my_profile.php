<?php
// panel/my_profile.php
// ویرایش پروفایلِ خودِ پزشک (و هر کاربری که به این صفحه هدایت شود).
// پزشک فقط به پروفایل خودش دسترسی دارد؛ مدیر می‌تواند با ?id= پروفایل هر پزشکی را ببیند/ویرایش کند.
// فیلدهای قابل ویرایش: نام و نام خانوادگی، نام کاربری، ایمیل، تلفن، رمز عبور.

require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();
$canManageOthers = has_role('admin') || is_admin();
$targetId = !empty($_GET['id']) ? (int) $_GET['id'] : (int) $user['id'];

// ─── دسترسی: پزشک فقط پروفایل خودش؛ مدیر می‌تواند هر پزشکی را ───
if ($targetId !== (int) $user['id'] && !$canManageOthers) {
    http_response_code(403);
    die('دسترسی غیرمجاز — فقط می‌توانید پروفایل خودتان را ویرایش کنید.');
}

$stmt = db()->prepare('SELECT id, username, full_name, email, phone, role, active FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$targetId]);
$profile = $stmt->fetch();

if (!$profile) {
    http_response_code(404);
    die('کاربر یافت نشد.');
}

$isSelf = ((int) $profile['id'] === (int) $user['id']);
$error  = (string) ($_GET['error'] ?? '');
$okMsg  = (string) ($_GET['ok'] ?? '');

panel_layout_start($isSelf ? 'پروفایل من' : 'ویرایش پروفایل پزشک');
?>
<div class="form-card" style="max-width:620px;">
    <?php if ($okMsg === 'saved'): ?>
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; border-radius:8px; padding:10px 12px; margin-bottom:14px;">
            ✅ اطلاعات پروفایل ذخیره شد.
        </div>
    <?php elseif ($okMsg === 'password'): ?>
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; border-radius:8px; padding:10px 12px; margin-bottom:14px;">
            ✅ رمز عبور جدید ذخیره شد.
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div style="background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; border-radius:8px; padding:10px 12px; margin-bottom:14px;">
            <?php
            $errText = [
                'missing_name'   => 'نام و نام خانوادگی الزامی است.',
                'missing_login'  => 'نام کاربری الزامی است.',
                'dup_username'   => 'این نام کاربری قبلاً استفاده شده است.',
                'dup_email'      => 'این ایمیل قبلاً برای کاربر دیگری ثبت شده است.',
                'dup_phone'      => 'این شماره تلفن قبلاً برای کاربر دیگری ثبت شده است.',
                'short_password' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.',
                'password_mismatch' => 'تکرار رمز عبور مطابقت ندارد.',
                'csrf'           => 'نشست شما منقضی شده است؛ صفحه را رفرش کنید و دوباره تلاش کنید.',
            ][$error] ?? 'خطا در ذخیره اطلاعات.';
            echo htmlspecialchars($errText);
            ?>
        </div>
    <?php endif; ?>

    <form method="post" action="save_my_profile.php">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $profile['id'] ?>">

        <div class="form-group">
            <label for="full_name">نام و نام خانوادگی</label>
            <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($profile['full_name'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <label for="username">نام کاربری</label>
            <input type="text" id="username" name="username" value="<?= htmlspecialchars($profile['username'] ?? '') ?>" required autocomplete="username">
            <small style="display:block; margin-top:6px; color:#525252;">با نام کاربری، ایمیل یا شماره تلفن می‌توانید وارد شوید.</small>
        </div>

        <div class="form-group">
            <label for="phone">تلفن</label>
            <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($profile['phone'] ?? '') ?>" autocomplete="tel">
        </div>

        <div class="form-group">
            <label for="email">ایمیل</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($profile['email'] ?? '') ?>" autocomplete="email">
        </div>

        <hr style="margin:16px 0; border:none; border-top:1px solid #e5e7eb;">
        <h4 style="margin:0 0 10px;">تغییر رمز عبور</h4>
        <small style="display:block; margin-bottom:10px; color:#525252;">اگر نمی‌خواهید رمز را عوض کنید، این دو فیلد را خالی بگذارید.</small>

        <div class="form-group">
            <label for="new_password">رمز عبور جدید</label>
            <input type="password" id="new_password" name="new_password" placeholder="••••••••" autocomplete="new-password">
        </div>
        <div class="form-group">
            <label for="confirm_password">تکرار رمز عبور جدید</label>
            <input type="password" id="confirm_password" name="confirm_password" placeholder="••••••••" autocomplete="new-password">
        </div>

        <button type="submit" class="btn" style="background:#06B6D4; color:#fff;">ذخیره تغییرات</button>
        <a href="dashboard.php" class="btn" style="background:#E5E7EB; color:#0F172A; margin-right:8px;">انصراف</a>
        <?php if (!$isSelf): ?>
            <a href="doctor_view.php?id=<?= (int) $profile['id'] ?>" class="btn" style="background:#E5E7EB; color:#0F172A; margin-right:8px;">مشاهدهٔ پروفایل</a>
        <?php endif; ?>
    </form>
</div>
<?php panel_layout_end();
