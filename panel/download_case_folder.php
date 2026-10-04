<?php
// panel/download_case_folder.php
// دانلود «پوشهٔ آپلودشدهٔ» یک کیس به‌صورت ZIP — چون پوشه را نمی‌توان مستقیم دانلود کرد.
//
// پارامترها (GET):
//   case   = شناسهٔ کیس (اجباری)
//   folder = نام پوشهٔ ریشه (همان بخش اولِ rel_path) — بدون آن، همهٔ پوشه‌های کیس
//
// دسترسی: کاربر باید کیس را ببیند (userCanViewCaseId). علاوه بر آن، هر فایلی که
// از راه «اتصال به کیس‌های دیگر» قابل دسترسی باشد هم فیلتر می‌شود.
require_once __DIR__ . '/auth.php';
require_login();

$user   = current_user();
$caseId = !empty($_GET['case']) ? (int) $_GET['case'] : 0;
$folder = sanitizeRelPath((string) ($_GET['folder'] ?? ''));

if ($caseId <= 0) {
    http_response_code(400);
    die('کیس مشخص نشده است.');
}
if ($folder === null) {
    http_response_code(400);
    die('پوشه مشخص نشده است.');
}
$root = relPathRoot($folder)['root'] ?? null;
if ($root === null) {
    http_response_code(400);
    die('نام پوشه نامعتبر است.');
}

// ─── کنترل دسترسی به کیس ───
if (!userCanViewCaseId($caseId, $user)) {
    http_response_code(403);
    die('دسترسی غیرمجاز');
}

// ─── فایل‌های همین پوشه (و زیرپوشه‌هایش) از همین کیس ───
$st = db()->prepare(
    'SELECT cf.* FROM case_files cf
     WHERE cf.case_id = ? AND (cf.rel_path = ? OR cf.rel_path LIKE ?)
     ORDER BY cf.rel_path ASC, cf.id ASC'
);
$st->execute([$caseId, $root, $root . '/%']);
$rows = $st->fetchAll();

if (empty($rows)) {
    http_response_code(404);
    die('فایلی در این پوشه یافت نشد.');
}

// ─── ساخت لیست فایل‌های ZIP و ثبت لاگ دانلود ───
$zipFiles = [];
$toLog    = [];
foreach ($rows as $f) {
    // همان منطق دسترسیِ serve/download_case_file (شامل «فایل مرتبط»)
    $canOwn = userCanViewCaseId((int) $f['case_id'], $user);
    if (!$canOwn && !caseFileAccessibleViaLinks((int) $f['id'], $user)) {
        continue;
    }
    $path = resolve_upload_path('cases/' . $caseId . '/' . $f['filename']);
    if (!is_file($path)) {
        continue;
    }
    $rel  = sanitizeRelPath($f['rel_path'] ?? null) ?? $root;
    $dir  = (str_contains($rel, '/')) ? dirname($rel) : $rel;
    $name = ($dir !== '' && $dir !== '.' ? $dir . '/' : '') . basename((string) $f['original_name']);
    $zipFiles[] = ['path' => $path, 'name' => $name];
    $toLog[] = $f;
}

if (empty($zipFiles)) {
    http_response_code(404);
    die('فایل قابل‌دانلودی در این پوشه نیست.');
}

// ─── ساخت ZIP در storage (بیرون از وب) ───
$storageDir = __DIR__ . '/../storage';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0775, true);
}
$safeRoot  = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]+/u', '-', $root);
$safeRoot  = trim((string) $safeRoot, " _-.") ?: 'folder';
$zipFsName = 'casefolder_' . $caseId . '_' . $safeRoot . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.zip';
$zipPath   = $storageDir . '/' . $zipFsName;

$zipErrors = [];
if (!buildZipFromFiles($zipFiles, $zipPath, $zipErrors)) {
    http_response_code(500);
    die('خطا در ساخت فایل ZIP (' . htmlspecialchars(implode(', ', $zipErrors)) . ')');
}

// ─── ثبت لاگ دانلود (چه کسی / کدام فایل‌ها / نوع) ───
foreach ($toLog as $f) {
    log_case_activity($caseId, 'file_download', json_encode([
        'file_id' => (int) $f['id'],
        'file'    => $f['original_name'] ?? '',
        'type'    => ($f['file_type'] ?? '') === 'final_design' ? 'design' : 'raw',
        'via'     => 'folder_zip',
        'folder'  => $root,
    ], JSON_UNESCAPED_UNICODE));
}

// ─── ارسال به کاربر ───
$downloadName = $safeRoot . '.zip';
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . str_replace(["\r", "\n", '"'], '', $downloadName) . '"');
header('Content-Length: ' . filesize($zipPath));
header('Cache-Control: private, max-age=0');
header('Pragma: public');
readfile($zipPath);
@unlink($zipPath);   // پاک‌سازی فایل موقت
exit;
