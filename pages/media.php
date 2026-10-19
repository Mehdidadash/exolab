<?php
// pages/media.php — سرو عمومی عکس‌های نمونه‌کار (portfolio_works)
// فقط پسوندهای تصویری مجاز است و فقط از ریشه‌ی پوشه‌ی آپلود خوانده می‌شود
// (بدون اجازه‌ی پیمایش زیرپوشه‌ها).
//
// توجه: این فایل داخل pages/ است، پس ریشه‌ی پروژه یک پله بالاتر است.
// برای سازگاری با لینک‌های قدیمی، .htaccess مسیر /media.php را هم به اینجا هدایت می‌کند.

$f = basename((string) ($_GET['f'] ?? ''));
if ($f === '' || $f === '.' || $f === '..' || strpbrk($f, "/\\") !== false) {
    http_response_code(400);
    exit;
}

$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
$mimes = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp', 'avif' => 'image/avif',
];
if (!isset($mimes[$ext])) {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);                              // ریشه‌ی پروژه
$candidates = [
    $root . '/uploads/' . $f,                          // مسیر اصلی (public_html/uploads)
    $root . '/assets/uploads/' . $f,                   // مکان قدیمی (گذار)
    dirname($root) . '/uploads/' . $f,                 // هم‌سطح/پشتیبان
];
$path = '';
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $path = $candidate;
        break;
    }
}
if ($path === '') {
    http_response_code(404);
    exit;
}

$real = realpath($path);
if ($real === false || !is_file($real)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mimes[$ext]);
header('Content-Length: ' . filesize($real));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($real);
exit;
