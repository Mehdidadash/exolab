<?php
// panel/upload_user_file.php
// AJAX handler for personal uploads (doctor / designer / any user).
require_once __DIR__ . '/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'method']);
    exit;
}

// CSRF via POST field or header
$sessionToken = $_SESSION['_csrf_token'] ?? '';
$token = $_POST['_csrf_token'] ?? '';
if (empty($token)) {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
}
if (empty($sessionToken) || !hash_equals($sessionToken, $token)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'csrf_invalid']);
    exit;
}

$user = current_user();
$caseId = !empty($_POST['case_id']) ? (int) $_POST['case_id'] : null;

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'فایلی انتخاب نشده است.']);
    exit;
}

$allowed = ['zip', 'rar', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'stl', 'ply', 'stp', 'step', 'obj', '3mf'];
$ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowed)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'فرمت فایل مجاز نیست.']);
    exit;
}

// If a case is selected, verify the user can access that case
if ($caseId) {
    $ok = false;
    if ($user['role'] === 'doctor') {
        $chk = db()->prepare('SELECT id FROM cases WHERE id = ? AND doctor_id = ?');
        $chk->execute([$caseId, $user['id']]);
        $ok = (bool) $chk->fetch();
    } elseif ($user['role'] === 'lab' || $user['role'] === 'outsource_lab' || $user['role'] === 'customer_lab' || $user['role'] === 'partner_lab') {
        $chk = db()->prepare('SELECT id FROM cases WHERE id = ? AND lab_id = ?');
        $chk->execute([$caseId, $user['id']]);
        $ok = (bool) $chk->fetch();
    } elseif ($user['role'] === 'clinic') {
        $clinicScope = getClinicScope('c');
        $chk = db()->prepare('SELECT id FROM cases c WHERE c.id = ? AND ' . $clinicScope['sql']);
        $chk->execute(array_merge([$caseId], $clinicScope['params']));
        $ok = (bool) $chk->fetch();
    } elseif (has_permission('view_all_cases')) {
        $chk = db()->prepare('SELECT id FROM cases WHERE id = ?');
        $chk->execute([$caseId]);
        $ok = (bool) $chk->fetch();
    }
    if (!$ok) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'دسترسی به کیس موردنظر ندارید.']);
        exit;
    }
}

$uploadDir = __DIR__ . '/../assets/uploads/user/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

$safe = bin2hex(random_bytes(8)) . '.' . $ext;
$dest = $uploadDir . $safe;
$tmp = $_FILES['file']['tmp_name'];
$size = (int) $_FILES['file']['size'];
$mime = $_FILES['file']['type'] ?? '';
$orig = $_FILES['file']['name'];

if (!move_uploaded_file($tmp, $dest)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'خطا در ذخیره فایل.']);
    exit;
}
@chmod($dest, 0644);

$stmt = db()->prepare('INSERT INTO user_uploads (user_id, case_id, filename, original_name, mime, size, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
$stmt->execute([$user['id'], $caseId, $safe, $orig, $mime, $size]);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'id' => (int) db()->lastInsertId()]);
