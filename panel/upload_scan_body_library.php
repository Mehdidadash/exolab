<?php
// panel/upload_scan_body_library.php
// بارگذاری فایلِ کتابخانه برای یک نوع اسکن‌بادی. دسترسی: فقط مدیر کل و مدیران شعبه.
require_once __DIR__ . '/auth.php';
require_login();
if (!canManageScanBodyTypes()) { http_response_code(403); die('دسترسی غیرمجاز'); }
require_csrf();

$back = 'scan_body_types.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $back);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$type = getScanBodyType($id);
if (!$type) {
    header('Location: ' . $back . '?error=notfound');
    exit;
}

if (!isset($_FILES['library_file']) || !is_array($_FILES['library_file'])) {
    header('Location: ' . $back . '?error=nofile');
    exit;
}

$f = $_FILES['library_file'];
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    header('Location: ' . $back . '?error=nofile');
    exit;
}
if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    header('Location: ' . $back . '?error=upload');
    exit;
}
// حداکثر ۲۰۰ مگابایت (کتابخانه‌های اسکن‌بادی می‌توانند مدل سه‌بعدی باشند)
if ((int) $f['size'] > 200 * 1024 * 1024) {
    header('Location: ' . $back . '?error=toobig');
    exit;
}

$origName = (string) ($f['name'] ?? 'library');
$ext = strtolower((string) pathinfo($origName, PATHINFO_EXTENSION));
$allowed = ['zip', 'rar', '7z', 'stl', 'ply', 'obj', '3mf', 'stp', 'step', 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
if ($ext !== '' && !in_array($ext, $allowed, true)) {
    header('Location: ' . $back . '?error=badext');
    exit;
}

$dir = scanBodyLibraryDir();
$safeBase = 'sb_' . $id . '_' . date('Ymd_His');
$storedName = $safeBase . ($ext !== '' ? '.' . $ext : '');
$dest = $dir . DIRECTORY_SEPARATOR . $storedName;

if (!move_uploaded_file((string) $f['tmp_name'], $dest)) {
    header('Location: ' . $back . '?error=upload');
    exit;
}
@chmod($dest, 0644);

// فایلِ قبلی (اگر روی سرور بود) پاک شود
$oldRel = trim((string) ($type['library_path'] ?? ''));
if ($oldRel !== '') {
    $oldAbs = scanBodyLibraryAbsolutePath($oldRel);
    if ($oldAbs && is_file($oldAbs) && $oldAbs !== $dest) { @unlink($oldAbs); }
}

$displayName = trim((string) ($_POST['library_name'] ?? ''));
if ($displayName === '') $displayName = $origName;

db()->prepare('UPDATE scan_body_types SET library_path = ?, library_name = ? WHERE id = ?')
    ->execute([$storedName, mb_substr($displayName, 0, 255, 'UTF-8'), $id]);

audit_log_save('scan_body_type', $id, 'بارگذاری کتابخانه اسکن‌بادی');
header('Location: ' . $back . '?msg=library_saved');
exit;
