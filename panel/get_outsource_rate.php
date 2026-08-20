<?php
// panel/get_outsource_rate.php
// Returns the dedicated outsource rate for a lab+service pair (or null).
require_once __DIR__ . '/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$labId = isset($_GET['lab_id']) ? (int) $_GET['lab_id'] : 0;
$serviceId = isset($_GET['service_id']) ? (int) $_GET['service_id'] : 0;

if ($labId <= 0 || $serviceId <= 0) {
    echo json_encode(['rate' => null]);
    exit;
}

$rate = getOutsourceRate($labId, $serviceId);
echo json_encode(['rate' => $rate !== null ? (float) $rate : null]);
