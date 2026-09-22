<?php
// panel/unlink_case_file_from_case.php
// حذف اتصالِ یک فایل از یک کیس (فایل و کیسِ مبدأ دست‌نخورده می‌مانند).
// AJAX → JSON.  POST: case_id + file_id
require_once __DIR__ . '/auth.php';
require_login();

function lcfu_json(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lcfu_json(['success' => false, 'message' => 'method'], 405);
}

$sessionToken = $_SESSION['_csrf_token'] ?? '';
$token = $_POST['_csrf_token'] ?? '';
if ($token === '') $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($token === '' || empty($sessionToken) || !hash_equals($sessionToken, $token)) {
    lcfu_json(['success' => false, 'message' => 'CSRF token نامعتبر. صفحه را رفرش کنید.'], 403);
}

$user = current_user();
$caseId = (int) ($_POST['case_id'] ?? 0);
$fileId = (int) ($_POST['file_id'] ?? 0);
if ($caseId <= 0 || $fileId <= 0) {
    lcfu_json(['success' => false, 'message' => 'پارامتر ناقص.'], 400);
}

$canUnlink = is_admin()
    || has_permission('upload_files')
    || has_permission('upload_design_files')
    || has_permission('edit_cases')
    || in_array($user['role'] ?? '', ['designer', 'doctor'], true);
if (!$canUnlink || !userCanViewCaseId($caseId, $user)) {
    lcfu_json(['success' => false, 'message' => 'دسترسی ندارید.'], 403);
}

// فقط کاربری که خودش اتصال را ساخته یا مدیر، یا آپلودکنندهٔ فایل
$st = db()->prepare('SELECT * FROM case_file_case_links WHERE case_file_id = ? AND case_id = ? LIMIT 1');
$st->execute([$fileId, $caseId]);
$link = $st->fetch();
if (!$link) {
    lcfu_json(['success' => true, 'message' => 'اتصالی وجود نداشت.']);
}

$file = getCaseFileRow($fileId);
$ownsUpload = $file && !empty($file['uploader_id']) && (int) $file['uploader_id'] === (int) $user['id'];
$isLinker = (int) ($link['created_by'] ?? 0) === (int) $user['id'];
if (!is_admin() && !$ownsUpload && !$isLinker && !has_permission('edit_cases')) {
    lcfu_json(['success' => false, 'message' => 'فقط سازندهٔ اتصال، آپلودکننده یا مدیر می‌تواند آن را بردارد.'], 403);
}

unlinkCaseFileFromCase($fileId, $caseId);
log_case_activity($caseId, 'file_unlink', 'حذف اتصال فایل #' . $fileId);
lcfu_json(['success' => true, 'message' => 'اتصال حذف شد.']);
