<?php
// panel/delete_branch.php
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
if ($id === 1) {
    die('شعبه اصلی قابل حذف نیست');
}

// Detach references to this branch → move to root branch (1)
db()->prepare('UPDATE cases SET branch_id = 1 WHERE branch_id = ?')->execute([$id]);
db()->prepare('UPDATE cases SET source_branch_id = NULL WHERE source_branch_id = ?')->execute([$id]);
db()->prepare('UPDATE users SET branch_id = NULL WHERE branch_id = ?')->execute([$id]);
db()->prepare('UPDATE site_prices SET branch_id = 1 WHERE branch_id = ?')->execute([$id]);
db()->prepare('UPDATE doctor_price_overrides SET branch_id = 1 WHERE branch_id = ?')->execute([$id]);
db()->prepare('UPDATE doctor_invoices SET branch_id = 1 WHERE branch_id = ?')->execute([$id]);
db()->prepare('UPDATE doctor_payments SET branch_id = 1 WHERE branch_id = ?')->execute([$id]);
db()->prepare('UPDATE outsource_invoices SET branch_id = 1 WHERE branch_id = ?')->execute([$id]);
db()->prepare('UPDATE designer_invoices SET branch_id = 1 WHERE branch_id = ?')->execute([$id]);
db()->prepare('UPDATE user_uploads SET branch_id = 1 WHERE branch_id = ?')->execute([$id]);
db()->prepare('UPDATE branches SET parent_id = NULL WHERE parent_id = ?')->execute([$id]);
db()->prepare('DELETE FROM branches WHERE id = ?')->execute([$id]);

header('Location: branches.php');
exit;
