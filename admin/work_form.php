<?php
require_once __DIR__ . '/auth.php';
$work = null;
$editing = false;

if (!empty($_GET['id'])) {
    $work = getPortfolioWork((int) $_GET['id']);
    if ($work) {
        $editing = true;
    }
}

admin_layout_start($editing ? 'ویرایش نمونه کار' : 'افزودن نمونه کار جدید');
?>
<div class="form-card">
    <form method="post" action="save_work.php" enctype="multipart/form-data">
        <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= $work['id'] ?>">
        <?php endif; ?>
        <div class="form-group">
            <label for="title">عنوان</label>
            <input type="text" id="title" name="title" value="<?= htmlspecialchars($work['title'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="description">توضیح (اختیاری)</label>
            <textarea id="description" name="description" rows="4"><?= htmlspecialchars($work['description'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label for="image">تصویر <?= $editing ? '(اختیاری برای تغییر)' : '(ضروری)' ?></label>
            <input type="file" id="image" name="image" accept="image/*" <?= $editing ? '' : 'required' ?>>
            <?php if ($editing && !empty($work['image_filename'])): ?>
                <small style="display: block; margin-top: 8px; color: #525252;">
                    تصویر فعلی: <strong><?= htmlspecialchars($work['image_filename']) ?></strong>
                </small>
                <img src="../assets/uploads/<?= htmlspecialchars($work['image_filename']) ?>" style="max-width: 150px; margin-top: 8px; border-radius: 8px;">
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label for="display_order">ترتیب نمایش</label>
            <input type="number" id="display_order" name="display_order" value="<?= (int) ($work['display_order'] ?? 0) ?>" min="0">
        </div>
        <div class="form-group">
            <label for="active">فعال</label>
            <select id="active" name="active">
                <option value="1" <?= empty($work['active']) || $work['active'] ? 'selected' : '' ?>>بله</option>
                <option value="0" <?= isset($work['active']) && !$work['active'] ? 'selected' : '' ?>>خیر</option>
            </select>
        </div>
        <button type="submit"><?= $editing ? 'بروزرسانی' : 'ذخیره' ?></button>
    </form>
</div>
<?php admin_layout_end();
