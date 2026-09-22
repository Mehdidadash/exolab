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
            <label for="short_name">نام اختصاری (اختیاری)</label>
            <input type="text" id="short_name" name="short_name" value="<?= htmlspecialchars((string) ($price['short_name'] ?? '')) ?>" maxlength="24" placeholder="ML" style="max-width:180px; font-family:monospace; direction:ltr; text-align:center; font-weight:bold;">
            <small style="display:block; color:#525252; margin-top:4px;">در جدول کیس‌ها و چاپ برچسب به‌جای عنوان کامل استفاده می‌شود (خالی = عنوان کامل). مثال: ML، IM_ML، Ni_Gu</small>
        </div>
        <div class="form-group">
            <label for="description">توضیحات</label>
            <textarea id="description" name="description" rows="5" required><?= htmlspecialchars($price['description'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label>قواعد قیمت‌گذاری</label>
            <div style="display:flex; gap:14px; flex-wrap:wrap; align-items:center;">
                <label style="display:flex; align-items:center; gap:6px; font-weight:400;">
                    <input type="checkbox" name="qty_manual" value="1" style="width:auto;" <?= !empty($price['qty_manual']) ? 'checked' : '' ?>>
                    تعداد دستی (مثل الاینر)
                </label>
                <label style="display:flex; align-items:center; gap:6px; font-weight:400;">
                    واحد پایه
                    <input type="number" name="base_units" min="1" step="1" style="width:80px;" value="<?= max(1, (int) ($price['base_units'] ?? 1)) ?>">
                </label>
                <label style="display:flex; align-items:center; gap:6px; font-weight:400;">
                    هر واحد اضافه
                    <input type="number" name="extra_unit_price" min="0" step="1" style="width:150px;" placeholder="= فی" value="<?= ($price['extra_unit_price'] ?? null) === null ? '' : htmlspecialchars(formatTomanInput($price['extra_unit_price'])) ?>">
                </label>
            </div>
            <small style="display:block; color:#525252; margin-top:6px;">
                قیمت پله‌ای: مبلغ کل = قیمت پایه + (تعداد − واحد پایه) × «هر واحد اضافه» — مثلاً سرجیکال گاید: قیمت ۳٬۵۰۰٬۰۰۰ برای یک دندان + ۲۵۰٬۰۰۰ برای هر دندان اضافه.
            </small>
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
