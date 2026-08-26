<?php
// panel/save_doctor_branch.php
// Assign a doctor to a branch (ownership) or clear. Root admin only.
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
$branchId = isset($_POST['branch_id']) && $_POST['branch_id'] !== '' ? (int) $_POST['branch_id'] : null;
if ($doctorId <= 0) {
    die('invalid doctor');
}

$stmt = db()->prepare('UPDATE users SET branch_id = ? WHERE id = ? AND role = "doctor"');
$stmt->execute([$branchId, $doctorId]);

header('Location: branches.php');
exit;
