<?php
// panel/reorder_case_statuses.php
// ذخیرهٔ ترتیب جدید وضعیت‌ها (پس از drag & drop). ورودی: ids به ترتیب.
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

if (!is_admin()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'forbidden']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'method']);
    exit;
}

$ids = $_POST['ids'] ?? null;
if (is_string($ids)) {
    $decoded = json_decode($ids, true);
    $ids = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $ids)));
}
if (!is_array($ids)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'invalid']);
    exit;
}

$upd = db()->prepare('UPDATE case_statuses SET sort_order = ? WHERE id = ?');
$i = 0;
foreach ($ids as $id) {
    $id = (int) $id;
    if ($id <= 0) continue;
    $i++;
    $upd->execute([$i, $id]);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'count' => $i], JSON_UNESCAPED_UNICODE);
