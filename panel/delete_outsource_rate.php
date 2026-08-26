<?php
// panel/delete_outsource_rate.php
require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: outsource_rates.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if ($id) {
    deleteOutsourceRate($id);
}
header('Location: outsource_rates.php');
exit;
