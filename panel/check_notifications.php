<?php
// panel/check_notifications.php
// JSON endpoint polled by the layout to raise browser notifications.
require_once __DIR__ . '/auth.php';
require_login();

$userId = (int) current_user()['id'];
$lastId = (int) ($_GET['last_id'] ?? 0);

$stmt = db()->prepare('
    SELECT n.*, c.patient_name
    FROM notifications n
    LEFT JOIN cases c ON n.case_id = c.id
    WHERE n.user_id = ? AND n.is_read = 0 AND n.id > ?
    ORDER BY n.id ASC
    LIMIT 5
');
$stmt->execute([$userId, $lastId]);
$rows = $stmt->fetchAll();

$maxId = $lastId;
foreach ($rows as $r) {
    if ((int) $r['id'] > $maxId) $maxId = (int) $r['id'];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'count' => getUnreadNotificationCount($userId),
    'max_id' => $maxId,
    'notifications' => array_map(function ($n) {
        return [
            'id' => (int) $n['id'],
            'title' => $n['title'],
            'message' => $n['message'],
            'type' => $n['type'],
            'case_id' => $n['case_id'] ? (int) $n['case_id'] : null,
            'patient' => $n['patient_name'],
        ];
    }, $rows),
], JSON_UNESCAPED_UNICODE);
