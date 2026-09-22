<?php
// panel/set_scan_appointment_status.php
// تغییر سریع وضعیت نوبت (رزرو/انجام شد/لغو شد).  POST: id, status
require_once __DIR__ . '/auth.php';
require_login();

function ssas_json(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssas_json(['success' => false, 'message' => 'method'], 405);
}

$sessionToken = $_SESSION['_csrf_token'] ?? '';
$token = $_POST['_csrf_token'] ?? '';
if ($token === '') $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($token === '' || empty($sessionToken) || !hash_equals($sessionToken, $token)) {
    ssas_json(['success' => false, 'message' => 'CSRF token نامعتبر.'], 403);
}

$user = current_user();
if (!canManageScanAppointments($user)) {
    ssas_json(['success' => false, 'message' => 'شما اجازهٔ تغییر وضعیت نوبت را ندارید.'], 403);
}

$id     = (int) ($_POST['id'] ?? 0);
$status = (string) ($_POST['status'] ?? '');
if ($id <= 0 || !array_key_exists($status, scanAppointmentStatuses())) {
    ssas_json(['success' => false, 'message' => 'درخواست نامعتبر.'], 400);
}

$appt = getScanAppointment($id);
if (!$appt) {
    ssas_json(['success' => false, 'message' => 'نوبت یافت نشد.'], 404);
}
$myBranch = currentBranchId();
if ($myBranch !== null && (int) ($appt['branch_id'] ?? 0) !== (int) $myBranch) {
    ssas_json(['success' => false, 'message' => 'این نوبت مربوط به شعبهٔ دیگری است.'], 403);
}

db()->prepare('UPDATE scan_appointments SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, $id]);

if (!empty($appt['case_id'])) {
    log_case_activity((int) $appt['case_id'], 'appt_status', 'وضعیت نوبت اسکن → ' . (scanAppointmentStatuses()[$status]['label'] ?? $status));
}

ssas_json([
    'success' => true,
    'message' => 'وضعیت نوبت تغییر کرد.',
    'status'  => $status,
    'label'   => scanAppointmentStatuses()[$status]['label'] ?? $status,
]);
