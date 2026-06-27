<?php
require_once __DIR__ . '/auth.php';
$doctor = null;
$editing = false;

if (!empty($_GET['id'])) {
    $doctor = getDoctor((int) $_GET['id']);
    if ($doctor) {
        $editing = true;
    }
}

admin_layout_start($editing ? 'ویرایش پزشک' : 'افزودن پزشک جدید');
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
        <div class="form-group">
            <label for="notes">یادداشت‌ها</label>
            <textarea id="notes" name="notes" rows="4"><?= htmlspecialchars($doctor['notes'] ?? '') ?></textarea>
        </div>
        <button type="submit"><?= $editing ? 'بروزرسانی' : 'ذخیره' ?></button>
    </form>
</div>
<?php admin_layout_end();
