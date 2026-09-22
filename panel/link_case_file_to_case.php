<?php
// panel/link_case_file_to_case.php
// اتصالِ فایل(های) یک کیس به کیسِ دیگر → «فایل مرتبط».
// دسترسی: کاربر باید هم کیسِ مبدأ (صاحبِ فایل) و هم کیسِ مقصد را ببیند.
// AJAX → JSON.  POST: case_id (مقصد) + file_ids[] (یا file_id)
require_once __DIR__ . '/auth.php';
require_login();

function lcf_json(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lcf_json(['success' => false, 'message' => 'method'], 405);
}

$sessionToken = $_SESSION['_csrf_token'] ?? '';
$token = $_POST['_csrf_token'] ?? '';
if ($token === '') $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($token === '' || empty($sessionToken) || !hash_equals($sessionToken, $token)) {
    lcf_json(['success' => false, 'message' => 'CSRF token نامعتبر. صفحه را رفرش کنید.'], 403);
}

$user = current_user();
$targetCaseId = (int) ($_POST['case_id'] ?? 0);

// فایل‌ها: هم file_ids[] و هم file_id تکی پذیرفته می‌شود
$fileIds = [];
if (isset($_POST['file_ids']) && is_array($_POST['file_ids'])) {
    $fileIds = array_map('intval', $_POST['file_ids']);
}
if (!empty($_POST['file_id'])) $fileIds[] = (int) $_POST['file_id'];
$fileIds = array_values(array_unique(array_filter($fileIds, function ($v) { return $v > 0; })));

if ($targetCaseId <= 0 || empty($fileIds)) {
    lcf_json(['success' => false, 'message' => 'کیسِ مقصد یا فایل انتخاب نشده است.'], 400);
}

// اجازهٔ «اتصال»: مدیران، کسانی که فایل آپلود می‌کنند و طراح/پزشکِ همان کیس
$canLink = is_admin()
    || has_permission('upload_files')
    || has_permission('upload_design_files')
    || has_permission('edit_cases')
    || in_array($user['role'] ?? '', ['designer', 'doctor'], true);
if (!$canLink || !userCanViewCaseId($targetCaseId, $user)) {
    lcf_json(['success' => false, 'message' => 'دسترسی به کیسِ مقصد ندارید.'], 403);
}

$linked = 0;
$errors = [];
$x = db()->prepare('SELECT id FROM cases WHERE id = ? LIMIT 1');
$x->execute([$targetCaseId]);
if (!$x->fetchColumn()) {
    lcf_json(['success' => false, 'message' => 'کیسِ مقصد یافت نشد.'], 404);
}

foreach ($fileIds as $fid) {
    $file = getCaseFileRow($fid);
    if (!$file) {
        $errors[] = 'فایل #' . $fid . ' یافت نشد.';
        continue;
    }
    if ((int) $file['case_id'] === $targetCaseId) {
        $errors[] = '«' . $file['original_name'] . '» خودش فایلِ همین کیس است.';
        continue;
    }
    // دسترسی به کیسِ مبدأ: یا کیس را می‌بیند، یا خودش آپلودکنندهٔ فایل است
    $ownsUpload = !empty($file['uploader_id']) && (int) $file['uploader_id'] === (int) $user['id'];
    if (!$ownsUpload && !userCanViewCaseId((int) $file['case_id'], $user)) {
        $errors[] = 'به کیسِ مبدأی «' . $file['original_name'] . '» دسترسی ندارید.';
        continue;
    }
    linkCaseFileToCase($fid, $targetCaseId, (int) $user['id']);
    $linked++;
    log_case_activity($targetCaseId, 'file_link', 'اتصال فایل از کیس #' . (int) $file['case_id'] . ' (' . $file['original_name'] . ')');
}

lcf_json([
    'success' => $linked > 0,
    'linked' => $linked,
    'errors' => $errors,
    'message' => $linked > 0 ? 'فایل‌ها به این کیس وصل شد.' : 'هیچ فایلی وصل نشد.',
]);
