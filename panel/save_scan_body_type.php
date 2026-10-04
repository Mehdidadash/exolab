<?php
// panel/save_scan_body_type.php
// ذخیره (ایجاد/ویرایش) یک نوع اسکن‌بادی.
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

if (!is_admin()) { http_response_code(403); die('دسترسی غیرمجاز'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: scan_body_types.php'); exit; }

$id = (int) ($_POST['id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
$sortOrder = (int) ($_POST['sort_order'] ?? 0);
$active = ($_POST['active'] ?? '1') === '1' ? 1 : 0;
$libraryUrl  = trim((string) ($_POST['library_url'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));

if ($name === '') {
    header('Location: scan_body_types.php?error=name' . ($id ? '&edit=' . $id : ''));
    exit;
}

try {
    saveScanBodyType($name, $sortOrder, $active, $id ?: null, [
        'library_url' => $libraryUrl,
        'description' => $description,
    ]);
} catch (InvalidArgumentException $e) {
    $code = $e->getMessage() === 'bad_url' ? 'bad_url' : 'name';
    header('Location: scan_body_types.php?error=' . $code . ($id ? '&edit=' . $id : ''));
    exit;
} catch (RuntimeException $e) {
    header('Location: scan_body_types.php?error=duplicate' . ($id ? '&edit=' . $id : ''));
    exit;
}

audit_log_save('scan_body_type', $id, 'نوع اسکن‌بادی');
header('Location: scan_body_types.php?msg=saved');
exit;
