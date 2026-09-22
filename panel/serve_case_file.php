<?php
// panel/serve_case_file.php
// نمایش/پیش‌نمایش درون‌خطی فایل کیس (عکس یا مدل سه‌بعدی) برای کسی که اجازه‌ی دیدن
// کیس را دارد. فایل‌ها بیرون از ریشه‌ی وب ذخیره می‌شوند، بنابراین با URL مستقیم
// قابل دسترسی نیستند و همه‌ی نمایش‌ها از اینجا سرو می‌شوند.

require_once __DIR__ . '/auth.php';
require_login();

$fileId = !empty($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$fileId) {
    http_response_code(400);
    die('درخواست نامعتبر');
}

$user = current_user();

// ردیف خام فایل (برای بررسی دسترسی از راه «اتصال به کیس‌های دیگر»)
$raw = getCaseFileRow($fileId);
if (!$raw) {
    http_response_code(404);
    die('فایل یافت نشد');
}

// ۱) دسترسی از راه کیسِ خودِ فایل
// همان تابع واحدی که صفحهٔ مشاهدهٔ کیس هم از آن استفاده می‌کند: هر کس رابطه‌ای با کیس
// داشته باشد (پزشک/طراح/لابراتوارِ کیس یا برون‌سپاری/کلینیک/شعبه/مجوز) فایل را می‌بیند.
$canCheckOwnCase = userCanViewCaseId((int) $raw['case_id'], $user);
$file = $canCheckOwnCase ? $raw : null;

// ۲) اگر از راه کیسِ خودش دسترسی نداشت: شاید فایل به کیسی که کاربر می‌بیند وصل شده باشد
if (!$file && caseFileAccessibleViaLinks($fileId, $user)) {
    $file = $raw;
}

if (!$file) {
    http_response_code($canCheckOwnCase ? 404 : 403);
    die($canCheckOwnCase ? 'فایل یافت نشد' : 'دسترسی غیرمجاز');
}

$path = resolve_upload_path('cases/' . $file['case_id'] . '/' . $file['filename']);
if (!is_file($path)) {
    http_response_code(404);
    die('فایل روی سرور یافت نشد');
}

$ext = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
$mimes = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp', 'svg' => 'image/svg+xml',
    'stl' => 'model/stl', 'ply' => 'model/ply', 'obj' => 'model/obj',
    '3mf' => 'application/vnd.ms-package.3dmanufacturing-3dmodel+xml',
    'stp' => 'application/step', 'step' => 'application/step',
];
$ctype = $mimes[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $ctype);
header('Content-Disposition: inline; filename="' . basename($file['original_name'] ?? $file['filename']) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
