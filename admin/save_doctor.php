<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: doctors.php');
    exit;
}

$name = trim($_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if (empty($name)) {
    header('Location: doctor_form.php?error=missing');
    exit;
}

$data = [
    'name' => $name,
    'phone' => $phone ?: null,
    'email' => $email ?: null,
    'notes' => $notes ?: null,
];

if (!empty($_POST['id'])) {
    $data['id'] = (int) $_POST['id'];
}

saveDoctor($data);
header('Location: doctors.php');
