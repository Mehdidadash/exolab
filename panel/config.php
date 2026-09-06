<?php
// config.php

// Load environment variables from .env file
require_once __DIR__ . '/../includes/EnvLoader.php';
EnvLoader::load();

// تنظیمات پایگاه داده و اطلاعات عمومی سایت
define('DB_HOST', EnvLoader::get('DB_HOST', 'localhost'));
define('DB_NAME', EnvLoader::get('DB_NAME', 'exolabir_index'));
define('DB_USER', EnvLoader::get('DB_USER', 'root'));
define('DB_PASS', EnvLoader::get('DB_PASS', ''));

define('BASE_URL', EnvLoader::get('BASE_URL', '/'));
define('SITE_NAME', EnvLoader::get('SITE_NAME', 'لابراتوار دیجیتال اگزولب'));
define('SITE_DESCRIPTION', EnvLoader::get('SITE_DESCRIPTION', 'خدمات لابراتوار دندانسازی دیجیتال، نمونه کار و لیست قیمت'));

define('USER_SESSION_KEY', EnvLoader::get('USER_SESSION_KEY', 'exolab_user_id'));

// کلید اجرای مهاجرت‌ها از طریق وب (database/migrate.php). اگر خالی باشد،
// اجرای وب غیرفعال است و فقط با CLI (php database/migrate.php) کار می‌کند.
define('MIGRATE_KEY', EnvLoader::get('MIGRATE_KEY', ''));

// مرحله ۳: منبع اصلی خوانش قیمت‌ها.
// true  → توابع صدور فاکتور ابتدا از جدول یکپارچه price_links می‌خوانند
//         (design_fee / outsource / specific) و در نبود رکورد به جداول قدیمی برمی‌گردند.
// false → رفتار قدیمی (فقط جداول legacy). برای بازگشت سریع در صورت بروز مشکل.
define('USE_PRICE_LINKS', EnvLoader::get('USE_PRICE_LINKS', '1') === '1');

// ─── پوشه‌ی آپلودها ───
// فایل‌های آپلودی (کیس، گالری پزشک، آپلود کاربر، عکس نمونه‌کار) در پوشه‌ی uploads
// داخلِ ریشه‌ی پروژه ذخیره می‌شوند:
//   لوکال: <project>/uploads          هاست: public_html/uploads
// (ریشه‌ی پروژه = پوشه‌ای که panel/ داخل آن است)
// اگر در هاست مسیر واقعی فرق داشت، مقدار دقیق را با کلید UPLOADS_ROOT در فایل
// .env بدهید (بدون تغییر کد). مثال: UPLOADS_ROOT=/home/username/public_html/uploads
if (!defined('UPLOADS_ROOT')) {
    $uploadsEnv = EnvLoader::get('UPLOADS_ROOT', '');
    if ($uploadsEnv !== '') {
        define('UPLOADS_ROOT', rtrim($uploadsEnv, '/\\'));
    } else {
        define('UPLOADS_ROOT', rtrim(dirname(__DIR__), '/\\') . '/uploads');
    }
}

if (!function_exists('uploads_path')) {
    /** مسیر کامل یک فایل/پوشه در ریشه‌ی آپلود (رشته خالی → خود ریشه). */
    function uploads_path(string $rel = ''): string
    {
        $base = UPLOADS_ROOT;
        if ($rel === '') {
            return $base;
        }
        $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
        return $base . DIRECTORY_SEPARATOR . ltrim($rel, DIRECTORY_SEPARATOR);
    }
}

if (!function_exists('ensure_uploads_dir')) {
    /** ساخت پوشه در ریشه‌ی آپلود (در صورت نبود) و برگرداندن مسیر کامل آن. */
    function ensure_uploads_dir(string $rel): string
    {
        $dir = uploads_path($rel);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

// مسیر قدیمی داخل ریشه‌ی وب (فقط برای خواندن/حذفِ فایل‌های قبلی در دوره‌ی گذار)
if (!defined('LEGACY_UPLOADS_ROOT')) {
    define('LEGACY_UPLOADS_ROOT', rtrim(dirname(__DIR__), '/\\') . '/assets/uploads');
}

if (!function_exists('legacy_uploads_path')) {
    /** مسیر کامل در مکان قدیمی (داخل وب: assets/uploads). */
    function legacy_uploads_path(string $rel = ''): string
    {
        $base = LEGACY_UPLOADS_ROOT;
        if ($rel === '') {
            return $base;
        }
        return $base . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel), DIRECTORY_SEPARATOR);
    }
}

if (!function_exists('resolve_upload_path')) {
    /**
     * مسیر فایل برای خواندن/حذف: اول ریشه‌ی جدید (بیرون وب) و اگر نبود مکان قدیمی
     * (داخل وب) را برمی‌گرداند تا فایل‌های قبلیِ هاست تا زمان انتقال نشکنند.
     * برای نوشتن همیشه از uploads_path()/ensure_uploads_dir() (بیرون وب) استفاده می‌شود.
     */
    function resolve_upload_path(string $rel): string
    {
        $external = uploads_path($rel);
        if (is_file($external)) {
            return $external;
        }
        $legacy = legacy_uploads_path($rel);
        if (is_file($legacy)) {
            return $legacy;
        }
        return $external;
    }
}

function base_url($path = '') {
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}
