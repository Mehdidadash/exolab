<?php
// panel\save_doctor.php

require_once __DIR__ . '/auth.php';
require_admin();

require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: doctors.php');
    exit;
}

$name = trim($_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$password = trim($_POST['password'] ?? '');
$clinicId = !empty($_POST['clinic_id']) ? (int) $_POST['clinic_id'] : null;

if (empty($name)) {
    header('Location: doctor_form.php?error=missing');
    exit;
}

$data = [
    'name' => $name,
    'phone' => $phone ?: null,
    'email' => $email ?: null,
    'notes' => $notes ?: null,
    'clinic_id' => $clinicId,
    // کلینیک‌های عضویتِ چندگانه (یک پزشک می‌تواند در چند کلینیک کار کند)
    'clinic_ids' => (isset($_POST['clinic_ids']) && is_array($_POST['clinic_ids']))
        ? array_values(array_filter(array_unique(array_map('intval', $_POST['clinic_ids'])), function ($v) { return $v > 0; }))
        : [],
];

// Only include 'password' in the data array if one was actually typed.
// saveDoctor() only touches password_hash when this key is present and
// non-empty, so leaving the field blank on edit keeps the old password.
if ($password !== '') {
    $data['password'] = $password;
}

if (!empty($_POST['id'])) {
    $data['id'] = (int) $_POST['id'];
}

$savedId = saveDoctor($data);
audit_log_save('doctor', $savedId, 'پزشک');
header('Location: doctors.php');
