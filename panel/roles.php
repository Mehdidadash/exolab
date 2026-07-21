<?php
// panel/roles.php
require_once __DIR__ . '/auth.php';
require_role('admin');

$roles = getAllRoles();
$allPerms = getAllPermissionDefinitions();

panel_layout_start('مدیریت نقش‌ها');
?>
<div style="margin-bottom: 18px; display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn" href="role_form.php">افزودن نقش جدید</a>
    <a class="btn" href="dashboard.php" style="background:#E5E7EB; color:#0F172A;">بازگشت</a>
</div>

<table class="datatable display">
    <thead>
    <tr>
        <th>نام نقش</th>
        <th>عنوان</th>
        <th>دسترسی‌ها</th>
        <th>عملیات</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($roles as $r): 
        $perms = json_decode($r['permissions'] ?? '[]', true) ?: [];
        $permLabels = array_map(function($p) use ($allPerms) {
            return $allPerms[$p] ?? $p;
        }, $perms);
        if (in_array('*', $perms)) $permLabels = ['دسترسی کامل'];
        $isUsed = db()->prepare('SELECT COUNT(*) FROM users WHERE role = ?')->execute([$r['name']]) || false;
        $usedCount = db()->prepare('SELECT COUNT(*) FROM users WHERE role = ?');
        $usedCount->execute([$r['name']]);
        $usedCount = (int) $usedCount->fetchColumn();
    ?>
        <tr>
            <td><code><?= htmlspecialchars($r['name']) ?></code></td>
            <td><?= htmlspecialchars($r['label']) ?></td>
            <td style="max-width:300px;"><?= htmlspecialchars(implode('، ', $permLabels)) ?></td>
            <td class="actions">
                <?= action_dropdown(null, 'role_form.php?id=' . $r['id'], 'delete_role.php', $r['id']) ?>
                <?php if ($usedCount > 0): ?><small style="color:#888;display:block;"><?= toPersianDigits($usedCount) ?> کاربر</small><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php panel_layout_end(); ?>
