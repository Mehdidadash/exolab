<?php
// panel/notifications.php
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();
$notifications = getAllNotifications($user['id']);

// Mark all as read when visiting this page
if (!empty($_GET['mark_read'])) {
    markNotificationRead((int)$_GET['mark_read']);
    header('Location: notifications.php');
    exit;
}
if (!empty($_GET['mark_all'])) {
    markAllNotificationsRead($user['id']);
    header('Location: notifications.php');
    exit;
}

panel_layout_start('نوتیفیکیشن‌ها');
?>
<div style="margin-bottom:18px; display:flex; gap:10px; flex-wrap:wrap; justify-content:space-between; align-items:center;">
    <div>
        <a class="btn" href="dashboard.php">بازگشت به داشبورد</a>
        <?php if (getUnreadNotificationCount($user['id']) > 0): ?>
            <a class="btn" href="?mark_all=1" style="background:#0F172A; color:#fff;">علامت‌گذاری همه به عنوان خوانده شده</a>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($notifications)): ?>
    <div class="form-card" style="text-align:center; padding:40px;">
        <p style="font-size:3rem; margin:0;">🔔</p>
        <p>هیچ نوتیفیکیشنی وجود ندارد.</p>
    </div>
<?php else: ?>
    <div style="display:flex; flex-direction:column; gap:8px;">
    <?php foreach ($notifications as $n): ?>
        <div class="form-card" style="padding:14px 18px; display:flex; align-items:center; gap:12px; <?= $n['is_read'] ? 'opacity:0.6;' : 'border-right:4px solid #06B6D4;' ?>">
            <div style="font-size:1.5rem;"><?= $n['type'] === 'assignment' ? '📋' : ($n['type'] === 'status_change' ? '🔄' : 'ℹ️') ?></div>
            <div style="flex:1;">
                <strong><?= htmlspecialchars($n['title']) ?></strong>
                <?php if ($n['message']): ?>
                    <p style="margin:4px 0 0; color:#555; font-size:0.9rem;"><?= htmlspecialchars($n['message']) ?></p>
                <?php endif; ?>
                <small style="color:#999;"><?= toJalaliDateFormatted($n['created_at']) ?></small>
            </div>
            <div style="display:flex; gap:6px;">
                <?php if ($n['case_id']): ?>
                    <a class="btn" href="view_case.php?id=<?= $n['case_id'] ?>" style="padding:4px 10px; font-size:0.8rem;">مشاهده کیس</a>
                <?php endif; ?>
                <?php if (!$n['is_read']): ?>
                    <a class="btn" href="?mark_read=<?= $n['id'] ?>" style="background:#E5E7EB; color:#0F172A; padding:4px 10px; font-size:0.8rem;">خوانده شد</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php panel_layout_end(); ?>
