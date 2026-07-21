<?php
// panel/save_user.php
require_once __DIR__ . '/auth.php';
require_role('admin');
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: users.php');
    exit;
}

$id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
$fullName = trim($_POST['full_name'] ?? '');
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$role = $_POST['role'] ?? 'staff';
$active = isset($_POST['active']) && $_POST['active'] === '1' ? 1 : 0;
$password = $_POST['password'] ?? '';
$notes = trim($_POST['notes'] ?? '');
$clinic_id = !empty($_POST['clinic_id']) ? (int) $_POST['clinic_id'] : null;

if (empty($fullName) || empty($username)) {
    header('Location: user_form.php?error=missing' . ($id ? '&id=' . $id : ''));
    exit;
}

// Check username uniqueness
$check = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND (id IS NULL OR id != ?)');
$check->execute([$username, $id ?? 0]);
if ((int) $check->fetchColumn() > 0) {
    header('Location: user_form.php?error=duplicate_username' . ($id ? '&id=' . $id : ''));
    exit;
}

if ($id) {
    if ($password) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = db()->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, phone = ?, role = ?, clinic_id = ?, active = ?, password_hash = ?, notes = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$fullName, $username, $email ?: null, $phone ?: null, $role, $clinic_id, $active, $hash, $notes ?: null, $id]);
    } else {
        $stmt = db()->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, phone = ?, role = ?, clinic_id = ?, active = ?, notes = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$fullName, $username, $email ?: null, $phone ?: null, $role, $clinic_id, $active, $notes ?: null, $id]);
    }
    $savedId = $id;
} else {
    if (empty($password)) {
        header('Location: user_form.php?error=missing_password');
        exit;
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare('INSERT INTO users (username, password_hash, full_name, email, phone, role, clinic_id, active, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$username, $hash, $fullName, $email ?: null, $phone ?: null, $role, $clinic_id, $active, $notes ?: null]);
    $savedId = (int) db()->lastInsertId();
}

audit_log_save('user', $savedId, 'کاربر');
header('Location: users.php');
exit;
