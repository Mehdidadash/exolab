<?php
// panel/save_doctor_price_override.php
require_once __DIR__ . '/auth.php';
require_admin();

require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: doctor_price_overrides.php');
    exit;
}

$doctor_id = !empty($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : 0;
$service_id = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
$price_type = isset($_POST['price_type']) ? strtolower((string)$_POST['price_type']) : 'service';
$custom_price = isset($_POST['custom_price']) ? (float)$_POST['custom_price'] : 0;

if ($doctor_id <= 0 || $custom_price <= 0) {
    header('Location: doctor_price_override_form.php?error=missing');
    exit;
}

// Service is required for the 'service' type; optional (per-type) for 'design_fee'
if ($price_type === 'service' && $service_id <= 0) {
    header('Location: doctor_price_override_form.php?error=missing');
    exit;
}

// Only the central (root) admin may change SHARED inter-branch / lab rates.
if (!is_root_admin()) {
    $overrideBranch = currentBranchId();
    if (isSharedPriceOverrideTarget($doctor_id, $overrideBranch)) {
        http_response_code(403);
        die('دسترسی غیرمجاز — نرخ‌های بین شعب/لابراتوار فقط توسط شعبه مرکزی قابل تغییر است.');
    }
}

$data = [
    'doctor_id' => $doctor_id,
    'service_id' => $service_id,
    'price_type' => $price_type,
    'custom_price' => $custom_price,
];

if (isset($_POST['id']) && !empty($_POST['id'])) {
    // Update
    $data['id'] = (int)$_POST['id'];
}

$savedId = saveDoctorPriceOverride($data);
audit_log_save('price_override', $savedId, 'قیمت اختصاصی');
header('Location: doctor_price_overrides.php');
exit;