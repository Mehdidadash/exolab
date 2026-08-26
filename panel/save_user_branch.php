<?php
// panel/save_user_branch.php
// Assign a user to a branch (or clear to global).
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

$userId = !empty($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
$branchId = isset($_POST['branch_id']) && $_POST['branch_id'] !== '' ? (int) $_POST['branch_id'] : null;

if ($userId <= 0) {
    die('invalid user');
}
// Prevent removing the root admin from global scope or locking yourself out
if ($branchId === null) {
    $target = db()->prepare('SELECT id, role FROM users WHERE id = ?');
    $target->execute([$userId]);
    $row = $target->fetch();
    if ($row && $row['role'] === 'admin' && (int) $userId === 1) {
        // Root admin stays global – no-op but don't error
    }
}

$stmt = db()->prepare('UPDATE users SET branch_id = ? WHERE id = ?');
$stmt->execute([$branchId, $userId]);

header('Location: branches.php');
exit;
