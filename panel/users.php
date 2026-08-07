<?php
// panel/users.php
require_once __DIR__ . '/auth.php';
require_role('admin');

$allRoles = [];
foreach (getAllRoles() as $r) {
    $allRoles[$r['name']] = $r['label'];
}

$stmt = db()->query("SELECT * FROM users ORDER BY role ASC, full_name ASC");
$users = $stmt->fetchAll();

panel_layout_start('مدیریت کاربران');
?>
<div style="margin-bottom: 18px; display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn" href="user_form.php">افزودن کاربر جدید</a>
    <a class="btn" href="dashboard.php" style="background:#E5E7EB; color:#0F172A;">بازگشت</a>
</div>

<?php if (empty($users)): ?>
    <p class="empty">هیچ کاربری یافت نشد.</p>
<?php else: ?>
<div style="overflow-x:auto;">
<table class="datatable display">
    <thead>
    <tr>
        <th>نام</th>
        <th>نام کاربری</th>
        <th>ایمیل</th>
        <th>تلفن</th>
        <th>نقش</th>
        <th>طراح</th>
        <th>وضعیت</th>
        <th>آخرین ورود</th>
        <th>عملیات</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <tr>
            <td><?= htmlspecialchars($u['full_name']) ?></td>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td><?= htmlspecialchars($u['email'] ?? '—') ?></td>
            <td><?= htmlspecialchars($u['phone'] ?? '—') ?></td>
            <td><span class="badge"><?= htmlspecialchars($allRoles[$u['role']] ?? $u['role']) ?></span></td>
            <td><?= $u['is_designer'] ? '✅' : '—' ?></td>
            <td><span class="badge" style="background:<?= $u['active'] ? '#dcfce7' : '#fee2e2' ?>; color:<?= $u['active'] ? '#166534' : '#991b1b' ?>;">
                <?= $u['active'] ? 'فعال' : 'غیرفعال' ?>
            </span></td>
            <td style="font-size:0.85rem;"><?= $u['last_login'] ? toJalaliDateFormatted($u['last_login']) : '—' ?></td>
            <td class="actions">
                <?php if ($u['id'] !== 1): ?>
                    <?= action_dropdown(null, 'user_form.php?id=' . $u['id'], 'delete_user.php', $u['id']) ?>
                <?php else: ?>
                    <?= action_dropdown(null, 'user_form.php?id=' . $u['id'], null, 0) ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php panel_layout_end(); ?>
