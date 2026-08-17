<?php
// panel/doctor_price_override_form.php
require_once __DIR__ . '/auth.php';
require_role('admin');

$editing = !empty($_GET['id']);
$override = null;
$priceTargets = getAllBillingTargets();
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
            <label for="doctor_id">پزشک / کلینیک / طراح / لابراتوار</label>
            <select id="doctor_id" name="doctor_id" required>
                <option value="">انتخاب کاربر...</option>
                <?php foreach ($priceTargets as $target): ?>
                    <?php
                    $targetRoleLabel = 'پزشک';
                    if ($target['role'] === 'clinic') {
                        $targetRoleLabel = 'کلینیک';
                    } elseif ($target['role'] === 'designer') {
                        $targetRoleLabel = 'طراح';
                    } elseif (in_array($target['role'], ['partner_lab', 'customer_lab', 'outsource_lab', 'lab'], true)) {
                        $targetRoleLabel = 'لابراتوار';
                    }
                    ?>
                    <option value="<?= $target['id'] ?>" <?= ($override['doctor_id'] ?? 0) == $target['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($target['name']) ?> (<?= htmlspecialchars($targetRoleLabel) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <small style="display:block; margin-top:6px; color:#525252;">
                اگر برای یک کلینیک قیمت گروهی تنظیم کنید، این قیمت برای پزشک‌های زیرمجموعه آن نیز اعمال می‌شود.
            </small>
        </div>

        <div class="form-group">
            <label for="price_type">نوع قیمت</label>
            <select id="price_type" name="price_type">
                <option value="service" <?= (($override['price_type'] ?? 'service') === 'service') ? 'selected' : '' ?>>قیمت خدمت</option>
                <option value="design_fee" <?= (($override['price_type'] ?? 'service') === 'design_fee') ? 'selected' : '' ?>>هزینه طراحی</option>
            </select>
        </div>

        <div class="form-group">
            <label for="service_id">خدمت</label>
            <select id="service_id" name="service_id">
                <option value="">انتخاب خدمت...</option>
                <?php foreach ($prices as $price): ?>
                    <option value="<?= $price['id'] ?>" <?= ($override['service_id'] ?? 0) == $price['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($price['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small style="display:block; margin-top:6px; color:#525252;">
                برای «قیمت خدمت» الزامی است. برای «هزینه طراحی» اختیاری است: اگر نوع کار را انتخاب کنید، این نرخ فقط برای همان نوع کار اعمال می‌شود؛ اگر خالی بماند به‌عنوان نرخ کلی آن طراح استفاده می‌شود.
            </small>
        </div>

        <div class="form-group">
            <label for="custom_price">قیمت اختصاصی (تومان) – به‌ازای هر واحد</label>
            <input type="number" id="custom_price" name="custom_price" step="1" min="0" value="<?= $override['custom_price'] ?? '' ?>" required>
            <small style="display:block; margin-top:6px; color:#525252;">
                این مبلغ به‌ازای هر واحد است. برای «هزینه طراحی» در فرم کیس، این مبلغ در تعداد واحد ضرب می‌شود (مثلاً ۵ واحد × ۱۲۰,۰۰۰ = ۶۰۰,۰۰۰ تومان).
            </small>
        </div>

        <div style="display:flex; gap:10px; margin-top:20px;">
            <button type="submit" class="btn" style="background:#06B6D4;">ذخیره</button>
            <a href="doctor_price_overrides.php" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</a>
        </div>
    </div>
</form>
<?php panel_layout_end(); ?>