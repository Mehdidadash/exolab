<?php
// panel/save_entity_doctor.php
// Assign a doctor to a clinic (clinic_id) or a lab (lab_id) as a sub-member.
require_once __DIR__ . '/auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('method');
}
if (!verify_csrf()) {
    http_response_code(403);
    die('CSRF invalid');
}

$doctorId = !empty($_POST['doctor_id']) ? (int) $_POST['doctor_id'] : 0;
$entityType = $_POST['entity_type'] ?? '';   // 'clinic' | 'lab'
$entityId = !empty($_POST['entity_id']) ? (int) $_POST['entity_id'] : 0;
$action = $_POST['action'] ?? 'assign';       // 'assign' | 'remove'

if ($doctorId <= 0 || $entityId <= 0 || !in_array($entityType, ['clinic', 'lab'], true)) {
    die('invalid');
}

// Verify the doctor exists
$docChk = db()->prepare('SELECT id FROM users WHERE id = ? AND role = "doctor"');
$docChk->execute([$doctorId]);
if (!$docChk->fetch()) {
    die('پزشک یافت نشد');
}

if ($entityType === 'clinic') {
    // Verify target is a clinic
    $chk = db()->prepare('SELECT id FROM users WHERE id = ? AND role = "clinic"');
    $chk->execute([$entityId]);
    if (!$chk->fetch()) die('کلینیک یافت نشد');
    $col = 'clinic_id';
} else {
    // Verify target is a lab role
    $chk = db()->prepare("SELECT id FROM users WHERE id = ? AND role IN ('outsource_lab','customer_lab','partner_lab','lab')");
    $chk->execute([$entityId]);
    if (!$chk->fetch()) die('لابراتوار یافت نشد');
    $col = 'lab_id';
}

if ($action === 'remove') {
    if ($col === 'clinic_id') {
        // حذف عضویتِ همین کلینیک (اگر کلینیک اصلی بود، یکی از عضویت‌های دیگر جانشین می‌شود)
        removeUserFromClinic($doctorId, $entityId);
    } else {
        $upd = db()->prepare('UPDATE users SET lab_id = NULL WHERE id = ?');
        $upd->execute([$doctorId]);
    }
} else {
    // Assign: یک پزشک می‌تواند عضو چند کلینیک باشد (عضویت اضافه می‌شود)،
    // ولی فقط یک لابراتوار دارد.
    if ($col === 'clinic_id') {
        addUserToClinic($doctorId, $entityId);
    } else {
        $upd = db()->prepare('UPDATE users SET lab_id = ? WHERE id = ?');
        $upd->execute([$entityId, $doctorId]);
    }
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'network.php'));
exit;
