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
$folderRaw = (string) ($_POST['folder'] ?? '');
$folder = sanitizeRelPath($folderRaw);
$scopeUserId = !empty($_POST['user']) ? (int) $_POST['user'] : 0;
$user = current_user();
$isAdmin = is_admin();

if ($caseId <= 0 || !userCanViewCaseId($caseId, $user)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'دسترسی به کیس موردنظر ندارید.']);
    exit;
}

// ─── حالت «پوشه»: همهٔ فایل‌های یک پوشه به کیس الصاق می‌شوند ───
// (در کتابخانهٔ مشترک، user مشخص می‌کند فایل‌های کدام آپلودکننده مدنظر است)
if ($folderRaw !== '' && $folder !== null) {
    $root = relPathRoot($folder)['root'] ?? null;
    if ($root === null) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'نام پوشه معتبر نیست.']);
        exit;
    }
    $sql = 'SELECT id, user_id FROM user_uploads WHERE (rel_path = ? OR rel_path LIKE ?)';
    $params = [$root, $root . '/%'];
    if ($scopeUserId > 0) {
        $sql .= ' AND user_id = ?';
        $params[] = $scopeUserId;
    }
    $rows = [];
    try {
        $st = db()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }
    $linked = 0;
    foreach ($rows as $r) {
        // مدیر همه را می‌تواند؛ بقیه فقط فایل‌های خودشان
        if (!$isAdmin && (int) $r['user_id'] !== (int) $user['id']) {
            continue;
        }
        linkUserUploadToCase((int) $r['id'], $caseId);
        $linked++;
    }
    header('Content-Type: application/json; charset=utf-8');
    if ($linked === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'فایلی برای الصاق در این پوشه پیدا نشد (یا اجازهٔ آن را ندارید).']);
        exit;
    }
    echo json_encode(['success' => true, 'linked' => $linked]);
    exit;
}

$st = db()->prepare('SELECT * FROM user_uploads WHERE id = ?');
$st->execute([$uploadId]);
$up = $st->fetch();

$isUploader = $up && (int) $up['user_id'] === (int) $user['id'];
if (!$up || (!$isAdmin && !$isUploader)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'شما اجازهٔ اتصال فایل به کیس را ندارید.']);
    exit;
}

linkUserUploadToCase($uploadId, $caseId);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true]);
