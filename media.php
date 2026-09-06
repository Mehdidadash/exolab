<?php
// media.php — سرو عمومی عکس‌های نمونه‌کار (portfolio_works)
// این فایل‌ها بیرون از ریشه‌ی وب (در پوشه‌ی همسطح پروژه) ذخیره می‌شوند،
// پس برای نمایش در سایتِ عمومی از اینجا سرو می‌شوند.
// فقط پسوندهای تصویری مجاز است و فقط از ریشه‌ی پوشه‌ی آپلود خوانده می‌شود
// (بدون اجازه‌ی پیمایش زیرپوشه‌ها).

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

// پوشه‌ی uploads داخلِ ریشه‌ی پروژه (روی هاست: public_html/uploads)
$appRoot = rtrim(__DIR__, '/\\');
$candidates = [
    $appRoot . '/uploads/' . $f,             // مسیر اصلی (داخل ریشه‌ی پروژه)
    $appRoot . '/assets/uploads/' . $f,      // مکان قدیمی (گذار)
    rtrim(dirname(__DIR__), '/\\') . '/uploads/' . $f, // همسطح/پشتیبان
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

header('Content-Type: ' . $mimes[$ext]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
