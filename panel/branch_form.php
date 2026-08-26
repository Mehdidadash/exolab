<?php
// panel/branch_form.php
// Create / edit a branch.
require_once __DIR__ . '/auth.php';
require_root_admin();

$id = !empty($_GET['id']) ? (int) $_GET['id'] : 0;
$branch = $id ? (getBranch($id) ?? []) : [];
$branches = getAllBranches();
$owners = db()->query("SELECT id, full_name, role FROM users WHERE active = 1 ORDER BY role ASC, full_name ASC")->fetchAll();

panel_layout_start($id ? 'ویرایش شعبه' : 'افزودن شعبه');
?>
<div style="margin-bottom:18px;">
    <a class="btn" href="branches.php" style="background:#E5E7EB; color:#0F172A;">بازگشت</a>
</div>
<div class="form-card" style="max-width:520px;">
    <form method="post" action="save_branch.php">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <div class="form-group">
            <label>نام شعبه *</label>
            <input type="text" name="name" value="<?= htmlspecialchars($branch['name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label>کد (اختیاری)</label>
            <input type="text" name="code" value="<?= htmlspecialchars($branch['code'] ?? '') ?>" placeholder="مثلاً QAZVIN">
        </div>
        <div class="form-group">
            <label>شعبه والد</label>
            <select name="parent_id">
                <option value="">— بدون —</option>
                <?php foreach ($branches as $b): if ((int) $b['id'] === (int) $id) continue; ?>
                    <option value="<?= (int) $b['id'] ?>" <?= (int) ($branch['parent_id'] ?? 0) === (int) $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>مدیر شعبه (کاربر)</label>
            <select name="owner_user_id">
                <option value="">— انتخاب —</option>
                <?php foreach ($owners as $o): ?>
                    <option value="<?= (int) $o['id'] ?>" <?= (int) ($branch['owner_user_id'] ?? 0) === (int) $o['id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['full_name']) ?> (<?= htmlspecialchars($o['role']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <small style="display:block; color:#525252; margin-top:4px;">کاربر انتخاب‌شده باید در صفحه «شعبه‌ها» نیز به همین شعبه متصل شود (branch_id).</small>
        </div>
        <div class="form-group">
            <label style="display:flex; align-items:center; gap:8px;">
                <input type="checkbox" name="active" value="1" style="width:auto;" <?= !isset($branch['active']) || $branch['active'] ? 'checked' : '' ?>>
                فعال
            </label>
        </div>
        <button type="submit" class="btn" style="background:#06B6D4; color:#fff;">ذخیره</button>
    </form>
</div>
<?php panel_layout_end(); ?>
