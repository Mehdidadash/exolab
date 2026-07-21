<?php
// panel/audit_log.php
require_once __DIR__ . '/auth.php';
require_role('admin');

// Fetch ALL logs – DataTables handles pagination/search client-side
$filterAction = $_GET['action'] ?? '';

$where = '';
$params = [];
if ($filterAction) {
    $where = 'WHERE al.action LIKE ?';
    $params[] = "%{$filterAction}%";
}

$sql = "SELECT al.*, u.full_name AS user_name, u.role AS user_role
        FROM audit_log al
        LEFT JOIN users u ON al.user_id = u.id
        {$where}
        ORDER BY al.created_at DESC";

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

<form method="get" style="margin-bottom: 16px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
    <input type="text" name="action" placeholder="فیلتر بر اساس نوع عملیات (create، update، delete)..." value="<?= htmlspecialchars($filterAction) ?>" style="flex: 1; min-width: 200px;">
    <button type="submit" style="background: #0F172A;">فیلتر</button>
    <?php if ($filterAction): ?>
        <a href="audit_log.php" class="btn" style="background: #E5E7EB; color: #0F172A;">پاک کردن فیلتر</a>
    <?php endif; ?>
</form>

<div style="overflow-x: auto;">
<table class="datatable display" data-order="0">
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
            <td data-sort="<?= htmlspecialchars($log['created_at']) ?>" style="white-space: nowrap; font-size: 0.85rem;"><?= toJalaliDateFormatted($log['created_at']) ?> - <?= toPersianDigits(substr($log['created_at'], 11, 5)) ?></td>
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
<?php panel_layout_end(); ?>
<?php panel_layout_end(); ?>
