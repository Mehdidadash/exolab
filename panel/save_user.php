<?php
// panel/save_user.php
require_once __DIR__ . '/auth.php';
require_root_admin();
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
$is_designer = !empty($_POST['is_designer']) ? 1 : 0;
$branch_id = isset($_POST['branch_id']) && $_POST['branch_id'] !== '' ? (int) $_POST['branch_id'] : null;
// Prevent moving the root admin (id 1) into a branch (keeps global access)
if ((int) ($id ?? 0) === 1) {
    $branch_id = null;
}

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
        $stmt = db()->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, phone = ?, role = ?, clinic_id = ?, is_designer = ?, active = ?, password_hash = ?, notes = ?, branch_id = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$fullName, $username, $email ?: null, $phone ?: null, $role, $clinic_id, $is_designer, $active, $hash, $notes ?: null, $branch_id, $id]);
    } else {
        $stmt = db()->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, phone = ?, role = ?, clinic_id = ?, is_designer = ?, active = ?, notes = ?, branch_id = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$fullName, $username, $email ?: null, $phone ?: null, $role, $clinic_id, $is_designer, $active, $notes ?: null, $branch_id, $id]);
    }
    // Ensure only one default designer
    // ⚠️ $savedId باید قبل از این بلوک مقدار بگیرد؛ قبلاً «متغیر تعریفنشده» بود و
    // UPDATE با id = NULL اجرا میشد → تیکِ «طراح پیشفرض» ذخیره نمیشد.
    $savedId = (int) $id;
    if (!empty($_POST['is_default_designer'])) {
        db()->exec('UPDATE users SET is_default_designer = 0 WHERE is_designer = 1');
        $upd = db()->prepare('UPDATE users SET is_default_designer = 1 WHERE id = ?');
        $upd->execute([$savedId]);
    } else {
        $upd = db()->prepare('UPDATE users SET is_default_designer = 0 WHERE id = ?');
        $upd->execute([$savedId]);
    }
} else {
    if (empty($password)) {
        header('Location: user_form.php?error=missing_password');
        exit;
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare('INSERT INTO users (username, password_hash, full_name, email, phone, role, clinic_id, is_designer, active, notes, branch_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$username, $hash, $fullName, $email ?: null, $phone ?: null, $role, $clinic_id, $is_designer, $active, $notes ?: null, $branch_id]);
    $savedId = (int) db()->lastInsertId();
    if (!empty($_POST['is_default_designer'])) {
        db()->exec('UPDATE users SET is_default_designer = 0 WHERE is_designer = 1');
        $upd = db()->prepare('UPDATE users SET is_default_designer = 1 WHERE id = ?');
        $upd->execute([$savedId]);
    }
}

audit_log_save('user', $savedId, 'کاربر');
header('Location: users.php');
exit;
