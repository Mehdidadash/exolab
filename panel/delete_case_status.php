<?php
// panel/delete_case_status.php
// حذف یک وضعیت کیس — اگر کیسی از آن استفاده می‌کند، حذف نمی‌شود.
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

if (!is_admin()) { http_response_code(403); die('دسترسی غیرمجاز'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: case_statuses.php'); exit; }

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: case_statuses.php'); exit; }

$inUse = db()->prepare('SELECT COUNT(*) FROM cases WHERE status_id = ?');
$inUse->execute([$id]);
if ((int) $inUse->fetchColumn() > 0) {
    header('Location: case_statuses.php?error=in_use');
    exit;
}

db()->prepare('DELETE FROM case_statuses WHERE id = ?')->execute([$id]);
header('Location: case_statuses.php?msg=deleted');
exit;
