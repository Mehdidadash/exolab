<?php
// panel/save_role.php
require_once __DIR__ . '/auth.php';
require_role('admin');
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: roles.php');
    exit;
}

$id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
$name = trim($_POST['name'] ?? '');
$label = trim($_POST['label'] ?? '');
$permissions = $_POST['permissions'] ?? [];

if (empty($name) || empty($label)) {
    header('Location: role_form.php?error=missing' . ($id ? '&id=' . $id : ''));
    exit;
}

// Check uniqueness
if (!$id) {
    $check = db()->prepare('SELECT COUNT(*) FROM roles WHERE name = ?');
    $check->execute([$name]);
    if ((int) $check->fetchColumn() > 0) {
        header('Location: role_form.php?error=duplicate');
        exit;
    }
}

$savedId = saveRole([
    'id' => $id,
    'name' => $name,
    'label' => $label,
    'permissions' => $permissions,
]);

audit_log_save('role', $savedId, 'نقش');
header('Location: roles.php');
exit;
