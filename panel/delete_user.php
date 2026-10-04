<?php
// panel/delete_user.php
// حذف کاربر. مدیر کل: همه (به‌جز ادمین اصلی) | مدیر شعبه: فقط کاربرانِ شعبهٔ خودش.
require_once __DIR__ . '/auth.php';
require_login();
if (!can_manage_users()) { http_response_code(403); die('دسترسی غیرمجاز'); }
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
    header('Location: users.php');
    exit;
}

$id = (int) $_POST['id'];
if ($id <= 1) {
    // Cannot delete main admin
    header('Location: users.php?error=cannot_delete_admin');
    exit;
}

// مدیر شعبه فقط کاربرانِ شعبهٔ خودش را می‌تواند حذف کند (و هرگز ادمین/مدیر دیگر).
if (!is_root_admin() && !can_manage_target_user(null, $id)) {
    header('Location: users.php?error=not_allowed');
    exit;
}

audit_log_delete('user', $id, 'کاربر');
$stmt = db()->prepare('DELETE FROM users WHERE id = ?');
$stmt->execute([$id]);
header('Location: users.php?msg=deleted');
exit;
