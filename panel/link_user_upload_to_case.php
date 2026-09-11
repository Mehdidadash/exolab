<?php
// panel/link_user_upload_to_case.php
// Link a library file (user_uploads) to an existing case (shared across cases).
// AJAX endpoint → JSON. Allowed: root/branch admins, or the file's uploader (to a case they can view).
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
    echo json_encode(['success' => false, 'message' => 'شما اجازهٔ اتصال فایل به کیس را ندارید.']);
    exit;
}
if ($caseId <= 0 || !userCanViewCaseId($caseId, $user)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'دسترسی به کیس موردنظر ندارید.']);
    exit;
}

linkUserUploadToCase($uploadId, $caseId);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true]);
