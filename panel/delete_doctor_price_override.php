<?php
// panel/delete_doctor_price_override.php
require_once __DIR__ . '/auth.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
    header('Location: doctor_price_overrides.php');
    exit;
}

deleteDoctorPriceOverride((int)$_POST['id']);
header('Location: doctor_price_overrides.php');
exit;