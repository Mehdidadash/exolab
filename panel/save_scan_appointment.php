<?php
// panel/save_scan_appointment.php
// ثبت/ویرایش نوبت اسکن (AJAX → JSON).  POST: id?, appt_date, start_time, end_time?, doctor_id?,
// case_id?, patient_name?, title?, appt_type, needs_scan_body, address?, phone?, status?, notes?
require_once __DIR__ . '/auth.php';
require_login();

function ssa_json(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssa_json(['success' => false, 'message' => 'method'], 405);
}

$sessionToken = $_SESSION['_csrf_token'] ?? '';
$token = $_POST['_csrf_token'] ?? '';
if ($token === '') $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($token === '' || empty($sessionToken) || !hash_equals($sessionToken, $token)) {
    ssa_json(['success' => false, 'message' => 'CSRF token نامعتبر. صفحه را رفرش کنید.'], 403);
}

$user = current_user();
if (!canManageScanAppointments($user)) {
    ssa_json(['success' => false, 'message' => 'شما اجازهٔ ثبت یا ویرایش نوبت را ندارید.'], 403);
}

// ─── ورودی‌ها ───
$id         = !empty($_POST['id']) ? (int) $_POST['id'] : null;
$apptDateIn = trim((string) ($_POST['appt_date'] ?? ''));
$startTime  = trim((string) ($_POST['start_time'] ?? ''));
$endTime    = trim((string) ($_POST['end_time'] ?? ''));
$doctorId   = !empty($_POST['doctor_id']) ? (int) $_POST['doctor_id'] : null;
$caseId     = !empty($_POST['case_id']) ? (int) $_POST['case_id'] : null;
$patient    = trim((string) ($_POST['patient_name'] ?? ''));
$title      = trim((string) ($_POST['title'] ?? ''));
$apptType   = (string) ($_POST['appt_type'] ?? 'scan');
$needsBody  = !empty($_POST['needs_scan_body']);
$address    = trim((string) ($_POST['address'] ?? ''));
$phone      = trim((string) ($_POST['phone'] ?? ''));
$status     = (string) ($_POST['status'] ?? 'scheduled');
$notes      = trim((string) ($_POST['notes'] ?? ''));

// تاریخ: هم میلادی (2026-09-20) و هم شمسی (۱۴۰۵/۰۶/۲۹) پذیرفته می‌شود
$apptDate = '';
if ($apptDateIn !== '') {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $apptDateIn)) {
        $apptDate = $apptDateIn;
    } else {
        $apptDate = (string) parseJalaliToGregorian($apptDateIn);
        if ($apptDate === '') $apptDate = (string) parseDateInput($apptDateIn);
    }
}
// ساعت‌ها را به HH:MM نرمال کن (پذیرش ۹:۳۰ و 9:30 و ۰۹:۳۰)
$normTime = function (string $t): string {
    $t = normalizePersianDigits(trim($t));
    if ($t === '') return '';
    if (!preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) return '';
    $h = (int) $m[1];
    $i = (int) $m[2];
    if ($h > 23 || $i > 59) return '';
    return sprintf('%02d:%02d', $h, $i);
};
$startTime = $normTime($startTime);
$endTime   = $normTime($endTime);

// ─── اعتبارسنجی ───
$errors = [];
if ($apptDate === '')     $errors[] = 'تاریخ نوبت را وارد کنید.';
if ($startTime === '')    $errors[] = 'ساعت شروع را وارد کنید.';
if ($endTime !== '' && $startTime !== '' && $endTime <= $startTime) {
    $errors[] = 'ساعت پایان باید بعد از ساعت شروع باشد.';
}
if (!array_key_exists($apptType, scanAppointmentTypes())) $apptType = 'scan';
if (!array_key_exists($status, scanAppointmentStatuses()))  $status = 'scheduled';
if ($doctorId) {
    $chk = db()->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
    $chk->execute([$doctorId]);
    if (!(int) $chk->fetchColumn()) $errors[] = 'پزشک انتخاب‌شده معتبر نیست.';
}
if ($caseId) {
    $chk = db()->prepare('SELECT id, doctor_id, patient_name FROM cases WHERE id = ?');
    $chk->execute([$caseId]);
    $cRow = $chk->fetch();
    if (!$cRow) {
        $errors[] = 'کیس انتخاب‌شده معتبر نیست.';
    } else {
        // پزشک/بیمار از خودِ کیس پر می‌شوند تا نوبت با کیس هم‌خوان باشد
        if (!$doctorId && !empty($cRow['doctor_id'])) $doctorId = (int) $cRow['doctor_id'];
        if ($patient === '' && !empty($cRow['patient_name'])) $patient = (string) $cRow['patient_name'];
    }
}
if ($patient === '' && $caseId) {
    $errors[] = 'نام بیمار را وارد کنید.';
}
if ($errors) {
    ssa_json(['success' => false, 'message' => 'اطلاعات نوبت کامل نیست.', 'errors' => $errors], 400);
}

// ─── شعبه ───
$branchId = currentBranchId();
if ($branchId === null) {
    // مدیر کل: شعبهٔ پزشک، وگرنه شعبهٔ کیس، وگرنه شعبهٔ اول
    if ($doctorId) {
        $b = db()->prepare('SELECT branch_id FROM users WHERE id = ?');
        $b->execute([$doctorId]);
        $vb = $b->fetchColumn();
        if ($vb !== null && $vb !== '') $branchId = (int) $vb;
    }
    if ($branchId === null && $caseId) {
        $b = db()->prepare('SELECT branch_id FROM cases WHERE id = ?');
        $b->execute([$caseId]);
        $vb = $b->fetchColumn();
        if ($vb !== null && $vb !== '') $branchId = (int) $vb;
    }
    if ($branchId === null) {
        $first = db()->query('SELECT id FROM branches ORDER BY id LIMIT 1')->fetchColumn();
        $branchId = $first ? (int) $first : 1;
    }
}

// ─── ویرایش: بررسی دسترسی به نوبتِ فعلی ───
if ($id) {
    $current = getScanAppointment($id);
    if (!$current) {
        ssa_json(['success' => false, 'message' => 'نوبت یافت نشد.'], 404);
    }
    $myBranch = currentBranchId();
    if ($myBranch !== null && (int) ($current['branch_id'] ?? 0) !== (int) $myBranch) {
        ssa_json(['success' => false, 'message' => 'این نوبت مربوط به شعبهٔ دیگری است.'], 403);
    }
}

$payload = [
    'branch_id'       => $branchId,
    'doctor_id'       => $doctorId,
    'case_id'         => $caseId,
    'patient_name'    => ($patient !== '' ? $patient : null),
    'title'           => ($title !== '' ? $title : null),
    'appt_date'       => $apptDate,
    'start_time'      => $startTime,
    'end_time'        => ($endTime !== '' ? $endTime : null),
    'appt_type'       => $apptType,
    'needs_scan_body' => $needsBody ? 1 : 0,
    'address'         => ($address !== '' ? $address : null),
    'phone'           => ($phone !== '' ? $phone : null),
    'status'          => $status,
    'notes'           => ($notes !== '' ? $notes : null),
    'created_by'      => (int) $user['id'],
];

try {
    $savedId = saveScanAppointment($payload, $id);
} catch (Throwable $e) {
    error_log('save_scan_appointment: ' . $e->getMessage());
    ssa_json(['success' => false, 'message' => 'ثبت نوبت انجام نشد. دوباره تلاش کنید.'], 500);
}

// لاگ روی کیس (اگر نوبت به کیس وصل است)
if ($caseId) {
    log_case_activity($caseId, $id ? 'appt_update' : 'appt_create', 'ثبت/ویرایش نوبت اسکن برای ' . toJalaliDateFormatted($apptDate) . ' ساعت ' . $startTime);
}

// اعلان به پزشک (اگر نوبت برای پزشکی ثبت شده و به‌روزرسانی مهم است)
if ($doctorId && (int) $doctorId !== (int) $user['id']) {
    try {
        $msg = 'نوبت ' . (scanAppointmentTypes()[$apptType]['label'] ?? 'اسکن') . ' — ' . toJalaliDateFormatted($apptDate)
             . ' ساعت ' . $startTime . ($needsBody ? ' (اسکن‌بادی لازم است)' : '');
        createNotification((int) $doctorId, 'نوبت اسکن', $msg, $caseId, 'appointment');
    } catch (Throwable $e) {
        // اعلان نباید ذخیرهٔ نوبت را خراب کند
    }
}

ssa_json([
    'success' => true,
    'id'      => $savedId,
    'appointment' => getScanAppointment($savedId),
    'message' => $id ? 'نوبت ویرایش شد.' : 'نوبت ثبت شد.',
]);
