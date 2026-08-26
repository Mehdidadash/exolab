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
if ($rate === null) {
    // Fall back to the lab's SHARED price (registered in the "قیمت‌های اختصاصی"
    // list targeting this lab, or the legacy lab_price_overrides table), so the
    // central branch's rate is visible/usable when another branch outsources to it.
    $ov = db()->prepare('SELECT custom_price FROM doctor_price_overrides WHERE doctor_id = ? AND price_type = "service" AND service_id = ?');
    $ov->execute([$labId, $serviceId]);
    $v = $ov->fetchColumn();
    if ($v === false || $v === null) {
        $lp = db()->prepare('SELECT custom_price FROM lab_price_overrides WHERE lab_id = ? AND service_id = ?');
        $lp->execute([$labId, $serviceId]);
        $v = $lp->fetchColumn();
    }
    if ($v !== false && $v !== null) $rate = (float) $v;
}
echo json_encode(['rate' => $rate !== null ? (float) $rate : null]);
