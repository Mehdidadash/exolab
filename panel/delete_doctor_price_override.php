<?php
// panel/delete_doctor_price_override.php
require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
    header('Location: doctor_price_overrides.php');
    exit;
}

$delId = (int)$_POST['id'];

// Only the central (root) admin may delete SHARED inter-branch / lab rates.
if (!is_root_admin()) {
    $ov = db()->prepare('SELECT doctor_id, branch_id FROM doctor_price_overrides WHERE id = ?');
    $ov->execute([$delId]);
    $row = $ov->fetch();
    if ($row && isSharedPriceOverrideTarget((int) $row['doctor_id'], $row['branch_id'] !== null ? (int) $row['branch_id'] : null)) {
        http_response_code(403);
        die('دسترسی غیرمجاز — نرخ‌های بین شعب/لابراتوار فقط توسط شعبه مرکزی قابل تغییر است.');
    }
}

audit_log_delete('price_override', $delId, 'قیمت اختصاصی');
deleteDoctorPriceOverride($delId);
header('Location: doctor_price_overrides.php');
exit;