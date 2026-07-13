<?php
// panel/doctor_price_override_form.php
require_once __DIR__ . '/auth.php';
require_role('admin');

$editing = !empty($_GET['id']);
$override = null;
$doctors = getAllDoctors();
$prices = getAllPrices();

if ($editing) {
    $stmt = db()->prepare('SELECT * FROM doctor_price_overrides WHERE id = ?');
    $stmt->execute([(int)$_GET['id']]);
    $override = $stmt->fetch();
    if (!$override) {
        header('Location: doctor_price_overrides.php?error=notfound');
        exit;
    }
}

panel_layout_start($editing ? 'ویرایش قیمت اختصاصی' : 'افزودن قیمت اختصاصی جدید');
?>
<form method="post" action="save_doctor_price_override.php">
    <?= csrf_field() ?>
    <?php if ($editing): ?>
        <input type="hidden" name="id" value="<?= $override['id'] ?>">
    <?php endif; ?>

    <div class="form-card">
        <div class="form-group">
            <label for="doctor_id">پزشک</label>
            <select id="doctor_id" name="doctor_id" required>
                <option value="">انتخاب پزشک...</option>
                <?php foreach ($doctors as $doctor): ?>
                    <option value="<?= $doctor['id'] ?>" <?= ($override['doctor_id'] ?? 0) == $doctor['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($doctor['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="service_id">خدمت</label>
            <select id="service_id" name="service_id" required>
                <option value="">انتخاب خدمت...</option>
                <?php foreach ($prices as $price): ?>
                    <option value="<?= $price['id'] ?>" <?= ($override['service_id'] ?? 0) == $price['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($price['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="custom_price">قیمت اختصاصی (تومان)</label>
            <input type="number" id="custom_price" name="custom_price" step="1" min="0" value="<?= $override['custom_price'] ?? '' ?>" required>
            <small style="display:block; margin-top:6px; color:#525252;">
                این قیمت به جای قیمت پیش‌فرض برای این پزشک و خدمت استفاده خواهد شد.
            </small>
        </div>

        <div style="display:flex; gap:10px; margin-top:20px;">
            <button type="submit" class="btn" style="background:#06B6D4;">ذخیره</button>
            <a href="doctor_price_overrides.php" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</a>
        </div>
    </div>
</form>
<?php panel_layout_end(); ?>