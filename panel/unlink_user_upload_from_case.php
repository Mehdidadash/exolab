<?php
// panel/unlink_user_upload_from_case.php
// Detach a library file from a case (the physical file stays in the library).
// Allowed: root/branch admins, or the file's uploader.
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

$uploadId = (int) ($_POST['upload_id'] ?? 0);
$caseId = (int) ($_POST['case_id'] ?? 0);
$user = current_user();

$st = db()->prepare('SELECT * FROM user_uploads WHERE id = ?');
$st->execute([$uploadId]);
$up = $st->fetch();

$isAdmin = is_admin();
$isUploader = $up && (int) $up['user_id'] === (int) $user['id'];
if (!$up || (!$isAdmin && !$isUploader)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'شما اجازهٔ حذف اتصال این فایل را ندارید.']);
    exit;
}

unlinkUserUploadFromCase($uploadId, $caseId);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true]);
