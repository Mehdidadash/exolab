<?php
// panel\price_form.php

require_once __DIR__ . '/auth.php';
require_admin();
$price = null;
$editing = false;

if (!empty($_GET['id'])) {
    $price = getPrice((int) $_GET['id']);
    if ($price) {
        $editing = true;
    }
}

panel_layout_start($editing ? 'ویرایش قیمت' : 'افزودن قیمت جدید');
?>
<div class="form-card">
    <form method="post" action="save_price.php">
        <?= csrf_field() ?>
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
            <input type="text" id="price" name="price" value="<?= htmlspecialchars(formatTomanInput($price['price'] ?? '')) ?>" required>
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
        <div class="form-group">
            <label for="hide_on_site">نمایش در سایت اصلی (لیست قیمت)</label>
            <select id="hide_on_site" name="hide_on_site">
                <option value="0" <?= empty($price['hide_on_site']) ? 'selected' : '' ?>>نمایش داده شود</option>
                <option value="1" <?= !empty($price['hide_on_site']) ? 'selected' : '' ?>>نمایش داده نشود (فقط داخلی)</option>
            </select>
        </div>
        <button type="submit"><?= $editing ? 'بروزرسانی' : 'ذخیره' ?></button>
    </form>
</div>
<?php panel_layout_end();
