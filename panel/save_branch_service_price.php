<?php
// panel/save_branch_service_price.php
// Set (or clear) the current branch's custom price for a service.
require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: branch_prices.php');
    exit;
}
if (currentBranchId() === null) {
    // Root admin manages the shared catalog, not branch overrides here.
    header('Location: branch_prices.php');
    exit;
}

$serviceId = !empty($_POST['service_id']) ? (int) $_POST['service_id'] : 0;
$price = isset($_POST['price']) && $_POST['price'] !== '' ? (float) $_POST['price'] : null;

if ($serviceId <= 0) {
    header('Location: branch_prices.php');
    exit;
}
setBranchServiceCustomPrice($serviceId, $price);
header('Location: branch_prices.php');
exit;
