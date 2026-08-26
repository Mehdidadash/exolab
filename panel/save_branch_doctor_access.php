<?php
// panel/save_branch_doctor_access.php
// Grant/revoke a doctor's financial access to another branch.
// The OWNING branch grants (or revokes) visibility of one of its doctors
// to another branch (via branch_doctor_access).
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

$doctorId = !empty($_POST['doctor_id']) ? (int) $_POST['doctor_id'] : 0;
$grantToBranch = !empty($_POST['grant_to_branch_id']) ? (int) $_POST['grant_to_branch_id'] : 0;
$action = $_POST['action'] ?? '';

if ($doctorId <= 0 || $grantToBranch <= 0) {
    die('invalid');
}

if ($action === 'revoke') {
    $stmt = db()->prepare('DELETE FROM branch_doctor_access WHERE branch_id = ? AND doctor_id = ?');
    $stmt->execute([$grantToBranch, $doctorId]);
} else {
    // Insert (ignore if exists)
    $stmt = db()->prepare('INSERT IGNORE INTO branch_doctor_access (branch_id, doctor_id, created_at) VALUES (?, ?, NOW())');
    $stmt->execute([$grantToBranch, $doctorId]);
}

header('Location: branches.php');
exit;
