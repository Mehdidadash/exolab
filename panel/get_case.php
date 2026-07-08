<?php
// panel/get_case.php
require_once __DIR__ . '/auth.php';
require_login();

$id = !empty($_GET['id']) ? (int) $_GET['id'] : null;
header('Content-Type: application/json; charset=utf-8');

if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_id']);
    exit;
}

$user = current_user();
if ($user['role'] === 'doctor') {
    $stmt = db()->prepare('SELECT * FROM cases WHERE id = ? AND doctor_id = ?');
    $stmt->execute([$id, $user['id']]);
} else {
    if (!has_permission('view_all_cases')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'forbidden']);
        exit;
    }
    $stmt = db()->prepare('SELECT * FROM cases WHERE id = ?');
    $stmt->execute([$id]);
}

$case = $stmt->fetch();
if (!$case) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit;
}

$case['received_date'] = toJalaliDateFormatted($case['received_date']);
echo json_encode(['success' => true, 'case' => $case], JSON_UNESCAPED_UNICODE);