<?php
// panel/delete_fixture_type.php
// حذفِ یک نوع فیکسچر — اگر در کیسی استفاده شده باشد حذف نمی‌شود.
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

if (!canManageFixtureTypes()) { http_response_code(403); die('دسترسی غیرمجاز'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: fixture_types.php'); exit; }

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: fixture_types.php'); exit; }

if (!deleteFixtureType($id)) {
    header('Location: fixture_types.php?error=in_use');
    exit;
}

audit_log_delete('fixture_type', $id, 'نوع فیکسچر');
header('Location: fixture_types.php?msg=deleted');
exit;
