<?php
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();
$userId = !empty($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$userId) {
    header('Location: users.php');
    exit;
}

$stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$targetUser = $stmt->fetch();
if (!$targetUser) {
    header('Location: users.php?error=notfound');
    exit;
}

$isDesigner = ($user['role'] === 'designer');
$canAccess = has_role('admin')
    || ($isDesigner && designerCanAccessUser((int) $targetUser['id']))
    || (has_role('doctor') && (int) $targetUser['id'] === (int) $user['id'])
    || (has_role('clinic') && in_array($targetUser['role'] ?? '', ['doctor', 'clinic']) && canAccessDoctor((int) $targetUser['id']));
$showSensitiveContact = has_role('admin') || in_array($user['role'] ?? '', ['clinic', 'staff', 'secretary', 'technician'], true) || (has_role('doctor') && (int) $targetUser['id'] === (int) $user['id']);
if (!$canAccess) {
    http_response_code(403);
    die('دسترسی غیرمجاز');
}

panel_layout_start('نمایه کاربر: ' . $targetUser['full_name']);
?>
<div class="form-card" style="margin-bottom:24px;">
    <h3><?= htmlspecialchars($targetUser['full_name']) ?></h3>
    <p style="margin:4px 0;"><strong>نقش:</strong> <?= htmlspecialchars($targetUser['role'] ?? '—') ?></p>
    <?php if ($showSensitiveContact): ?>
        <p style="margin:4px 0;"><strong>ایمیل:</strong> <?= htmlspecialchars($targetUser['email'] ?? '—') ?></p>
        <p style="margin:4px 0;"><strong>تلفن:</strong> <?= htmlspecialchars($targetUser['phone'] ?? '—') ?></p>
    <?php else: ?>
        <p style="margin:4px 0;"><strong>ایمیل:</strong> —</p>
        <p style="margin:4px 0;"><strong>تلفن:</strong> —</p>
    <?php endif; ?>
    <p style="margin:4px 0;"><strong>وضعیت:</strong> <?= $targetUser['active'] ? 'فعال' : 'غیرفعال' ?></p>
    <?php if (!empty($targetUser['notes'])): ?>
        <p style="margin:4px 0;"><strong>یادداشت:</strong> <?= htmlspecialchars($targetUser['notes']) ?></p>
    <?php endif; ?>
</div>

<div class="form-card">
    <h3>پیام‌ها و کامنت‌ها</h3>
    <form method="post" action="save_comment.php" style="margin-bottom:16px;">
        <?= csrf_field() ?>
        <input type="hidden" name="entity_type" value="user">
        <input type="hidden" name="entity_id" value="<?= (int) $targetUser['id'] ?>">
        <textarea name="message" rows="3" placeholder="پیام یا یادداشت برای این کاربر..." required style="width:100%;"></textarea>
        <button type="submit" class="btn" style="margin-top:8px; background:#06B6D4; color:#fff;">ارسال پیام</button>
    </form>

    <?php $userComments = getEntityComments('user', $targetUser['id']); ?>
    <?php if (!empty($userComments)): ?>
        <div style="display:flex; flex-direction:column; gap:10px;">
            <?php foreach ($userComments as $comment): ?>
                <div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:10px 12px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:6px; flex-wrap:wrap;">
                        <strong><?= htmlspecialchars($comment['user_name'] ?? 'کاربر') ?></strong>
                        <span style="font-size:0.8rem; color:#6b7280;"><?= toJalaliDateTimeFormatted($comment['created_at']) ?></span>
                    </div>
                    <div style="white-space:pre-wrap; line-height:1.8;"><?= htmlspecialchars($comment['message']) ?></div>
                    <?php if ((int) $comment['user_id'] === (int) $user['id'] || has_role('admin')): ?>
                        <form method="post" action="save_comment.php" style="margin-top:8px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="comment_id" value="<?= (int) $comment['id'] ?>">
                            <button type="submit" class="btn" style="background:#fee2e2; color:#991b1b; padding:4px 10px; font-size:0.8rem;">حذف</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="empty">هنوز پیامی ثبت نشده است.</p>
    <?php endif; ?>
</div>
<?php panel_layout_end(); ?>
