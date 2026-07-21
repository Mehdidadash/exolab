<?php
// panel/delete_role.php
require_once __DIR__ . '/auth.php';
require_role('admin');
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method']);
    exit;
}

$id = !empty($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_id']);
    exit;
}

$deleted = deleteRole($id);
if (!$deleted) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'in_use', 'message' => 'این نقش توسط کاربر(ها) استفاده می‌شود و قابل حذف نیست.']);
    exit;
}

audit_log_delete('role', $id, 'نقش');
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true]);
exit;
