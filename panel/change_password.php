<?php
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();
panel_layout_start('تغییر رمز عبور');
?>
<div class="form-card" style="max-width:480px;">
    <form method="post" action="save_change_password.php">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="current_password">رمز عبور فعلی</label>
            <input type="password" id="current_password" name="current_password" required>
        </div>
        <div class="form-group">
            <label for="new_password">رمز عبور جدید</label>
            <input type="password" id="new_password" name="new_password" required>
        </div>
        <div class="form-group">
            <label for="confirm_password">تأیید رمز عبور جدید</label>
            <input type="password" id="confirm_password" name="confirm_password" required>
        </div>
        <button type="submit" class="btn" style="background:#06B6D4; color:#fff;">ذخیره رمز عبور</button>
        <a href="dashboard.php" class="btn" style="background:#E5E7EB; color:#0F172A; margin-right:8px;">انصراف</a>
    </form>
</div>
<?php panel_layout_end(); ?>
