<?php
// panel/serve_doctor_image.php
// عکس گالری «سلیقه/نحوه کار» پزشک که بیرون از ریشه‌ی وب ذخیره شده است.
// دسترسی: همان قواعد صفحه‌ی doctor_view.php + نقش‌های مجاز برای دیدن گالری.

require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();
$doctorId = !empty($_GET['did']) ? (int) $_GET['did'] : 0;
$f = basename((string) ($_GET['f'] ?? ''));
if ($doctorId <= 0 || $f === '' || $f === '.' || $f === '..') {
    http_response_code(400);
    die('درخواست نامعتبر');
}

$isDesigner = is_designer_user($user);
$canAccess = has_role('admin')
    || ($isDesigner && designerCanAccessUser($doctorId))
    || (has_role('doctor') && $doctorId === (int) $user['id'])
    || (has_role('clinic') && canAccessDoctor($doctorId));
$canViewGallery = in_array($user['role'] ?? '', ['admin', 'designer', 'staff', 'secretary'], true) || $isDesigner;

if (!$canAccess || !$canViewGallery) {
    http_response_code(403);
    die('دسترسی غیرمجاز');
}

$path = resolve_upload_path('doctors/' . $f);
if (!is_file($path)) {
    http_response_code(404);
    die('عکس یافت نشد');
}

$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
$mimes = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
];
$ctype = $mimes[$ext] ?? null;
if (!$ctype) {
    http_response_code(403);
    die('نوع فایل مجاز نیست');
}

header('Content-Type: ' . $ctype);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
