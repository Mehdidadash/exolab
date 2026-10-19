<?php
/**
 * includes/public_bootstrap.php
 *
 * راه‌اندازی مشترک همه‌ی صفحات عمومی سایت (index.php و صفحات پوشه‌ی pages/).
 *
 * هدف: هر صفحه‌ی جدید با کمترین کد تکراری ساخته شود. این فایل:
 *   ۱) محافظِ دیپلوی را اجرا می‌کند (اگر بسته‌ی آپلود ناقص باشد → پیام ۵۰۳ قابل‌فهم، نه صفحه‌ی سفید)
 *   ۲) فایل‌های پایه (db، helpers، contact) را لود می‌کند
 *   ۳) مسیر ریشه‌ی سایت و base URL را تشخیص می‌دهد (برای پوشه‌ی pages/ حیاتی است)
 *   ۴) تنظیمات SEO و صفحه را با پیش‌فرض‌های امن می‌سازد
 *   ۵) توابع کمکی نمایش (رندر هدر/فوتر/متادیتا) را تعریف می‌کند
 *
 * استفاده در یک صفحه‌ی جدید داخل pages/:
 *
 *   <?php
 *   $PAGE = [
 *       'key'         => 'my-service',                       // برای active شدن منو
 *       'path'        => 'pages/my-service.php',
 *       'title'       => 'عنوان صفحه | ' . SITE_NAME,
 *       'description' => 'توضیح متا...',
 *       'keywords'    => 'کلمات, کلیدی',
 *       'h1'          => 'عنوان اصلی',
 *       'lead'        => 'توضیح کوتاه زیر عنوان',
 *       'breadcrumb'  => 'نام صفحه',
 *       'schema'      => [ ... ]                              // اختیاری: JSON-LD
 *   ];
 *   require __DIR__ . '/../includes/public_bootstrap.php';
 *   ?>
 *   ... محتوای صفحه ...
 *   <?php public_footer_and_scripts(); ?>
 *
 * صفحه‌ی جدید را در آرایه‌ی $PUBLIC_PAGES (پایین همین فایل) ثبت کنید تا منو
 * و لینک‌های بین‌صفحه‌ای خودکار به‌روز شوند.
 */

// ─────────────────────────────────────────────────────────────
// ۱) محافظِ دیپلوی
// ─────────────────────────────────────────────────────────────
$__required = [
    __DIR__ . '/../panel/db.php',
    __DIR__ . '/../includes/helpers.php',
];
$__missing = [];
foreach ($__required as $__f) {
    if (!is_file($__f)) {
        $__missing[] = $__f;
    }
}
if ($__missing) {
    error_log('public_bootstrap.php: missing required files: ' . implode(', ', $__missing));
    http_response_code(503);
    @ini_set('display_errors', '0');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8">'
        . '<title>سایت در دسترس نیست</title>'
        . '<body style="font-family:Tahoma,sans-serif;background:#f7f7f9;margin:0;padding:60px 20px;text-align:center">'
        . '<h1 style="color:#c0392b">⚠️ سایت موقتاً در دسترس نیست</h1>'
        . '<p>نسخه‌ی برنامه روی سرور کامل نیست. لطفاً چند دقیقه بعد دوباره تلاش کنید.</p>'
        . '</body></html>';
    exit;
}
unset($__required, $__missing, $__f);

require_once __DIR__ . '/../panel/db.php';
require_once __DIR__ . '/../includes/helpers.php';

// ─────────────────────────────────────────────────────────────
// ۲) تشخیص ریشه‌ی سایت و URL پایه
// ─────────────────────────────────────────────────────────────
if (!defined('PUBLIC_ROOT_DIR')) {
    // ریشه‌ی پروژه = پوشه‌ای که این فایل داخل includes/ آن است.
    define('PUBLIC_ROOT_DIR', dirname(__DIR__));
}

if (!function_exists('public_base_url')) {
    /**
     * URL پایه‌ی سایت (بدون اسلش انتهایی). مثال: https://exolab.ir  یا  http://localhost/exolab
     * همه‌ی لینک‌های عمومی (includes و صفحات pages/) باید از این تابع ساخته شوند
     * تا مستقل از عمق صفحه (ریشه یا pages/) درست کار کنند.
     */
    function public_base_url(): string
    {
        static $base = null;
        if ($base !== null) {
            return $base;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // مسیر ریشه‌ی اپ نسبت به DocumentRoot (لوکال: /exolab ، هاست: '')
        $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $rootOnDisk = str_replace('\\', '/', PUBLIC_ROOT_DIR);
        $docRoot = isset($_SERVER['DOCUMENT_ROOT'])
            ? rtrim(str_replace('\\', '/', (string) $_SERVER['DOCUMENT_ROOT']), '/')
            : '';

        $appPath = '';
        if ($docRoot !== '' && $rootOnDisk !== '' && str_starts_with($rootOnDisk, $docRoot)) {
            $appPath = substr($rootOnDisk, strlen($docRoot)); // مثال: /exolab  یا ''
        } elseif ($script !== '/' && $script !== '.') {
            // اگر با DocumentRoot هم‌پوشان نبود، از مسیر اسکریپت جاری به ریشه برمی‌گردیم
            $appPath = $script;
            if (str_ends_with($appPath, '/pages')) {
                $appPath = substr($appPath, 0, -6);
            }
        }

        $base = $scheme . '://' . $host . rtrim($appPath, '/');
        return $base;
    }
}

if (!function_exists('public_url')) {
    /** ساخت URL مطلق از یک مسیر نسبی به ریشه‌ی سایت. */
    function public_url(string $path = ''): string
    {
        return public_base_url() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('public_asset')) {
    /**
     * URL یک فایل استاتیک (css/js/icon). مسیرها نسبت به ریشه‌ی پروژه‌اند
     * و از ریشه ساخته می‌شوند، پس در صفحات pages/ هم درست کار می‌کنند.
     */
    function public_asset(string $path): string
    {
        return public_url($path) . '?v=' . PUBLIC_ASSET_VERSION;
    }
}

if (!defined('PUBLIC_ASSET_VERSION')) {
    /** نسخه‌ی دارایی‌های استاتیک برای شکستن کش مرورگر پس از تغییر. */
    define('PUBLIC_ASSET_VERSION', '2');
}

// ─────────────────────────────────────────────────────────────
// ۳) رجیستری صفحات عمومی (منبع واحد حقیقت)
// ─────────────────────────────────────────────────────────────
/**
 * همه‌ی صفحات عمومی اینجا ثبت می‌شوند. با افزودن یک آیتم، لینک‌های
 * «خدمات مرتبط» و منوی کشویی خدمات خودکار به‌روز می‌شوند.
 *   key   → شناسه‌ی یکتا برای تشخیص صفحه‌ی جاری (active)
 *   url   → مسیر تمیز (clean URL) نسبت به ریشه
 *   label → عنوان نمایشی
 *   sub   → توضیح کوتاه برای کارت‌های لینک
 *   in_menu → آیا در منوی کشویی «خدمات» نشان داده شود
 */
$GLOBALS['PUBLIC_PAGES'] = [
    'scanner-guide' => [
        'key' => 'scanner-guide', 'url' => 'index.php#scanner-guide',
        'label' => 'راهنمای آماده‌سازی اسکن', 'sub' => 'چک‌لیست پیش از حضور اپراتور اسکن',
        'in_menu' => false,
    ],
    'photography-guide' => [
        'key' => 'photography-guide', 'url' => 'pages/photography-guide.php',
        'label' => 'راهنمای عکاسی فول ماوث', 'sub' => 'عکس‌های لازم برای قدام فک بالا و فول ماوث',
        'in_menu' => true,
    ],
    'aligner' => [
        'key' => 'aligner', 'url' => 'pages/aligner.php',
        'label' => 'الاینر شفاف', 'sub' => 'همکاری با دندانپزشکان عمومی بدون نیاز به تخصص ارتودنسی',
        'in_menu' => true,
    ],
    'prices' => [
        'key' => 'prices', 'url' => 'index.php#prices',
        'label' => 'لیست قیمت خدمات', 'sub' => 'قیمت‌های به‌روز از پنل مدیریت',
        'in_menu' => false,
    ],
    'contact' => [
        'key' => 'contact', 'url' => 'index.php#contact',
        'label' => 'تماس و هماهنگی', 'sub' => 'تلفن اسلامشهر و قزوین',
        'in_menu' => false,
    ],
];

if (!function_exists('public_page_url')) {
    /** URL یک صفحه‌ی ثبت‌شده با کلید یا مسیر. */
    function public_page_url(string $keyOrPath): string
    {
        $pages = $GLOBALS['PUBLIC_PAGES'] ?? [];
        $path = $pages[$keyOrPath]['url'] ?? $keyOrPath;
        return public_url($path);
    }
}

// ─────────────────────────────────────────────────────────────
// ۴) اطلاعات پایه‌ی صفحه (پیش‌فرض + override صفحه)
// ─────────────────────────────────────────────────────────────
$contact = require __DIR__ . '/../contact.php';

$PAGE = array_merge([
    'key'         => '',
    'path'        => '',
    'title'       => SITE_NAME,
    'description' => defined('SITE_DESCRIPTION') ? SITE_DESCRIPTION : '',
    'keywords'    => '',
    'robots'      => 'index, follow, max-image-preview:large',
    'h1'          => '',
    'lead'        => '',
    'breadcrumb'  => '',
    'canonical'   => '',
    'schema'      => [],
    'og_type'     => 'website',
], $PAGE ?? []);

if ($PAGE['canonical'] === '') {
    $PAGE['canonical'] = public_url($PAGE['path']);
}

// ─────────────────────────────────────────────────────────────
// ۵) توابع نمایش مشترک
// ─────────────────────────────────────────────────────────────
if (!function_exists('public_head')) {
    /** چاپ متادیتای <head> بر اساس $PAGE. */
    function public_head(): void
    {
        global $PAGE;
        $t = htmlspecialchars($PAGE['title']);
        $d = htmlspecialchars($PAGE['description']);
        $c = htmlspecialchars($PAGE['canonical']);
        $img = htmlspecialchars(public_url('assets/icons/EXOLAB_LOGO_FULL.svg'));
        $site = htmlspecialchars(SITE_NAME);
        ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="<?= public_url('assets/icons/favicon_io/favicon.ico') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= public_url('assets/icons/favicon_io/favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= public_url('assets/icons/favicon_io/favicon-16x16.png') ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= public_url('assets/icons/favicon_io/apple-touch-icon.png') ?>">
    <link rel="manifest" href="<?= public_url('assets/icons/favicon_io/site.webmanifest') ?>">

    <title><?= $t ?></title>
    <meta name="description" content="<?= $d ?>">
    <?php if ($PAGE['keywords'] !== ''): ?>
    <meta name="keywords" content="<?= htmlspecialchars($PAGE['keywords']) ?>">
    <?php endif; ?>
    <meta name="robots" content="<?= htmlspecialchars($PAGE['robots']) ?>">
    <meta name="author" content="<?= $site ?>">
    <link rel="canonical" href="<?= $c ?>">

    <meta property="og:type" content="<?= htmlspecialchars($PAGE['og_type']) ?>">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:site_name" content="<?= $site ?>">
    <meta property="og:title" content="<?= $t ?>">
    <meta property="og:description" content="<?= $d ?>">
    <meta property="og:url" content="<?= $c ?>">
    <meta property="og:image" content="<?= $img ?>">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= $t ?>">
    <meta name="twitter:description" content="<?= $d ?>">
        <?php
    }
}

if (!function_exists('public_header')) {
    /** چاپ هدر مشترک سایت. */
    function public_header(): void
    {
        global $PAGE;
        $current = $PAGE['key'] ?? '';

        $links = [
            ['url' => 'index.php#scanner-guide',      'label' => 'اسکنر',        'key' => 'scanner-guide'],
            ['url' => 'index.php#prices',             'label' => 'لیست قیمت',    'key' => 'prices'],
            ['url' => 'index.php#works',              'label' => 'نمونه کار',    'key' => 'works'],
            ['url' => 'pages/photography-guide.php',  'label' => 'راهنمای عکاسی','key' => 'photography-guide'],
            ['url' => 'pages/aligner.php',            'label' => 'الاینر شفاف',  'key' => 'aligner'],
            ['url' => 'index.php#contact',            'label' => 'تماس',         'key' => 'contact'],
        ];

        $menuPages = array_filter(
            $GLOBALS['PUBLIC_PAGES'] ?? [],
            static fn(array $p): bool => !empty($p['in_menu'])
        );
        ?>
<header class="site-header">
    <div class="container">
        <a class="brand" href="<?= public_url('index.php') ?>">
            <img src="<?= public_url('assets/icons/EXOLAB_LOGO_FULL.svg') ?>" alt="<?= htmlspecialchars(SITE_NAME) ?>" class="site-logo">
            <span><?= htmlspecialchars(SITE_NAME) ?></span>
        </a>
        <button class="menu-toggle" onclick="event.stopPropagation(); toggleMobileMenu(event)">
            <img src="<?= public_url('assets/icons/menu-icon.svg') ?>" alt="منو" class="icon-svg">
        </button>
        <nav class="site-nav">
            <?php foreach ($links as $l): ?>
                <a href="<?= public_url($l['url']) ?>"
                   class="nav-link<?= $current === $l['key'] ? ' active' : '' ?>"><?= htmlspecialchars($l['label']) ?></a>
            <?php endforeach; ?>
            <?php if ($menuPages): ?>
                <div class="nav-group">
                    <button type="button" class="nav-group-toggle" onclick="event.stopPropagation(); toggleNavGroup(this)">
                        خدمات
                        <span class="caret">▾</span>
                    </button>
                    <div class="nav-group-menu">
                        <span class="menu-label">صفحات خدمات</span>
                        <?php foreach ($menuPages as $p): ?>
                            <a href="<?= public_url($p['url']) ?>"><?= htmlspecialchars($p['label']) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </nav>
    </div>
</header>
        <?php
    }
}

if (!function_exists('public_footer')) {
    /** چاپ فوتر مشترک سایت. */
    function public_footer(): void
    {
        global $contact;
        ?>
<footer class="site-footer">
    <div class="container footer-content">
        <div>
            <p>© <?= date('Y') ?> <?= htmlspecialchars(SITE_NAME) ?></p>
            <p><a href="<?= public_url('panel/login.php') ?>">ورود مدیر</a></p>
        </div>
        <div>
            <p>تلفن: <strong><a href="tel:<?= htmlspecialchars($contact['phone_eslamshahr'] ?? '') ?>" style="color:#f5c518;"><?= htmlspecialchars($contact['phone_eslamshahr'] ?? '') ?></a></strong></p>
        </div>
    </div>
</footer>
        <?php
    }
}

if (!function_exists('public_schema')) {
    /** چاپ JSON-LD صفحه (در صورت وجود). */
    function public_schema(): void
    {
        global $PAGE;
        if (empty($PAGE['schema'])) {
            return;
        }
        echo '<script type="application/ld+json">'
            . json_encode($PAGE['schema'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            . '</script>';
    }
}

if (!function_exists('public_scripts')) {
    /** چاپ اسکریپت‌های مشترک. */
    function public_scripts(): void
    {
        echo '<script src="' . public_asset('assets/js/main.js') . '"></script>';
        echo '<script src="' . public_asset('assets/js/lightbox.js') . '"></script>';
    }
}

if (!function_exists('public_footer_and_scripts')) {
    /** پایان‌بندی استاندارد یک صفحه: فوتر + اسکریپت‌ها + اسکیما + بستن تگ‌ها. */
    function public_footer_and_scripts(): void
    {
        public_footer();
        public_scripts();
        public_schema();
        echo "\n</body>\n</html>\n";
    }
}

if (!function_exists('public_breadcrumb_schema')) {
    /** ساخت اسکیمای BreadcrumbList استاندارد از مسیر صفحه. */
    function public_breadcrumb_schema(string $name, string $url = ''): array
    {
        $url = $url !== '' ? $url : public_base_url() . ($_SERVER['REQUEST_URI'] ?? '/');
        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'خانه', 'item' => public_url('index.php')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $name, 'item' => $url],
            ],
        ];
    }
}
