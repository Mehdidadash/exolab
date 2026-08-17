<?php
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: change_password.php');
    exit;
}

$user = current_user();
$currentPassword = $_POST['current_password'] ?? '';
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

if (!$user || !$currentPassword || !$newPassword || $newPassword !== $confirmPassword) {
    header('Location: change_password.php?error=invalid');
    exit;
}

if (!password_verify($currentPassword, $user['password_hash'])) {
    header('Location: change_password.php?error=wrong_password');
    exit;
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);
$stmt = db()->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?');
$stmt->execute([$hash, $user['id']]);

audit_log_save('user', $user['id'], 'تغییر رمز عبور');
header('Location: dashboard.php?message=password_changed');
exit;
