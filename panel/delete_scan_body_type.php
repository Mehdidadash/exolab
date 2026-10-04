<?php
// panel/delete_scan_body_type.php
// حذفِ یک نوع اسکن‌بادی — اگر در نوبتی استفاده شده باشد حذف نمی‌شود.
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

if (!is_admin()) { http_response_code(403); die('دسترسی غیرمجاز'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: scan_body_types.php'); exit; }

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: scan_body_types.php'); exit; }

if (!deleteScanBodyType($id)) {
    header('Location: scan_body_types.php?error=in_use');
    exit;
}

audit_log_delete('scan_body_type', $id, 'نوع اسکن‌بادی');
header('Location: scan_body_types.php?msg=deleted');
exit;
