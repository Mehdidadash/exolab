<?php
// panel/download_scan_body_library.php
// دانلود فایلِ کتابخانهٔ اسکن‌بادی — دسترسی: مدیر کل، مدیران شعبه و طراح‌ها.
// (فایل‌ها خارج از پوشهٔ وب در storage/scan_body_library ذخیره می‌شوند، پس این
//  اسکریپت نگهبانِ دسترسی است.)
require_once __DIR__ . '/auth.php';
require_login();

if (!canViewScanBodyLibrary()) {
    http_response_code(403);
    die('دسترسی غیرمجاز');
}

$id = (int) ($_GET['id'] ?? 0);
$type = getScanBodyType($id);
if (!$type) {
    http_response_code(404);
    die('نوع اسکن‌بادی یافت نشد.');
}

// اولویت: فایلِ ذخیره‌شده روی سرور؛ در غیر این صورت لینکِ خارجی.
$relPath = trim((string) ($type['library_path'] ?? ''));
if ($relPath !== '') {
    $abs = scanBodyLibraryAbsolutePath($relPath);
    if (!$abs || !is_file($abs)) {
        http_response_code(404);
        die('فایل کتابخانه یافت نشد.');
    }

    $downloadName = trim((string) ($type['library_name'] ?? ''));
    if ($downloadName === '') {
        $downloadName = basename($abs);
    }
    // پسوند را حفظ کن (اگر در نامِ نمایشی نبود)
    if (pathinfo($downloadName, PATHINFO_EXTENSION) === '') {
        $ext = pathinfo($abs, PATHINFO_EXTENSION);
        if ($ext !== '') $downloadName .= '.' . $ext;
    }

    $mime = 'application/octet-stream';
    if (function_exists('mime_content_type')) {
        $detected = @mime_content_type($abs);
        if ($detected) $mime = $detected;
    }

    if (function_exists('audit_log_save')) {
        audit_log_save('scan_body_type', $id, 'دانلود کتابخانه اسکن‌بادی');
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($abs);
    exit;
}

// لینکِ خارجی
$url = trim((string) ($type['library_url'] ?? ''));
if ($url !== '' && preg_match('#^https?://#i', $url)) {
    if (function_exists('audit_log_save')) {
        audit_log_save('scan_body_type', $id, 'باز کردن لینک کتابخانه اسکن‌بادی');
    }
    header('Location: ' . $url, true, 302);
    exit;
}

http_response_code(404);
die('برای این نوع اسکن‌بادی فایل یا لینکی ثبت نشده است.');
