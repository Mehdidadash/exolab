<?php
// panel/save_lab_price_override.php
require_once __DIR__ . '/auth.php';
require_role('admin');
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: lab_price_overrides.php');
    exit;
}

$id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
$lab_id = (int) ($_POST['lab_id'] ?? 0);
$service_id = (int) ($_POST['service_id'] ?? 0);
$custom_price = (float) ($_POST['custom_price'] ?? 0);

if ($lab_id <= 0 || $service_id <= 0) {
    header('Location: lab_price_override_form.php?error=missing' . ($id ? '&id=' . $id : ''));
    exit;
}

$savedId = saveLabPriceOverride([
    'id' => $id,
    'lab_id' => $lab_id,
    'service_id' => $service_id,
    'custom_price' => $custom_price,
]);

audit_log_save('lab_price_override', $savedId, 'قیمت اختصاصی لابراتوار');
header('Location: lab_price_overrides.php');
exit;
