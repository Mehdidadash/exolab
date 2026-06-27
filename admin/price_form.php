<?php
require_once __DIR__ . '/auth.php';
$price = null;
$editing = false;

if (!empty($_GET['id'])) {
    $price = getPrice((int) $_GET['id']);
    if ($price) {
        $editing = true;
    }
}

admin_layout_start($editing ? 'ویرایش قیمت' : 'افزودن قیمت جدید');
?>
<div class="form-card">
    <form method="post" action="save_price.php">
        <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= $price['id'] ?>">
        <?php endif; ?>
        <div class="form-group">
            <label for="title">عنوان</label>
            <input type="text" id="title" name="title" value="<?= htmlspecialchars($price['title'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="description">توضیحات</label>
            <textarea id="description" name="description" rows="5" required><?= htmlspecialchars($price['description'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label for="price">قیمت</label>
            <input type="text" id="price" name="price" value="<?= htmlspecialchars($price['price'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="category">دسته</label>
            <input type="text" id="category" name="category" value="<?= htmlspecialchars($price['category'] ?? 'عمومی') ?>">
        </div>
        <div class="form-group">
            <label for="active">فعال</label>
            <select id="active" name="active">
                <option value="1" <?= empty($price['active']) || $price['active'] ? 'selected' : '' ?>>بله</option>
                <option value="0" <?= isset($price['active']) && !$price['active'] ? 'selected' : '' ?>>خیر</option>
            </select>
        </div>
        <div class="form-group">
            <label for="display_order">ترتیب نمایش (عدد کوچکتر یعنی جلوتر)</label>
            <input type="number" id="display_order" name="display_order" value="<?= isset($price['display_order']) ? (int)$price['display_order'] : 0 ?>">
        </div>
        <button type="submit"><?= $editing ? 'بروزرسانی' : 'ذخیره' ?></button>
    </form>
</div>
<?php admin_layout_end();
