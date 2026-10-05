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
    'pdf' => 'application/pdf',
    'zip' => 'application/zip', 'rar' => 'application/x-rar-compressed',
    'txt' => 'text/plain; charset=utf-8', 'xml' => 'application/xml',
    'dcm' => 'application/dicom', 'dicom' => 'application/dicom',
];

// ── فایل HTML: دو حالت کاملاً متفاوت ──
// الف) «وب‌ویوِ exocad» — فایلهای .html که خودِ دستگاه اسکن/نرمافزار exocad می‌سازد
//     (خروجی FRAME/طراحی که مدل سه‌بعدی را داخل JavaScript رندر می‌کند). اینها برای
//     کار کردن به Content-Type: text/html نیاز دارند، وگرنه فقط سورسکد نمایش داده
//     می‌شود و «نمایشگر سه‌بعدی» کار نمی‌کند.
//     ⚠️ امنیت: چون اسکریپت داخلشان اجرا می‌شود، فقط با CSP سختگیرانه و در
//     sandbox سرو می‌شوند و اسکریپتشان به دامنهٔ سایت دسترسی ندارد.
// ب) بقیهٔ فایلهای HTML (آپلودِ کاربر) — مثل قبل به text/plain تبدیل می‌شوند.
$isHtml = in_array($ext, ['html', 'htm', 'xhtml'], true);
$isExocadWebview = false;
if ($isHtml) {
    // تشخیص از روی هدر فایل (۸ کیلوبایت اول) — نه از روی نام، تا جعل نام کار نکند.
    $probe = @file_get_contents($path, false, null, 0, 8192);
    if ($probe !== false && (stripos($probe, 'exocad webview') !== false || stripos($probe, 'exocad GmbH') !== false)) {
        $isExocadWebview = true;
    }
}

if ($isHtml && !$isExocadWebview) {
    $ctype = 'text/plain; charset=utf-8';
}
$ctype = $mimes[$ext] ?? ($ctype ?? 'application/octet-stream');
if ($isExocadWebview) {
    $ctype = 'text/html; charset=utf-8';
}

header('Content-Type: ' . $ctype);
if ($isHtml && !$isExocadWebview) {
    header('Content-Security-Policy: sandbox; default-src \'none\'');
    header('X-Content-Type-Options: nosniff');
}
if ($isExocadWebview) {
    // sandbox بدون allow-same-origin ⇒ اسکریپت به کوکی/دامنهٔ سایت دسترسی ندارد.
    // allow-scripts لازم است تا رندر سه‌بعدی exocad اجرا شود.
    header("Content-Security-Policy: sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline' 'unsafe-eval'; style-src 'unsafe-inline'; img-src data: blob:; connect-src 'none'; frame-ancestors 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}
header('Content-Disposition: inline; filename="' . basename($file['original_name'] ?? $file['filename']) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
