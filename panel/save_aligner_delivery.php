<?php
// panel/save_aligner_delivery.php
// ثبتِ «تحویل مرحله‌های الاینر شفاف» برای یک کیس + ویرایش تعداد کل مراحلِ هر فک.
//
// ورودی (POST):
//   case_id, action = 'delivery' | 'plan'
//   action=plan     → upper_stages, lower_stages
//   action=delivery → delivered_date (شمسی یا میلادی), upper_from, upper_qty, lower_from, lower_qty,
//                     unit_price, note
//   _csrf_token (یا هدر X-CSRF-Token)
//
// خروجی: ریدایرکت به view_case.php?id=…#aligner  (با msg/error در آدرس)
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('روش درخواست مجاز نیست.');
}

$sessionToken = $_SESSION['_csrf_token'] ?? '';
$token = $_POST['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
    http_response_code(403);
    die('توکن امنیتی نامعتبر است. صفحه را رفرش کنید.');
}

$caseId = !empty($_POST['case_id']) ? (int) $_POST['case_id'] : 0;
if ($caseId <= 0) {
    http_response_code(400);
    die('کیس مشخص نشده است.');
}

$canManage = is_admin() || has_permission('edit_cases') || has_permission('update_case_status')
    || has_permission('upload_files') || is_designer_user($user);
if (!$canManage || !userCanViewCaseId($caseId, $user)) {
    http_response_code(403);
    die('دسترسی غیرمجاز.');
}

$action = (string) ($_POST['action'] ?? 'delivery');
$back = 'view_case.php?id=' . $caseId . '#aligner';
$redirect = function (string $param, string $msg) use ($back) {
    $sep = (strpos($back, '?') !== false) ? '&' : '?';
    header('Location: ' . $back . $sep . $param . '=' . rawurlencode($msg));
    exit;
};

$st = db()->prepare('SELECT id, service_id, location_type, aligner_upper_stages, aligner_lower_stages FROM cases WHERE id = ? LIMIT 1');
$st->execute([$caseId]);
$case = $st->fetch();
if (!$case) {
    http_response_code(404);
    die('کیس یافت نشد.');
}
if (!serviceIsAligner((int) $case['service_id'])) {
    $redirect('error', 'این کیس از نوع «طرح درمان الاینر» نیست.');
}

// ─── ویرایشِ تعداد کل مراحلِ هر فک ───
if ($action === 'plan') {
    $upper = isset($_POST['upper_stages']) && $_POST['upper_stages'] !== '' ? max(0, (int) $_POST['upper_stages']) : null;
    $lower = isset($_POST['lower_stages']) && $_POST['lower_stages'] !== '' ? max(0, (int) $_POST['lower_stages']) : null;
    $loc = (string) ($case['location_type'] ?? 'both');
    if ($loc === 'upper') { $lower = null; }
    if ($loc === 'lower') { $upper = null; }
    try {
        $up = db()->prepare('UPDATE cases SET aligner_upper_stages = ?, aligner_lower_stages = ? WHERE id = ?');
        $up->execute([$upper ?: null, $lower ?: null, $caseId]);
        log_case_activity($caseId, 'aligner_plan', json_encode(['upper' => $upper, 'lower' => $lower], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        $redirect('error', 'ذخیرهٔ تعداد مراحل انجام نشد.');
    }
    $redirect('msg', 'تعداد مراحل ذخیره شد.');
}

// ─── ثبتِ تحویلِ مرحله ───
$dateIn = trim((string) ($_POST['delivered_date'] ?? ''));
$deliveredDate = '';
if ($dateIn !== '') {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateIn)) {
        $deliveredDate = $dateIn;
    } else {
        $deliveredDate = (string) parseJalaliToGregorian($dateIn);
        if ($deliveredDate === '') $deliveredDate = (string) parseDateInput($dateIn);
    }
}
if ($deliveredDate === '') {
    $redirect('error', 'تاریخ تحویل معتبر نیست.');
}

$upperFrom = isset($_POST['upper_from']) && $_POST['upper_from'] !== '' ? (int) $_POST['upper_from'] : null;
$upperQty  = isset($_POST['upper_qty'])  && $_POST['upper_qty']  !== '' ? (int) $_POST['upper_qty']  : 0;
$lowerFrom = isset($_POST['lower_from']) && $_POST['lower_from'] !== '' ? (int) $_POST['lower_from'] : null;
$lowerQty  = isset($_POST['lower_qty'])  && $_POST['lower_qty']  !== '' ? (int) $_POST['lower_qty']  : 0;

if ($upperQty <= 0 && $lowerQty <= 0) {
    $redirect('error', 'تعداد مرحله برای فک بالا یا پایین باید بیشتر از صفر باشد.');
}
if (($upperQty > 0 && ($upperFrom === null || $upperFrom < 1)) || ($lowerQty > 0 && ($lowerFrom === null || $lowerFrom < 1))) {
    $redirect('error', '«از مرحلهٔ» هر فک را وارد کنید (بزرگ‌تر از صفر).');
}

$upperTo = $upperQty > 0 ? $upperFrom + $upperQty - 1 : null;
$lowerTo = $lowerQty > 0 ? $lowerFrom + $lowerQty - 1 : null;

// کنترلِ سقف: از تعداد کل مراحل عبور نکند (اگر ثبت شده باشد)
$maxUpper = !empty($case['aligner_upper_stages']) ? (int) $case['aligner_upper_stages'] : 0;
$maxLower = !empty($case['aligner_lower_stages']) ? (int) $case['aligner_lower_stages'] : 0;
if ($maxUpper > 0 && $upperTo !== null && $upperTo > $maxUpper) {
    $redirect('error', 'فک بالا: مرحلهٔ پایان (' . $upperTo . ') از تعداد کل مراحل (' . $maxUpper . ') بیشتر است.');
}
if ($maxLower > 0 && $lowerTo !== null && $lowerTo > $maxLower) {
    $redirect('error', 'فک پایین: مرحلهٔ پایان (' . $lowerTo . ') از تعداد کل مراحل (' . $maxLower . ') بیشتر است.');
}

$unitPrice = isset($_POST['unit_price']) && $_POST['unit_price'] !== ''
    ? (float) str_replace([',', '،', ' '], '', (string) $_POST['unit_price'])
    : 0;

$stageServiceId = alignerStageServiceId((int) $case['service_id']);
if ($unitPrice <= 0) {
    $stagePrice = $stageServiceId ? getPrice($stageServiceId) : null;
    $unitPrice = $stagePrice ? (float) $stagePrice['price'] : 0;
}

$deliveryId = addCaseAlignerDelivery($caseId, $deliveredDate, [
    'upper_from' => $upperFrom,
    'upper_to'   => $upperTo,
    'lower_from' => $lowerFrom,
    'lower_to'   => $lowerTo,
    'unit_price' => $unitPrice,
    'service_id' => $stageServiceId ?: null,
    'note'       => trim((string) ($_POST['note'] ?? '')),
], (int) $user['id']);

if (!$deliveryId) {
    $redirect('error', 'ثبت تحویل انجام نشد.');
}

log_case_activity($caseId, 'aligner_delivery', json_encode([
    'upper' => $upperTo ? ($upperFrom . '-' . $upperTo) : null,
    'lower' => $lowerTo ? ($lowerFrom . '-' . $lowerTo) : null,
    'amount' => $unitPrice * (($upperQty ?: 0) + ($lowerQty ?: 0)),
    'date' => $deliveredDate,
], JSON_UNESCAPED_UNICODE));

$redirect('msg', 'تحویل مرحله‌های الاینر ثبت شد.');
