<?php
// panel\rename_case_file.php

require_once __DIR__ . '/auth.php';
require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'error'=>'method']);
    exit;
}

$id = !empty($_POST['id']) ? (int) $_POST['id'] : 0;
$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
$description = $description !== '' ? $description : null;
$token = $_POST['_csrf_token'] ?? '';
if (empty($token)) {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
}
if (empty($_SESSION['_csrf_token']) || $token !== $_SESSION['_csrf_token']) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'csrf']);
    exit;
}
if ($id <= 0 || $name === '') {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'invalid']);
    exit;
}
try {
    $stmt = db()->prepare('SELECT * FROM case_files WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success'=>false,'error'=>'notfound']);
        exit;
    }
    $upd = db()->prepare('UPDATE case_files SET original_name = ?, description = ? WHERE id = ?');
    $upd->execute([$name, $description, $id]);
    echo json_encode(['success'=>true]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'server']);
    exit;
}
