<?php
// panel/branches.php
// Manage branches (شعبه‌ها) – multi-branch hierarchical lab system.
require_once __DIR__ . '/auth.php';
require_root_admin();

$branches = getAllBranches();

// All users that could be a branch owner (admin/staff + lab roles)
$ownerCandidates = db()->query("
    SELECT u.id, u.full_name, u.role, u.branch_id
    FROM users u
    WHERE u.active = 1
    ORDER BY u.role ASC, u.full_name ASC
")->fetchAll();

panel_layout_start('مدیریت شعبه‌ها');
?>
<div style="margin-bottom: 18px; display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn" href="branch_form.php">افزودن شعبه جدید</a>
    <a class="btn" href="dashboard.php" style="background:#E5E7EB; color:#0F172A;">بازگشت</a>
</div>

<?php if (empty($branches)): ?>
    <p class="empty">هیچ شعبه‌ای یافت نشد.</p>
<?php else: ?>
<div style="overflow-x:auto;">
<table class="datatable display">
    <thead>
    <tr>
        <th>شناسه</th>
        <th>نام شعبه</th>
        <th>کد</th>
        <th>شعبه والد</th>
        <th>مدیر شعبه</th>
        <th>وضعیت</th>
        <th>عملیات</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($branches as $b):
        $parent = $b['parent_id'] ? getBranch((int) $b['parent_id']) : null;
    ?>
        <tr>
            <td><?= (int) $b['id'] ?></td>
            <td><strong><?= htmlspecialchars($b['name']) ?></strong></td>
            <td><code><?= htmlspecialchars($b['code'] ?? '') ?></code></td>
            <td><?= $parent ? htmlspecialchars($parent['name']) : '—' ?></td>
            <td><?= htmlspecialchars($b['owner_name'] ?? '—') ?> (<?= htmlspecialchars($b['owner_user_id'] ?? '—') ?>)</td>
            <td><?= $b['active'] ? 'فعال' : 'غیرفعال' ?></td>
            <td class="actions">
                <a class="btn" href="branch_form.php?id=<?= (int) $b['id'] ?>" style="background:#F3F4F6; color:#111; padding:4px 10px; text-decoration:none;">ویرایش</a>
                <?php if ((int) $b['id'] !== 1): ?>
                <form method="post" action="delete_branch.php" style="display:inline;" onsubmit="return confirm('حذف شعبه؟ کیس‌ها و اسناد آن به شعبه اصلی منتقل نمی‌شوند.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                    <button class="btn" style="background:#fee2e2; color:#991b1b; padding:4px 10px;">حذف</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<h3 style="margin-top:28px;">اختصاص کاربران به شعبه‌ها</h3>
<div class="form-card" style="margin-top:12px;">
    <p style="color:#525252; margin-bottom:12px;">کاربرانی که <code>branch_id</code> داشته باشند، فقط داده‌های همان شعبه را می‌بینند. کاربران بدون شعبه (NULL) مدیر کل هستند و همه‌چیز را می‌بینند.</p>
    <div class="table-scroll">
    <table class="display" style="width:100%">
        <thead>
        <tr>
            <th>کاربر</th>
            <th>نقش</th>
            <th>شعبه</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($ownerCandidates as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['full_name']) ?> (<?= (int) $u['id'] ?>)</td>
                <td><?= htmlspecialchars($u['role']) ?></td>
                <td>
                    <form method="post" action="save_user_branch.php" style="display:flex; gap:6px; align-items:center;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                        <select name="branch_id" style="min-width:180px;">
                            <option value="">— کل/سراسری —</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?= (int) $b['id'] ?>" <?= (int) ($u['branch_id'] ?? 0) === (int) $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn" style="padding:4px 10px;">ذخیره</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<h3 style="margin-top:28px;">اختصاص پزشکان به شعبه‌ها (مالکیت)</h3>
<div class="form-card" style="margin-top:12px;">
    <p style="color:#525252; margin-bottom:12px;">هر پزشک متعلق به یک شعبه است. کیس‌ها، فاکتورها و پرداخت‌های هر پزشک متعلق به همان شعبه است و شعبه‌های دیگر فقط در صورت <strong>دسترسی داده‌شده</strong> می‌توانند آن را ببینند.</p>
    <?php $allDoctors = db()->query('SELECT id, full_name, branch_id FROM users WHERE role = "doctor" AND active = 1 ORDER BY full_name')->fetchAll(); ?>
    <?php if (empty($allDoctors)): ?>
        <p class="empty">پزشکی ثبت نشده است.</p>
    <?php else: ?>
    <table class="display" style="width:100%">
        <thead>
        <tr>
            <th>پزشک</th>
            <th>شعبه مالک</th>
            <th>دسترسی داده‌شده به شعبه‌ها</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($allDoctors as $doc):
            $docOwner = $doc['branch_id'] ? getBranch((int) $doc['branch_id']) : null;
            $grantsStmt = db()->prepare('SELECT branch_id FROM branch_doctor_access WHERE doctor_id = ?');
            $grantsStmt->execute([(int) $doc['id']]);
            $grantedBranchIds = $grantsStmt->fetchAll(PDO::FETCH_COLUMN);
        ?>
            <tr>
                <td><strong><?= htmlspecialchars($doc['full_name']) ?></strong> (<?= (int) $doc['id'] ?>)</td>
                <td>
                    <form method="post" action="save_doctor_branch.php" style="display:flex; gap:6px; align-items:center;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="doctor_id" value="<?= (int) $doc['id'] ?>">
                        <select name="branch_id" style="min-width:180px;">
                            <?php foreach ($branches as $b): ?>
                                <option value="<?= (int) $b['id'] ?>" <?= (int) ($doc['branch_id'] ?? 0) === (int) $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn" style="padding:4px 10px;">ذخیره</button>
                    </form>
                </td>
                <td>
                    <form method="post" action="save_branch_doctor_access.php" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="doctor_id" value="<?= (int) $doc['id'] ?>">
                        <select name="grant_to_branch_id" style="min-width:180px;">
                            <option value="">— انتخاب شعبه برای دسترسی —</option>
                            <?php foreach ($branches as $b): if ((int) $b['id'] === (int) ($doc['branch_id'] ?? 0)) continue; ?>
                                <option value="<?= (int) $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn" style="padding:4px 10px; background:#06B6D4; color:#fff;">➕ دسترسی</button>
                    </form>
                    <?php foreach ($grantedBranchIds as $gb): $gbBranch = getBranch((int) $gb); ?>
                        <?php if ($gbBranch): ?>
                            <span style="display:inline-block; background:#e0f2fe; color:#0369a1; border-radius:6px; padding:2px 8px; margin:2px 4px 2px 0; font-size:0.8rem;">
                                <?= htmlspecialchars($gbBranch['name']) ?>
                                <form method="post" action="save_branch_doctor_access.php" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="doctor_id" value="<?= (int) $doc['id'] ?>">
                                    <input type="hidden" name="grant_to_branch_id" value="<?= (int) $gb ?>">
                                    <input type="hidden" name="action" value="revoke">
                                    <button type="submit" style="background:none; border:none; color:#b91c1c; cursor:pointer; font-size:0.8rem;" title="حذف دسترسی">✕</button>
                                </form>
                            </span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php panel_layout_end(); ?>
