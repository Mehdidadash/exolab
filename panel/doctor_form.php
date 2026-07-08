<?php
// panel\doctor_form.php

require_once __DIR__ . '/auth.php';
require_role('admin');
$doctor = null;
$editing = false;

if (!empty($_GET['id'])) {
    $doctor = getDoctor((int) $_GET['id']);
    if ($doctor) {
        $editing = true;
    }
}

panel_layout_start($editing ? 'ویرایش پزشک' : 'افزودن پزشک جدید');
?>
<div class="form-card">
    <form method="post" action="save_doctor.php">
        <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= $doctor['id'] ?>">
        <?php endif; ?>
        <div class="form-group">
            <label for="name">نام</label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars($doctor['name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="phone">تلفن</label>
            <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($doctor['phone'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="email">ایمیل</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($doctor['email'] ?? '') ?>">
        </div>

        <!-- NEW: doctor portal login password.
             Doctors log in with their phone OR email (whichever is filled above)
             plus this password. Leave blank when editing to keep the old password. -->
        <div class="form-group">
            <label for="password">
                رمز عبور ورود به پنل پزشک
                <?= $editing ? '(خالی بگذارید تا رمز فعلی تغییر نکند)' : '(برای فعال‌سازی دسترسی پزشک به پنل خودش وارد کنید)' ?>
            </label>
            <input type="text" id="password" name="password" placeholder="<?= $editing ? '••••••••' : 'رمز عبور اولیه' ?>" autocomplete="new-password">
            <small style="display:block; margin-top:6px; color:#525252;">
                پزشک با شماره تلفن یا ایمیل ثبت‌شده در بالا، به همراه این رمز عبور، وارد پنل خودش می‌شود.
                <?php if (!$editing): ?>در صورت خالی گذاشتن، پزشک تا تعیین رمز عبور امکان ورود نخواهد داشت.<?php endif; ?>
            </small>
        </div>

        <div class="form-group">
            <label for="notes">یادداشت‌ها</label>
            <textarea id="notes" name="notes" rows="4"><?= htmlspecialchars($doctor['notes'] ?? '') ?></textarea>
        </div>
        <button type="submit"><?= $editing ? 'بروزرسانی' : 'ذخیره' ?></button>
    </form>
</div>
<?php panel_layout_end();
