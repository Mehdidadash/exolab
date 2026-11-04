<?php
// panel/get_price.php
require_once __DIR__ . '/auth.php';
require_login();

$doctor_id = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;
$lab_id = isset($_GET['lab_id']) ? (int)$_GET['lab_id'] : 0;
$service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;
$price_type = isset($_GET['price_type']) ? strtolower((string)$_GET['price_type']) : 'service';

// For lab-origin cases, price is resolved from the lab regardless of the doctor
if ($lab_id > 0) {
    if ($service_id <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['price' => null]);
        exit;
    }
    $price = getLabApplicablePrice($lab_id, $service_id);
    header('Content-Type: application/json');
    echo json_encode(['price' => $price !== null ? round((float) $price) : null]);
    exit;
}

if ($doctor_id <= 0) {
    // ⚠️ پزشک/لابراتوار انتخاب نشده: قبلاً همیشه `price:null` برمی‌گشت و در فرمِ کیس
    // فی خالی می‌ماند (و کیس با فی ۰ ذخیره می‌شد). حالا فیِ عمومی/شعبه برمی‌گردد.
    if ($service_id <= 0 || $price_type === 'design_fee') {
        header('Content-Type: application/json');
        echo json_encode(['price' => null]);
        exit;
    }
    $price = getBranchServiceCustomPrice($service_id);
    if ($price === null) {
        $row = getPrice($service_id);
        $price = $row ? (float) $row['price'] : null;
    }
    header('Content-Type: application/json');
    echo json_encode(['price' => $price !== null ? round((float) $price) : null]);
    exit;
}

if ($price_type === 'design_fee') {
    $price = getApplicableDesignFee($doctor_id, $service_id > 0 ? $service_id : null);
} else {
    if ($service_id <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['price' => null]);
        exit;
    }
    $price = getApplicablePrice($doctor_id, $service_id);
}

header('Content-Type: application/json');
echo json_encode(['price' => $price !== null ? round((float)$price) : null]);