<?php
// panel/users.php
// مدیریت کاربران — مدیر کل: همهٔ کاربران | مدیر شعبه: فقط کاربرانِ شعبهٔ خودش.
require_once __DIR__ . '/auth.php';
require_login();
if (!can_manage_users()) { http_response_code(403); die('دسترسی غیرمجاز'); }

$isRoot = is_root_admin();
$myBranchId = managed_users_branch_id();

$allRoles = assignable_user_roles();

if ($isRoot) {
    $users = db()->query("SELECT * FROM users ORDER BY role ASC, full_name ASC")->fetchAll();
} else {
    // مدیر شعبه: فقط کاربرانِ شعبهٔ خودش
    $stmt = db()->prepare("SELECT * FROM users WHERE branch_id = ? ORDER BY role ASC, full_name ASC");
    $stmt->execute([(int) $myBranchId]);
    $users = $stmt->fetchAll();
}

$msg = trim((string) ($_GET['msg'] ?? ''));
$err = trim((string) ($_GET['error'] ?? ''));

panel_layout_start('مدیریت کاربران');
?>
<div style="margin-bottom: 18px; display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn" href="user_form.php">افزودن کاربر جدید</a>
    <a class="btn" href="dashboard.php" style="background:#E5E7EB; color:#0F172A;">بازگشت</a>
</div>

<?php if ($msg === 'deleted'): ?><p style="color:#166534; font-weight:bold;">کاربر حذف شد.</p><?php endif; ?>
<?php if ($err === 'not_allowed'): ?><p style="color:#b91c1c; font-weight:bold;">شما اجازهٔ مدیریت این کاربر را ندارید.</p><?php endif; ?>
<?php if ($err === 'cannot_delete_admin'): ?><p style="color:#b91c1c; font-weight:bold;">کاربر مدیر اصلی قابل حذف نیست.</p><?php endif; ?>

<?php if (!$isRoot): ?>
    <div class="form-card" style="margin-bottom:16px; background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af;">
        شما به‌عنوان <b>مدیر شعبه</b> فقط کاربرانِ شعبهٔ
        «<?= htmlspecialchars((string) (getBranch((int) $myBranchId)['name'] ?? '—')) ?>» را می‌بینید و می‌توانید
        کاربر جدید اضافه یا ویرایش کنید. ساختِ «مدیر سیستم» و «مدیر شعبه» فقط توسط مدیر کل انجام می‌شود.
    </div>
<?php endif; ?>

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
        <th>شعبه</th>
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
            <td><?php
                $ub = $u['branch_id'] ? getBranch((int) $u['branch_id']) : null;
                echo $ub ? htmlspecialchars($ub['name']) : '<span style="color:#9ca3af;">کل/سراسری</span>';
            ?></td>
            <td><?= $u['is_designer'] ? '✅' : '—' ?></td>
            <td><span class="badge" style="background:<?= $u['active'] ? '#dcfce7' : '#fee2e2' ?>; color:<?= $u['active'] ? '#166534' : '#991b1b' ?>;">
                <?= $u['active'] ? 'فعال' : 'غیرفعال' ?>
            </span></td>
            <td style="font-size:0.85rem;"><?= $u['last_login'] ? toJalaliDateFormatted($u['last_login']) : '—' ?></td>
            <td class="actions">
                <?php if ((int) $u['id'] === 1): ?>
                    <?= action_dropdown('user_view.php?id=' . $u['id'], 'user_form.php?id=' . $u['id'], null, 0) ?>
                <?php elseif (can_manage_target_user($u)): ?>
                    <?= action_dropdown('user_view.php?id=' . $u['id'], 'user_form.php?id=' . $u['id'], 'delete_user.php', $u['id']) ?>
                <?php else: ?>
                    <?= action_dropdown('user_view.php?id=' . $u['id'], null, null, 0) ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php panel_layout_end(); ?>
