<?php
// panel/lab_price_override_form.php
require_once __DIR__ . '/auth.php';
require_admin();

$editing = !empty($_GET['id']);
$override = null;
$labs = db()->query("SELECT id, full_name FROM users WHERE role IN ('outsource_lab','partner_lab','customer_lab','lab') AND active=1 ORDER BY full_name")->fetchAll();
$prices = getAllPrices();

if ($editing) {
    $stmt = db()->prepare('SELECT * FROM lab_price_overrides WHERE id = ?');
    $stmt->execute([(int)$_GET['id']]);
    $override = $stmt->fetch();
    if (!$override) {
        header('Location: lab_price_overrides.php?error=notfound');
        exit;
    }
}

panel_layout_start($editing ? 'ویرایش قیمت اختصاصی لابراتوار' : 'افزودن قیمت اختصاصی لابراتوار');
?>
<form method="post" action="save_lab_price_override.php">
    <?= csrf_field() ?>
    <?php if ($editing): ?>
        <input type="hidden" name="id" value="<?= $override['id'] ?>">
    <?php endif; ?>

    <div class="form-card">
        <div class="form-group">
            <label for="lab_id">لابراتوار</label>
            <select id="lab_id" name="lab_id" required>
                <option value="">انتخاب لابراتوار...</option>
                <?php foreach ($labs as $lab): ?>
                    <option value="<?= $lab['id'] ?>" <?= ($override['lab_id'] ?? 0) == $lab['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($lab['full_name']) ?>
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
                این قیمت هنگام صدور فاکتور لابراتوار به جای قیمت پیش‌فرض استفاده خواهد شد.
            </small>
        </div>

        <div style="display:flex; gap:10px; margin-top:20px;">
            <button type="submit" class="btn" style="background:#06B6D4;">ذخیره</button>
            <a href="lab_price_overrides.php" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</a>
        </div>
    </div>
</form>
<?php panel_layout_end(); ?>
