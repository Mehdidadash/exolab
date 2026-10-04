<?php
// panel/delete_scan_body_library.php
// حذفِ فایلِ کتابخانهٔ یک نوع اسکن‌بادی (لینک دست‌نخورده می‌ماند).
// دسترسی: فقط مدیر کل و مدیران شعبه.
require_once __DIR__ . '/auth.php';
require_login();
if (!canManageScanBodyTypes()) { http_response_code(403); die('دسترسی غیرمجاز'); }
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: scan_body_types.php'); exit; }

$id = (int) ($_POST['id'] ?? 0);
$type = getScanBodyType($id);
if (!$type) { header('Location: scan_body_types.php?error=notfound'); exit; }

$rel = trim((string) ($type['library_path'] ?? ''));
if ($rel !== '') {
    $abs = scanBodyLibraryAbsolutePath($rel);
    if ($abs && is_file($abs)) { @unlink($abs); }
    db()->prepare('UPDATE scan_body_types SET library_path = NULL, library_name = NULL WHERE id = ?')->execute([$id]);
    audit_log_save('scan_body_type', $id, 'حذف فایل کتابخانه اسکن‌بادی');
}

header('Location: scan_body_types.php?msg=library_deleted');
exit;
