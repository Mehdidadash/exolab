<?php
// panel/role_form.php
require_once __DIR__ . '/auth.php';
require_role('admin');

$editing = !empty($_GET['id']);
$role = $editing ? getRole((int) $_GET['id']) : null;
if ($editing && !$role) {
    header('Location: roles.php?error=notfound');
    exit;
}

$allPerms = getAllPermissionDefinitions();

panel_layout_start($editing ? 'ویرایش نقش' : 'افزودن نقش جدید');
?>
<form method="post" action="save_role.php">
    <?= csrf_field() ?>
    <?php if ($editing): ?>
        <input type="hidden" name="id" value="<?= $role['id'] ?>">
    <?php endif; ?>
    <div class="form-card">
        <div class="form-group">
            <label for="name">نام نقش (کلید سیستمی)</label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars($role['name'] ?? '') ?>" required<?= $editing ? ' readonly style="background:#f3f4f6;"' : '' ?>>
            <small>فقط حروف لاتین، بدون فاصله. بعد از ایجاد قابل تغییر نیست.</small>
        </div>
        <div class="form-group">
            <label for="label">عنوان نقش (نمایشی)</label>
            <input type="text" id="label" name="label" value="<?= htmlspecialchars($role['label'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label>دسترسی‌ها</label>
            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 8px; margin-top:8px;">
                <?php 
                $currentPerms = $role ? (json_decode($role['permissions'] ?? '[]', true) ?: []) : [];
                foreach ($allPerms as $key => $label): 
                    $checked = in_array($key, $currentPerms) ? 'checked' : '';
                ?>
                    <label style="display:flex; align-items:center; gap:6px; padding:4px 8px; background:#f9fafb; border-radius:6px; cursor:pointer;">
                        <input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($key) ?>" <?= $checked ?>>
                        <?= htmlspecialchars($label) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:8px;">
                <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                    <input type="checkbox" id="select-all-perms" onchange="document.querySelectorAll('input[name^=\'permissions\']').forEach(function(cb){cb.checked=this.checked;}.bind(this))">
                    انتخاب/لغو همه
                </label>
            </div>
        </div>
        <button type="submit"><?= $editing ? 'بروزرسانی' : 'ذخیره' ?></button>
        <a href="roles.php" class="btn" style="background:#E5E7EB; color:#0F172A;">انصراف</a>
    </div>
</form>
<?php panel_layout_end(); ?>
