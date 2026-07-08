<?php
// panel\delete_case.php

require_once __DIR__ . '/auth.php';
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

$id = $_POST['id'] ?? null;
$token = $_POST['_csrf_token'] ?? '';

if (empty($id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_id']);
    exit;
}

if (empty($_SESSION['_csrf_token']) || $token !== $_SESSION['_csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'csrf_invalid']);
    exit;
}

try {
    $stmt = db()->prepare('DELETE FROM cases WHERE id = ?');
    $stmt->execute([(int)$id]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}
