<?php
// panel/download_user_upload.php
// Serve a user's own uploaded file for download.
require_once __DIR__ . '/auth.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$user = current_user();

$stmt = db()->prepare('SELECT * FROM user_uploads WHERE id = ?');
$stmt->execute([$id]);
$up = $stmt->fetch();

// Owners, admins, and designers (shared file area) may download
if (!$up || ((int) $up['user_id'] !== (int) $user['id'] && !has_role('admin') && $user['role'] !== 'designer')) {
    http_response_code(403);
    die('دسترسی غیرمجاز');
}

$path = resolve_upload_path('user/' . $up['filename']);
if (!file_exists($path)) {
    http_response_code(404);
    die('فایل یافت نشد');
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $up['original_name'] . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=0');
header('Pragma: public');
readfile($path);
exit;
