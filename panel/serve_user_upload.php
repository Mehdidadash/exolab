<?php
// panel/serve_user_upload.php
// Inline serve of a library file (user_uploads) so it can be previewed on a case page
// (images open in the browser; models/archives are streamed to the 3D/zip viewers).
require_once __DIR__ . '/auth.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit;
}

$stmt = db()->prepare('SELECT * FROM user_uploads WHERE id = ?');
$stmt->execute([$id]);
$up = $stmt->fetch();

if (!$up || !userCanViewUserUpload($up)) {
    http_response_code(403);
    die('دسترسی غیرمجاز');
}

$path = resolve_upload_path('user/' . $up['filename']);
if (!file_exists($path)) {
    http_response_code(404);
    die('فایل یافت نشد');
}

$ext = strtolower(pathinfo($up['original_name'], PATHINFO_EXTENSION));
$types = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
    'stl' => 'model/stl', 'ply' => 'application/octet-stream',
    'zip' => 'application/zip', 'rar' => 'application/x-rar-compressed',
    'txt' => 'text/plain', 'pdf' => 'application/pdf',
];
$mime = $types[$ext] ?? ($up['mime'] ?: 'application/octet-stream');
$isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);

header('Content-Type: ' . $mime);
$safeName = str_replace(["\r", "\n", '"'], '', $up['original_name']);
header('Content-Disposition: ' . ($isImage ? 'inline' : 'attachment') . '; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
header('Pragma: public');
readfile($path);
exit;
