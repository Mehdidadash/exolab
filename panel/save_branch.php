<?php
// panel/save_branch.php
require_once __DIR__ . '/auth.php';
require_root_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('method');
}
if (!verify_csrf()) {
    http_response_code(403);
    die('CSRF invalid');
}

$id = !empty($_POST['id']) ? (int) $_POST['id'] : 0;
$name = trim($_POST['name'] ?? '');
$code = trim($_POST['code'] ?? '');
$code = $code !== '' ? $code : null;
$parentId = !empty($_POST['parent_id']) ? (int) $_POST['parent_id'] : null;
$ownerUserId = !empty($_POST['owner_user_id']) ? (int) $_POST['owner_user_id'] : null;
$active = !empty($_POST['active']) ? 1 : 0;

if ($name === '') {
    die('نام شعبه الزامی است');
}
// Prevent cycles: a branch cannot be its own parent
if ($parentId === $id) {
    $parentId = null;
}

if ($id) {
    $stmt = db()->prepare('UPDATE branches SET name = ?, code = ?, parent_id = ?, owner_user_id = ?, active = ? WHERE id = ?');
    $stmt->execute([$name, $code, $parentId, $ownerUserId, $active, $id]);
    $bid = $id;
} else {
    $stmt = db()->prepare('INSERT INTO branches (name, code, parent_id, owner_user_id, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$name, $code, $parentId, $ownerUserId, $active]);
    $bid = (int) db()->lastInsertId();
}

// Keep owner's branch_id in sync (only if they don't already belong to another branch)
if ($ownerUserId) {
    $cur = db()->prepare('SELECT branch_id FROM users WHERE id = ?');
    $cur->execute([$ownerUserId]);
    $curBid = $cur->fetchColumn();
    if (!$curBid) {
        $upd = db()->prepare('UPDATE users SET branch_id = ? WHERE id = ?');
        $upd->execute([$bid, $ownerUserId]);
    }
}

header('Location: branches.php');
exit;
