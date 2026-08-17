<?php
// panel/delete_lab_price_override.php
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

deleteLabPriceOverride($id);
audit_log_delete('lab_price_override', $id, 'قیمت اختصاصی لابراتوار');
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true]);
exit;
