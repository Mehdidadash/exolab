<?php
// panel/delete_case_folder.php
// حذف یک «پوشهٔ آپلودشده» از یک کیس — یعنی حذف همهٔ فایل‌های داخل آن پوشه
// (و زیرپوشه‌هایش). خودِ پوشه روی دیسک وجود ندارد (فایل‌ها تخت ذخیره می‌شوند)؛
// پس حذف یعنی پاک‌کردن ردیف‌ها + فایل‌های فیزیکی.
//
// ورودی POST:  case_id  — شناسهٔ کیس
//              folder   — نام پوشهٔ ریشه (بخش اول rel_path)
//              _csrf_token یا هدر X-CSRF-Token
//
// دسترسی: مدیر، یا کسی که مجوز edit_cases/upload_files دارد و کیس را می‌بیند.
require_once __DIR__ . '/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'method']);
    exit;
}

// ─── CSRF ───
$token = $_POST['_csrf_token'] ?? '';
if (empty($token)) {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
}
if (empty($_SESSION['_csrf_token']) || !hash_equals((string) $_SESSION['_csrf_token'], (string) $token)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'csrf']);
    exit;
}

$user   = current_user();
$caseId = !empty($_POST['case_id']) ? (int) $_POST['case_id'] : 0;
$folder = sanitizeRelPath((string) ($_POST['folder'] ?? ''));

if ($caseId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'invalid_case']);
    exit;
}
if ($folder === null) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'invalid_folder']);
    exit;
}
$root = relPathRoot($folder)['root'] ?? null;
if ($root === null) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'invalid_folder']);
    exit;
}

// ─── دسترسی ───
$canManage = is_admin()
    || has_permission('edit_cases')
    || has_permission('upload_files')
    || has_permission('upload_design_files');
if (!$canManage || !userCanViewCaseId($caseId, $user)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'forbidden', 'message' => 'دسترسی غیرمجاز.']);
    exit;
}

try {
    // فایل‌های همین پوشه (و زیرپوشه‌هایش)
    $st = db()->prepare(
        'SELECT id, filename FROM case_files
         WHERE case_id = ? AND (rel_path = ? OR rel_path LIKE ?)'
    );
    $st->execute([$caseId, $root, $root . '/%']);
    $rows = $st->fetchAll();

    if (empty($rows)) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'notfound', 'message' => 'فایلی در این پوشه یافت نشد.']);
        exit;
    }

    $del = db()->prepare('DELETE FROM case_files WHERE id = ?');
    $deleted = 0;
    foreach ($rows as $r) {
        // فایل فیزیکی (هر دو مکان: ریشهٔ جدید و مکان قدیمی)
        $path = resolve_upload_path('cases/' . $caseId . '/' . $r['filename']);
        if (is_file($path)) @unlink($path);
        $legacy = legacy_uploads_path('cases/' . $caseId . '/' . $r['filename']);
        if ($legacy !== $path && is_file($legacy)) @unlink($legacy);

        $del->execute([$r['id']]);
        $deleted++;
    }

    log_case_activity($caseId, 'file_delete', 'حذف پوشهٔ «' . $root . '» با ' . $deleted . ' فایل');

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'deleted' => $deleted,
        'folder'  => $root,
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    error_log('delete_case_folder: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'server', 'message' => 'خطای سرور.']);
    exit;
}
