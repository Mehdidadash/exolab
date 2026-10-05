<?php
// panel/notifications.php
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();

$scope = ($_GET['scope'] ?? '') === 'important' ? 'important' : 'all';
$notifications = getAllNotifications($user['id'], 50, $scope === 'important' ? 'important' : null);

if (!empty($_GET['mark_read'])) {
    markNotificationRead((int)$_GET['mark_read']);
    header('Location: notifications.php' . ($scope === 'important' ? '?scope=important' : ''));
    exit;
}
if (!empty($_GET['mark_all'])) {
    markAllNotificationsRead($user['id']);
    header('Location: notifications.php');
    exit;
}
if (!empty($_GET['mark_all_important'])) {
    markAllImportantNotificationsRead($user['id']);
    header('Location: notifications.php?scope=important');
    exit;
}

$totalUnread = getUnreadNotificationCount($user['id']);
$importantUnread = getUnreadImportantNotificationCount($user['id']);

panel_layout_start($scope === 'important' ? 'اعلان‌های مهم' : 'نوتیفیکیشن‌ها');
?>
<style>
    .notif-tabs { display:flex; gap:8px; margin-bottom:16px; border-bottom:1px solid #E5E7EB; flex-wrap:wrap; }
    .notif-tab { padding:10px 16px; border-radius:10px 10px 0 0; font-weight:700; text-decoration:none; color:#334155; background:#F1F5F9; border:1px solid transparent; border-bottom:none; display:inline-flex; align-items:center; gap:8px; }
    .notif-tab:hover { background:#E2E8F0; color:#0F172A; }
    .notif-tab.active { background:#fff; color:#0F172A; border-color:#E5E7EB; }
    .notif-tab.active.important-tab { color:#dc2626; }
    .notif-tab .tab-badge { background:#ef4444; color:#fff; font-size:.7rem; padding:1px 7px; border-radius:999px; font-weight:700; }
    .notif-tab.active.important-tab .tab-badge { background:#dc2626; }
    .notif-item { padding:14px 18px; display:flex; align-items:center; gap:12px; border-radius:12px; }
    .notif-item.important { border-right:5px solid #dc2626; background:linear-gradient(90deg,#FEF2F2 0%,#fff 40%); box-shadow:0 1px 3px rgba(220,38,38,.12); }
    .notif-item.normal { border-right:4px solid #06B6D4; }
    .notif-item.read { opacity:.6; }
    .notif-item.important.read { border-right:5px solid #fca5a5; background:#fff; }
    .notif-icon { font-size:1.5rem; width:38px; height:38px; display:inline-flex; align-items:center; justify-content:center; border-radius:50%; flex-shrink:0; }
    .notif-icon.important { background:#FEE2E2; }
    .notif-icon.normal { background:#E0F2FE; }
    .notif-important-flag { font-size:.65rem; background:#dc2626; color:#fff; padding:2px 8px; border-radius:999px; font-weight:700; margin-inline-start:6px; }
</style>

<div class="notif-tabs">
    <a class="notif-tab<?= $scope === 'all' ? ' active' : '' ?>" href="notifications.php">
        🔔 همهٔ اعلان‌ها
        <?php if ($totalUnread > 0): ?><span class="tab-badge"><?= toPersianDigits($totalUnread > 99 ? '99+' : $totalUnread) ?></span><?php endif; ?>
    </a>
    <a class="notif-tab important-tab<?= $scope === 'important' ? ' active' : '' ?>" href="notifications.php?scope=important">
        ⚠️ اعلان‌های مهم
        <?php if ($importantUnread > 0): ?><span class="tab-badge"><?= toPersianDigits($importantUnread > 99 ? '99+' : $importantUnread) ?></span><?php endif; ?>
    </a>
</div>

<div style="margin-bottom:18px; display:flex; gap:10px; flex-wrap:wrap; justify-content:space-between; align-items:center;">
    <div>
        <a class="btn" href="dashboard.php">بازگشت به داشبورد</a>
        <?php if ($scope === 'important' && $importantUnread > 0): ?>
            <a class="btn" href="?mark_all_important=1" style="background:#dc2626; color:#fff;">علامت‌گذاری همهٔ اعلان‌های مهم به عنوان خوانده شده</a>
        <?php elseif ($scope === 'all' && $totalUnread > 0): ?>
            <a class="btn" href="?mark_all=1" style="background:#0F172A; color:#fff;">علامت‌گذاری همه به عنوان خوانده شده</a>
        <?php endif; ?>
    </div>
    <small style="color:#64748B;">اعلان‌های مهم: کامنت جدید و تغییر وضعیت‌های خاص. اعلان‌های آپلود فایل و تخصیص طراحی/لابراتوار در «همهٔ اعلان‌ها» می‌مانند.</small>
</div>

<?php if (empty($notifications)): ?>
    <div class="form-card" style="text-align:center; padding:40px;">
        <p style="font-size:3rem; margin:0;"><?= $scope === 'important' ? '⚠️' : '🔔' ?></p>
        <p><?= $scope === 'important' ? 'هیچ اعلان مهمی وجود ندارد.' : 'هیچ نوتیفیکیشنی وجود ندارد.' ?></p>
    </div>
<?php else: ?>
    <div style="display:flex; flex-direction:column; gap:8px;">
    <?php foreach ($notifications as $n):
        $isImportant = isImportantNotificationType($n['type']);
        $icon = $isImportant
            ? ($n['type'] === 'comment' ? '💬' : '🔄')
            : ($n['type'] === 'assignment' ? '📋' : ($n['type'] === 'file' ? '📎' : ($n['type'] === 'appointment' ? '📅' : 'ℹ️')));
        $cls = 'notif-item ' . ($isImportant ? 'important' : 'normal') . ($n['is_read'] ? ' read' : '');
    ?>
        <div class="form-card <?= $cls ?>">
            <div class="notif-icon <?= $isImportant ? 'important' : 'normal' ?>"><?= $icon ?></div>
            <div style="flex:1;">
                <strong><?= htmlspecialchars($n['title']) ?><?php if ($isImportant): ?><span class="notif-important-flag">مهم</span><?php endif; ?></strong>
                <?php if ($n['message']): ?>
                    <p style="margin:4px 0 0; color:#555; font-size:0.9rem;"><?= htmlspecialchars($n['message']) ?></p>
                <?php endif; ?>
                <small style="color:#999;"><?= toJalaliDateTimeFormatted($n['created_at']) ?></small>
            </div>
            <div style="display:flex; gap:6px;">
                <?php if ($n['case_id']): ?>
                    <a class="btn" href="view_case.php?id=<?= $n['case_id'] ?>" style="padding:4px 10px; font-size:0.8rem;">مشاهده کیس</a>
                <?php endif; ?>
                <?php if (!$n['is_read']): ?>
                    <a class="btn" href="?mark_read=<?= $n['id'] ?><?= $scope === 'important' ? '&amp;scope=important' : '' ?>" style="background:#E5E7EB; color:#0F172A; padding:4px 10px; font-size:0.8rem;">خوانده شد</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php panel_layout_end(); ?>
