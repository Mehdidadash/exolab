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

// ── طراح پیش‌فرضِ اختصاصیِ این پزشک ──
// فقط وقتی فیلد در فرم بوده ذخیره می‌شود (فرم‌های دیگرِ ذخیرهٔ پزشک ممکن است آن را نفرستند).
// مقدار نامعتبر / غیرطراح → خالی (یعنی «استفاده از پیش‌فرضِ شعبه»).
if (array_key_exists('default_designer_id', $_POST)) {
    $did = (int) ($_POST['default_designer_id'] ?? 0);
    if ($did > 0 && !isValidDesignerId($did)) $did = 0;
    try {
        $upd = db()->prepare('UPDATE users SET default_designer_id = ? WHERE id = ?');
        $upd->execute([$did > 0 ? $did : null, $savedId]);
    } catch (\Throwable $e) {
        error_log('save_doctor default_designer_id: ' . $e->getMessage());
    }
}

audit_log_save('doctor', $savedId, 'پزشک');
header('Location: doctors.php');
