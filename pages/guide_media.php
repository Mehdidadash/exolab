<?php
// pages/guide_media.php — سروِ امنِ تصاویر «راهنمای عکاسی» از پوشه‌ی uploads/guide
//
// پوشه‌ی uploads/ با .htaccess دسترسی مستقیم را می‌بندد؛ پس تصاویر راهنما از اینجا
// سرو می‌شوند. فقط پسوندهای تصویری مجاز است و مسیر هرگز از uploads/guide خارج نمی‌شود.
//
// توجه: این فایل داخل pages/ است، پس ریشه‌ی پروژه یک پله بالاتر است.
// .htaccess مسیر قدیمی /guide_media.php را به اینجا هدایت می‌کند.

$f = basename((string) ($_GET['f'] ?? ''));
if ($f === '' || $f === '.' || $f === '..' || strpbrk($f, "/\\") !== false) {
    http_response_code(400);
    exit;
}

$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
$mimes = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
    'svg'  => 'image/svg+xml',
];
if (!isset($mimes[$ext])) {
    http_response_code(404);
    exit;
}

$root      = dirname(__DIR__);                        // ریشه‌ی پروژه
$candidates = [
    $root . '/uploads/guide/' . $f,                   // مسیر اصلی (public_html/uploads/guide)
    $root . '/assets/uploads/guide/' . $f,            // مکان قدیمی (گذار)
    dirname($root) . '/uploads/guide/' . $f,          // هم‌سطح/پشتیبان
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
header('Cache-Control: public, max-age=604800, immutable');
header('X-Content-Type-Options: nosniff');
readfile($real);
exit;
