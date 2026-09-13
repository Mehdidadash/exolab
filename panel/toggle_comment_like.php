<?php
// panel/toggle_comment_like.php
// لایک/برداشتن‌لایک یک کامنت. پاسخ JSON.
require_once __DIR__ . '/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'method']);
    exit;
}

$sessionToken = $_SESSION['_csrf_token'] ?? '';
$token = $_POST['_csrf_token'] ?? '';
if ($token === '') $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($token === '' || empty($sessionToken) || !hash_equals($sessionToken, $token)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'csrf_invalid']);
    exit;
}

$commentId = (int) ($_POST['comment_id'] ?? 0);
$user = current_user();

$st = db()->prepare('SELECT id, entity_type, entity_id FROM entity_comments WHERE id = ? LIMIT 1');
$st->execute([$commentId]);
$comment = $st->fetch();
if (!$comment) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'not_found']);
    exit;
}

// فقط کسانی که به کیسِ کامنت دسترسی دارند می‌توانند لایک کنند.
if (($comment['entity_type'] ?? '') === 'case' && !userCanViewCaseId((int) $comment['entity_id'], $user)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'forbidden']);
    exit;
}

$res = toggleCommentLike($commentId, (int) $user['id']);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'count' => (int) $res['count'],
    'liked' => (bool) $res['liked'],
    'names' => $res['names'],
], JSON_UNESCAPED_UNICODE);
