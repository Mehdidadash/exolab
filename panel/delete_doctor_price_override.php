<?php
// panel/delete_doctor_price_override.php
require_once __DIR__ . '/auth.php';
require_role('admin');
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
    header('Location: doctor_price_overrides.php');
    exit;
}

$delId = (int)$_POST['id'];
audit_log_delete('price_override', $delId, 'قیمت اختصاصی');
deleteDoctorPriceOverride($delId);
header('Location: doctor_price_overrides.php');
exit;