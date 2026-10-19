<?php
// panel/save_fixture_type.php
// ذخیره (ایجاد/ویرایش) یک نوع فیکسچر.
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

if (!canManageFixtureTypes()) { http_response_code(403); die('دسترسی غیرمجاز'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: fixture_types.php'); exit; }

$id = (int) ($_POST['id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
$sortOrder = (int) ($_POST['sort_order'] ?? 0);
$active = ($_POST['active'] ?? '1') === '1' ? 1 : 0;
$description = trim((string) ($_POST['description'] ?? ''));

if ($name === '') {
    header('Location: fixture_types.php?error=name' . ($id ? '&edit=' . $id : ''));
    exit;
}

try {
    saveFixtureType($name, $sortOrder, $active, $id ?: null, [
        'description' => $description,
    ]);
} catch (InvalidArgumentException $e) {
    header('Location: fixture_types.php?error=name' . ($id ? '&edit=' . $id : ''));
    exit;
} catch (RuntimeException $e) {
    header('Location: fixture_types.php?error=duplicate' . ($id ? '&edit=' . $id : ''));
    exit;
}

audit_log_save('fixture_type', $id, 'نوع فیکسچر');
header('Location: fixture_types.php?msg=saved');
exit;
