<?php
// panel/delete_user.php
require_once __DIR__ . '/auth.php';
require_role('admin');
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

audit_log_delete('user', $id, 'کاربر');
$stmt = db()->prepare('DELETE FROM users WHERE id = ?');
$stmt->execute([$id]);
header('Location: users.php');
exit;
