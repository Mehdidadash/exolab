<?php
// panel/save_doctor_price_override.php
require_once __DIR__ . '/auth.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: doctor_price_overrides.php');
    exit;
}

$doctor_id = !empty($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : 0;
$service_id = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
$custom_price = isset($_POST['custom_price']) ? (float)$_POST['custom_price'] : 0;

if ($doctor_id <= 0 || $service_id <= 0 || $custom_price <= 0) {
    header('Location: doctor_price_override_form.php?error=missing');
    exit;
}

$data = [
    'doctor_id' => $doctor_id,
    'service_id' => $service_id,
    'custom_price' => $custom_price,
];

if (isset($_POST['id']) && !empty($_POST['id'])) {
    // Update
    $data['id'] = (int)$_POST['id'];
}

saveDoctorPriceOverride($data);
header('Location: doctor_price_overrides.php');
exit;