<?php
// panel/download_upload_folder.php
// دانلود «گروه پوشه» به‌صورت یک فایل ZIP — چون مرورگر/سیستم نمی‌تواند پوشه را دانلود کند.
//
// پارامترها (GET):
//   folder = نام پوشهٔ ریشه (همان بخش اولِ rel_path)
//   case   = (اختیاری) شناسهٔ کیس — فایل‌های آن کیس را محدود می‌کند
//   all    = (اختیاری) 1 → فایل‌های همهٔ کاربران (فقط برای کتابخانهٔ مشترک)
//
// دسترسی: هر فایلی که کاربر اجازهٔ دیدنش را ندارد، از ZIP حذف می‌شود
// (userCanViewUserUpload) — پس هیچ نشتی رخ نمی‌دهد.
require_once __DIR__ . '/auth.php';
require_login();

$user     = current_user();
$folder   = sanitizeRelPath((string) ($_GET['folder'] ?? ''));
$caseId   = !empty($_GET['case']) ? (int) $_GET['case'] : 0;
$scopeAll = !empty($_GET['all']) && (is_admin() || $user['role'] === 'designer');

if ($folder === null) {
    http_response_code(400);
    die('پوشه مشخص نشده است.');
}
$root = relPathRoot($folder)['root'] ?? null;
if ($root === null) {
    http_response_code(400);
    die('نام پوشه نامعتبر است.');
}

// ─── فایل‌های این پوشه (و زیرپوشه‌هایش) ───
$sql = 'SELECT u.*
        FROM user_uploads u
        WHERE (u.rel_path = ? OR u.rel_path LIKE ?)';
$params = [$root, $root . '/%'];

if ($caseId > 0) {
    // فقط فایل‌هایی که به این کیس وصل‌اند (پیوند چندبه‌چند + case_id قدیمی)
    $sql .= ' AND (u.case_id = ? OR u.id IN (SELECT upload_id FROM user_upload_case_links WHERE case_id = ?))';
    $params[] = $caseId;
    $params[] = $caseId;
} elseif (!$scopeAll) {
    // «فایل‌های من» → فقط آپلودهای خود کاربر
    $sql .= ' AND u.user_id = ?';
    $params[] = (int) $user['id'];
}

$sql .= ' ORDER BY u.rel_path ASC, u.id ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (empty($rows)) {
    http_response_code(404);
    die('فایلی در این پوشه یافت نشد.');
}

// ─── فیلتر دسترسی + ساخت لیست فایل‌های ZIP ───
$zipFiles = [];
foreach ($rows as $up) {
    if (!userCanViewUserUpload($up, $user)) {
        continue;
    }
    $path = resolve_upload_path('user/' . $up['filename']);
    if (!is_file($path)) {
        continue;
    }
    // نام داخل ZIP: ساختار پوشه حفظ می‌شود (rel_path) و نام نمایشی خواناست.
    // چون فایل روی دیسک نام تصادفی دارد، از original_name برای نام نهایی استفاده می‌کنیم:
    //   <root>/<زیرپوشه‌ها>/<original_name>
    $rel  = sanitizeRelPath($up['rel_path'] ?? null) ?? $root;
    $dir  = (str_contains($rel, '/')) ? dirname($rel) : $rel;
    $name = ($dir !== '' && $dir !== '.' ? $dir . '/' : '') . basename((string) $up['original_name']);
    $zipFiles[] = ['path' => $path, 'name' => $name];
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
$safeRoot = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]+/u', '-', $root);
$safeRoot = trim((string) $safeRoot, " _-.") ?: 'folder';
$zipFsName = 'upfolder_' . $safeRoot . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.zip';
$zipPath   = $storageDir . '/' . $zipFsName;

$zipErrors = [];
if (!buildZipFromFiles($zipFiles, $zipPath, $zipErrors)) {
    http_response_code(500);
    die('خطا در ساخت فایل ZIP (' . htmlspecialchars(implode(', ', $zipErrors)) . ')');
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
