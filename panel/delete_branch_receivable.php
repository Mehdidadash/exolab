<?php
// panel/delete_branch_receivable.php
require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();

$id = !empty($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id > 0) {
    deleteBranchReceivable($id);
}
header('Location: branch_receivables.php');
exit;
