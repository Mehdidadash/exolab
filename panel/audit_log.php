<?php
// panel/audit_log.php
require_once __DIR__ . '/auth.php';
require_role('admin');

// Pagination
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Filter by action type
$filterAction = $_GET['action'] ?? '';

$where = '';
$params = [];

if ($filterAction) {
    $where = 'WHERE al.action LIKE ?';
    $params[] = "%{$filterAction}%";
}

$countStmt = db()->prepare("SELECT COUNT(*) FROM audit_log al {$where}");
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = (int) ceil($totalCount / $perPage);

$sql = "SELECT al.*, u.full_name AS user_name, u.role AS user_role
        FROM audit_log al
        LEFT JOIN users u ON al.user_id = u.id
        {$where}
        ORDER BY al.created_at DESC
        LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

panel_layout_start('لاگ فعالیت‌ها');
?>
<div style="margin-bottom: 18px; display: flex; gap: 10px; flex-wrap: wrap; justify-content: space-between; align-items: center;">
    <div>
        <a class="btn" href="dashboard.php">بازگشت به داشبورد</a>
    </div>
</div>

<!-- Filter -->
<form method="get" style="margin-bottom: 16px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
    <input type="text" name="action" placeholder="فیلتر بر اساس نوع عملیات (create، update، delete)..." value="<?= htmlspecialchars($filterAction) ?>" style="flex: 1; min-width: 200px;">
    <button type="submit" style="background: #0F172A;">فیلتر</button>
    <?php if ($filterAction): ?>
        <a href="audit_log.php" class="btn" style="background: #E5E7EB; color: #0F172A;">پاک کردن فیلتر</a>
    <?php endif; ?>
</form>

<div style="overflow-x: auto;">
<table>
    <thead>
    <tr>
        <th>زمان</th>
        <th>کاربر</th>
        <th>نقش</th>
        <th>عملیات</th>
        <th>موجودیت</th>
        <th>جزئیات</th>
        <th>IP</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($logs as $log): ?>
        <tr>
            <td style="white-space: nowrap; font-size: 0.85rem;"><?= toJalaliDateFormatted($log['created_at']) ?> - <?= toPersianDigits(substr($log['created_at'], 11, 5)) ?></td>
            <td><?= htmlspecialchars($log['user_name'] ?? '—') ?></td>
            <td><span class="badge"><?= htmlspecialchars($log['user_role'] ?? '—') ?></span></td>
            <td>
                <span class="badge" style="background: <?= str_starts_with($log['action'], 'create') ? '#dcfce7' : (str_starts_with($log['action'], 'delete') ? '#fee2e2' : '#fef3c7') ?>; color: <?= str_starts_with($log['action'], 'create') ? '#166534' : (str_starts_with($log['action'], 'delete') ? '#991b1b' : '#92400e') ?>;">
                    <?= htmlspecialchars($log['action']) ?>
                </span>
            </td>
            <td><?= htmlspecialchars($log['entity_type'] ?? '—') ?> #<?= $log['entity_id'] ? toPersianDigits($log['entity_id']) : '—' ?></td>
            <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($log['details'] ?? '—') ?></td>
            <td style="direction: ltr; font-size: 0.85rem;"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($logs)): ?>
        <tr><td colspan="7" class="empty">هیچ لاگی یافت نشد.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div style="display: flex; justify-content: center; gap: 6px; margin-top: 20px; flex-wrap: wrap;">
    <?php if ($page > 1): ?>
        <a class="btn" href="?page=<?= $page - 1 ?>&action=<?= urlencode($filterAction) ?>" style="background: #E5E7EB; color: #0F172A;">قبلی</a>
    <?php endif; ?>
    
    <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
        <a class="btn" href="?page=<?= $i ?>&action=<?= urlencode($filterAction) ?>" 
           style="<?= $i === $page ? 'background: #0F172A; color: #fff;' : 'background: #E5E7EB; color: #0F172A;' ?>">
            <?= toPersianDigits($i) ?>
        </a>
    <?php endfor; ?>
    
    <?php if ($page < $totalPages): ?>
        <a class="btn" href="?page=<?= $page + 1 ?>&action=<?= urlencode($filterAction) ?>" style="background: #E5E7EB; color: #0F172A;">بعدی</a>
    <?php endif; ?>
</div>
<div style="text-align: center; margin-top: 8px; color: #525252;">
    نمایش <?= toPersianDigits($offset + 1) ?> تا <?= toPersianDigits(min($offset + $perPage, $totalCount)) ?> از <?= toPersianDigits($totalCount) ?> لاگ
</div>
<?php endif; ?>
<?php panel_layout_end(); ?>
