<?php
// panel/delete_scan_appointment.php
// حذف نوبت اسکن (AJAX → JSON).  POST: id
require_once __DIR__ . '/auth.php';
require_login();

function dsa_json(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    dsa_json(['success' => false, 'message' => 'method'], 405);
}

$sessionToken = $_SESSION['_csrf_token'] ?? '';
$token = $_POST['_csrf_token'] ?? '';
if ($token === '') $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($token === '' || empty($sessionToken) || !hash_equals($sessionToken, $token)) {
    dsa_json(['success' => false, 'message' => 'CSRF token نامعتبر.'], 403);
}

$user = current_user();
if (!canManageScanAppointments($user)) {
    dsa_json(['success' => false, 'message' => 'شما اجازهٔ حذف نوبت را ندارید.'], 403);
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    dsa_json(['success' => false, 'message' => 'نوبت انتخاب نشده است.'], 400);
}

$appt = getScanAppointment($id);
if (!$appt) {
    dsa_json(['success' => true, 'message' => 'نوبت وجود نداشت.']);
}

$myBranch = currentBranchId();
if ($myBranch !== null && (int) ($appt['branch_id'] ?? 0) !== (int) $myBranch) {
    dsa_json(['success' => false, 'message' => 'این نوبت مربوط به شعبهٔ دیگری است.'], 403);
}

if (!empty($appt['case_id'])) {
    log_case_activity((int) $appt['case_id'], 'appt_delete', 'حذف نوبت اسکن ' . toJalaliDateFormatted((string) $appt['appt_date']));
}

deleteScanAppointment($id);
dsa_json(['success' => true, 'message' => 'نوبت حذف شد.']);
