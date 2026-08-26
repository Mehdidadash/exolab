<?php
// panel\doctor_form.php

require_once __DIR__ . '/auth.php';
require_admin();
$doctor = null;
$editing = false;
$clinicParents = getAllDoctorAndClinicUsers();

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
        <?= csrf_field() ?>
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
            <label for="clinic_id">کلینیک/پدر (اختیاری)</label>
            <select id="clinic_id" name="clinic_id">
                <option value="">بدون کلینیک/پدر</option>
                <?php foreach ($clinicParents as $parent): ?>
                    <?php if (!empty($doctor) && (int) $parent['id'] === (int) $doctor['id']) continue; ?>
                    <option value="<?= (int) $parent['id'] ?>" <?= (!empty($doctor) && (int) ($doctor['clinic_id'] ?? 0) === (int) $parent['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($parent['name']) ?> (<?= htmlspecialchars($parent['role'] === 'clinic' ? 'کلینیک' : 'پزشک') ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <small style="display:block; margin-top:6px; color:#525252;">
                اگر این پزشک مالک کلینیک است یا زیرمجموعه یک کلینیک/پزشک دیگر می‌شود، این گزینه را مشخص کنید.
            </small>
        </div>

        <div class="form-group">
            <label for="lab_id">لابراتوار زیرمجموعه (اختیاری)</label>
            <select id="lab_id" name="lab_id">
                <option value="">بدون لابراتوار</option>
                <?php $labsForForm = db()->query("SELECT id, full_name FROM users WHERE role IN ('outsource_lab','customer_lab','partner_lab','lab') AND active=1 ORDER BY full_name")->fetchAll(); ?>
                <?php foreach ($labsForForm as $lf): ?>
                    <option value="<?= (int) $lf['id'] ?>" <?= (!empty($doctor) && (int) ($doctor['lab_id'] ?? 0) === (int) $lf['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($lf['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small style="display:block; margin-top:6px; color:#525252;">
                اگر این پزشک زیرمجموعه یک لابراتوار است (مثل شعبه)، مشخص کنید.
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
