<?php
// panel/save_case_status.php
// ذخیره (ایجاد/ویرایش) یک وضعیت کیس: نام، آیکون، رنگ و ترتیب.
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

if (!is_admin()) { http_response_code(403); die('دسترسی غیرمجاز'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: case_statuses.php'); exit; }

$id = (int) ($_POST['id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
$icon = trim((string) ($_POST['icon'] ?? ''));
$color = trim((string) ($_POST['color'] ?? ''));
$sortOrder = (int) ($_POST['sort_order'] ?? 0);

$icon = $icon !== '' ? $icon : null;
$color = $color !== '' ? $color : null;
$isAbnormal = !empty($_POST['is_abnormal']) ? 1 : 0;

if ($name === '') {
    header('Location: case_statuses.php?error=name');
    exit;
}

// یکتا بودن نام
$chk = db()->prepare('SELECT id FROM case_statuses WHERE name = ? AND id <> ? LIMIT 1');
$chk->execute([$name, $id]);
if ($chk->fetchColumn()) {
    header('Location: case_statuses.php?error=duplicate');
    exit;
}

if ($id > 0) {
    db()->prepare('UPDATE case_statuses SET name = ?, icon = ?, color = ?, sort_order = ?, is_abnormal = ? WHERE id = ?')
        ->execute([$name, $icon, $color, $sortOrder, $isAbnormal, $id]);
} else {
    db()->prepare('INSERT INTO case_statuses (name, icon, color, sort_order, is_abnormal, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute([$name, $icon, $color, $sortOrder, $isAbnormal]);
}

header('Location: case_statuses.php?msg=saved');
exit;
