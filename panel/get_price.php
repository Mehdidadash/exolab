<?php
// panel/get_price.php
require_once __DIR__ . '/auth.php';
require_login();

$doctor_id = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;
$service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;

if ($doctor_id <= 0 || $service_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['price' => null]);
    exit;
}

$price = getApplicablePrice($doctor_id, $service_id);
header('Content-Type: application/json');
echo json_encode(['price' => $price ? number_format($price, 2, '.', '') : null]);