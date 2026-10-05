<?php
// index.php

/**
 * محافظِ دیپلوی: روی هاست اگر بسته‌ی آپلود ناقص باشد (مثلاً پوشه‌ی panel/ یا فایل db.php
 * آپلود نشده باشد) اینجا به‌جای Fatal error و صفحهٔ سفید/۵۰۰، یک پیام قابل‌فهم و
 * قابل‌دیباگ نشان می‌دهیم. کاربر عادی فقط پیام ۵۰۳ می‌بیند؛ جزئیات مسیرها فقط برای
 * مدیر (لاگین‌شده) نمایش داده می‌شود.
 */
$__requiredFiles = [
    __DIR__ . '/panel/db.php',
    __DIR__ . '/includes/helpers.php',
];
$__missingFiles = [];
foreach ($__requiredFiles as $__f) {
    if (!is_file($__f)) {
        $__missingFiles[] = $__f;
    }
}

if ($__missingFiles) {
    error_log('index.php: missing required files: ' . implode(', ', $__missingFiles));
    http_response_code(503);
    @ini_set('display_errors', '0');
    $__details = '';
    require_once __DIR__ . '/panel/config.php';
    require_once __DIR__ . '/panel/auth.php';
    if (function_exists('is_admin') && is_admin()) {
        $__details = '<ul style="text-align:left;direction:ltr;font-size:13px">'
            . '<li>root = <code>' . htmlspecialchars(__DIR__) . '</code></li>'
            . '<li>panel/ exists = <code>' . (is_dir(__DIR__ . '/panel') ? 'yes' : 'NO') . '</code></li>'
            . '<li>missing = <code>' . htmlspecialchars(implode(' | ', $__missingFiles)) . '</code></li>'
            . '</ul>';
    }
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8">'
        . '<title>سایت در دسترس نیست</title>'
        . '<body style="font-family:Tahoma,sans-serif;background:#f7f7f9;margin:0;padding:60px 20px;text-align:center">'
        . '<h1 style="color:#c0392b">⚠️ سایت موقتاً در دسترس نیست</h1>'
        . '<p>نسخه‌ی برنامه روی سرور کامل نیست. لطفاً چند دقیقه بعد دوباره تلاش کنید.'
        . ' در صورت ادامه، به پشتیبانی اطلاع دهید.</p>' . $__details
        . '</body></html>';
    exit;
}
unset($__requiredFiles, $__missingFiles, $__f, $__details);

require_once __DIR__ . '/panel/db.php';
require_once __DIR__ . '/includes/helpers.php';
$prices = getPrices();
$works = getPortfolioWorks();
$contact = require __DIR__ . '/contact.php';

// ─── قیمت‌های الاینر شفاف از دیتابیس ───
// شناسه‌های خدمات الاینر در کاتالوگ مشترک (site_prices):
//   ۱۶ = «طرح درمان الاینر شفاف»
//   ۱۷ = «الاینر شفاف» (هر عدد)
// قیمت‌ها از دیتابیس خوانده می‌شوند تا با ویرایش در پنل، همین صفحه هم به‌روز شود.
const ALIGNER_PLAN_PRICE_ID  = 16;
const ALIGNER_STAGE_PRICE_ID = 17;

/**
 * قیمت یک خدمت را از دیتابیس برمی‌گرداند (با احتساب قیمت اختصاصی شعبه در صورت وجود).
 * در صورت نبود رکورد، مقدار جایگزین (fallback) برگردانده می‌شود.
 */
function alignerServicePrice(int $serviceId, float $fallback): float
{
    $service = getPrice($serviceId);
    if (!$service) {
        return $fallback;
    }
    $custom = getBranchServiceCustomPrice($serviceId);
    if ($custom !== null) {
        return $custom;
    }
    return (float) $service['price'];
}

/** عنوان خدمت از دیتابیس (برای هم‌نام‌بودن عنوان سایت و پنل)، با مقدار پیش‌فرض. */
function alignerServiceTitle(int $serviceId, string $fallback): string
{
    $service = getPrice($serviceId);
    $title = trim((string) ($service['title'] ?? ''));
    return $title !== '' ? $title : $fallback;
}

$alignerPlanPrice  = alignerServicePrice(ALIGNER_PLAN_PRICE_ID, 6000000);
$alignerStagePrice = alignerServicePrice(ALIGNER_STAGE_PRICE_ID, 1200000);
$alignerPlanTitle  = alignerServiceTitle(ALIGNER_PLAN_PRICE_ID, 'طرح درمان الاینر شفاف');
$alignerStageTitle = alignerServiceTitle(ALIGNER_STAGE_PRICE_ID, 'الاینر شفاف');

$alignerPlanDesc  = 'بررسی عکس‌ها و مدل دیجیتال، طراحی مراحل حرکت دندان‌ها و تایید نهایی طرح درمان توسط دندانپزشک باسابقه ارتودنسی.';
$alignerStageDesc = 'ساخت و ارسال هر عدد الاینر، پس از تایید طرح درمان و انجام اسکن داخل دهانی.';

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Favicons -->
    <link rel="icon" type="image/x-icon" href="assets/icons/favicon_io/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon_io/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/icons/favicon_io/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/icons/favicon_io/apple-touch-icon.png">
    <link rel="manifest" href="assets/icons/favicon_io/site.webmanifest">
<?php
// ─── اطلاعات پایه برای SEO ───
$siteName  = SITE_NAME;
$siteTitle = 'لابراتوار دیجیتال دندانسازی اگزولب | اسکن داخل دهانی، میلینگ سنتری و الاینر شفاف';
$siteDesc  = 'EXOLAB ارائه‌دهنده خدمات لابراتوار دیجیتال دندانسازی، اسکن داخل دهانی، میلینگ سنتری و درمان الاینر شفاف در اسلامشهر و قزوین — همراه با لیست قیمت و نمونه کار.';
$siteKeywords = 'لابراتوار دیجیتال دندانسازی, اسکن داخل دهانی, میلینگ سنتری, الاینر شفاف, ارتودنسی نامرئی, لابراتوار دندانسازی اسلامشهر, لابراتوار دندانسازی قزوین, CAD CAM دندانپزشکی, زیرکونیا, لمینیت ای مکس, اگزولب, EXOLAB';
$canonical = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . strtok($_SERVER['REQUEST_URI'] ?? '/index.php', '?');
?>
    <title><?= htmlspecialchars($siteTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($siteDesc) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($siteKeywords) ?>">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <meta name="author" content="<?= htmlspecialchars($siteName) ?>">
    <link rel="canonical" href="<?= htmlspecialchars($canonical) ?>">

    <!-- Open Graph (اشتراک‌گذاری در شبکه‌های اجتماعی و نمایش بهتر در گوگل) -->
    <meta property="og:type" content="website">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:site_name" content="<?= htmlspecialchars($siteName) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($siteTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($siteDesc) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($canonical) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($canonical) ?>assets/icons/EXOLAB_LOGO_FULL.svg">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($siteTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($siteDesc) ?>">

    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/lightbox.css">
    <style>
        /* استایل‌های اختصاصی بخش «الاینر شفاف» — در صورت تمایل می‌توانید این بخش را به assets/css/style.css منتقل کنید */
        #aligner .aligner-flow { margin: 8px 0 36px; }
        #aligner .aligner-flow-svg { display: block; width: 100%; height: auto; color: inherit; }
        #aligner h3 { margin-top: 0; }
        #aligner .section-lead { max-width: 760px; margin: 0 auto 28px; text-align: center; line-height: 2; }

        /* ── مراحل همکاری: کارت‌های عمودی، خوانا در موبایل و دسکتاپ ── */
        #aligner .aligner-steps {
            list-style: none;
            max-width: 860px;
            margin: 0 auto 44px;
            padding: 0;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            counter-reset: aligner-step;
        }
        #aligner .aligner-steps li {
            position: relative;
            background: #fff;
            border: 1px solid #E5E7EB;
            border-radius: 14px;
            padding: 22px 20px 20px;
            line-height: 1.95;
            font-size: .97rem;
            box-shadow: 0 8px 24px rgba(15, 92, 186, .04);
            counter-increment: aligner-step;
        }
        #aligner .aligner-steps li::before {
            content: counter(aligner-step, persian);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            margin-bottom: 12px;
            border-radius: 50%;
            background: #f5c518;
            color: #1a1a1a;
            font-size: 1rem;
            font-weight: 700;
        }
        #aligner .aligner-steps li strong {
            display: block;
            margin-bottom: 6px;
            font-size: 1.02rem;
            color: #0F172A;
        }
        #aligner .aligner-steps li .step-time {
            display: inline-block;
            margin-top: 10px;
            padding: 4px 10px;
            border-radius: 999px;
            background: #eef6ff;
            color: #0f5cba;
            font-size: .8rem;
            font-weight: 700;
        }

        #aligner .aligner-cases {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin: 20px 0 12px;
        }
        #aligner .case-col {
            border: 1px solid rgba(0, 0, 0, .12);
            border-top: 4px solid #2e9e5b;
            border-radius: 10px;
            padding: 22px 24px;
        }
        #aligner .case-col.caution { border-top-color: #c98f00; }
        #aligner .case-col h4 {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 14px;
            font-size: 1.05rem;
        }
        #aligner .case-col ul {
            margin: 0;
            padding-inline-start: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            line-height: 1.8;
        }
        #aligner .aligner-hint {
            max-width: 760px;
            margin: 22px auto 0;
            font-size: .95rem;
            opacity: .85;
            line-height: 2;
        }
        #aligner .aligner-pricing { margin-top: 40px; }
        #aligner .aligner-note {
            max-width: 760px;
            margin: 18px auto 0;
            padding: 16px 18px;
            border-radius: 10px;
            background: rgba(245, 197, 24, .1);
            font-size: .95rem;
            line-height: 2;
        }

        /* ── پرسش‌های متداول (FAQ) ── */
        #aligner .aligner-faq {
            max-width: 860px;
            margin: 44px auto 0;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        #aligner .aligner-faq details {
            background: #fff;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            padding: 4px 18px;
        }
        #aligner .aligner-faq summary {
            cursor: pointer;
            padding: 14px 0;
            font-weight: 700;
            line-height: 1.9;
            list-style: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        #aligner .aligner-faq summary::-webkit-details-marker { display: none; }
        #aligner .aligner-faq summary::after {
            content: '+';
            flex: 0 0 auto;
            font-size: 1.25rem;
            color: #0f5cba;
            line-height: 1;
        }
        #aligner .aligner-faq details[open] summary::after { content: '−'; }
        #aligner .aligner-faq p {
            margin: 0 0 16px;
            line-height: 2;
            font-size: .95rem;
            color: #334155;
        }

        /* ── خدمات مرتبط: کارت‌های کوتاه برای معرفی سایر خدمات ── */
        #aligner .aligner-links {
            max-width: 860px;
            margin: 36px auto 0;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }
        #aligner .aligner-links a {
            display: block;
            background: #fff;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            padding: 16px 18px;
            font-weight: 700;
            line-height: 1.9;
            transition: border-color .15s, box-shadow .15s;
        }
        #aligner .aligner-links a:hover {
            border-color: #06B6D4;
            box-shadow: 0 10px 26px rgba(6, 182, 212, .14);
        }
        #aligner .aligner-links span {
            display: block;
            margin-top: 4px;
            font-size: .85rem;
            font-weight: 400;
            color: #64748b;
        }

        @media (max-width: 900px) {
            #aligner .aligner-steps { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 760px) {
            #aligner .aligner-cases { grid-template-columns: 1fr; }
            #aligner .aligner-steps { grid-template-columns: 1fr; gap: 12px; }
            #aligner .aligner-steps li { padding: 18px 16px; font-size: .94rem; }
            #aligner .case-col { padding: 18px 16px; }
            #aligner .aligner-faq details { padding: 2px 14px; }
        }
    </style>
</head>
<body>
<header class="site-header">
    <div class="container">
        <a class="brand" href="#home">
            <img src="assets/icons/EXOLAB_LOGO_FULL.svg" alt="<?= SITE_NAME ?>" class="site-logo">
            <span><?= SITE_NAME ?></span>
        </a>
        <button class="menu-toggle" onclick="event.stopPropagation(); toggleMobileMenu(event)">
            <img src="assets/icons/menu-icon.svg" alt="menu" class="icon-svg">
        </button>
        <nav class="site-nav">
            <a href="#scanner-guide">اسکنر</a>
            <a href="#services">خدمات</a>
            <a href="#prices">لیست قیمت</a>
            <a href="#works">نمونه کار</a>
            <a href="#aligner">الاینر شفاف</a>
            <a href="#contact">تماس</a>
        </nav>
    </div>
</header>
<main>
    <section id="home" class="hero">
        <div class="container hero-content">
            <h1>خدمات دیجیتال برای دندانپزشکی و لابراتوار</h1>
            <p>خدمات میلینگ سنتری، لابراتوار دندانسازی دیجیتال و اسکن داخل دهانی برای مطب‌ها.</p>
            <a class="btn" href="#contact">تماس با ما</a>
        </div>
    </section>
        <section class="section" id="scanner-guide">
    <div class="container">
    <h2>راهنمای آماده‌سازی بیمار برای اسکن داخل دهانی</h2>

    <p>
        به منظور ثبت دقیق‌تر اسکن و ارائه بهترین نتیجه، لطفاً پیش از حضور اپراتور اسکن موارد زیر را مدنظر قرار دهید.
    </p>

    <div class="grid">

        <div class="card">
            <h3>1. هماهنگی زمان اسکن</h3>
            <p>
                پیش از تعیین وقت بیمار، زمان حضور اپراتور اسکن را با EXOLAB هماهنگ فرمایید.
            </p>
        </div>

        <div class="card">
            <h3>2. کنترل خونریزی و شرایط بافت نرم</h3>
            <p>
                اسکنرهای داخل دهانی در حضور خونریزی فعال یا رطوبت بیش از حد، دقت کمتری دارند.
                لطفاً پیش از اسکن از کنترل خونریزی، التهاب لثه و شرایط مناسب بافت نرم اطمینان حاصل فرمایید.
            </p>
        </div>

        <div class="card">
            <h3>3. نخ‌گذاری لثه</h3>
            <p>
                در مواردی که نمایش دقیق مارجین ضروری است، توصیه می‌شود پیش از حضور اپراتور،
                نخ‌گذاری لثه توسط دندانپزشک انجام شده باشد تا بیمار در زمان اسکن آماده باشد.
                در صورت امکان از انتخاب سایزهای بسیار بزرگ نخ اجتناب شود.
            </p>
        </div>

        <div class="card">
            <h3>4. موارد ایمپلنت</h3>
            <p>
                برای اسکن موارد ایمپلنت، لطفاً حداقل یک روز قبل از مراجعه بیمار،
                اطلاعات سیستم ایمپلنت شامل برند، نوع کانکشن و شماره دندان به EXOLAB اعلام شود.
            </p>

            <hr>

            <strong>نمونه:</strong>

            <ul>
                <li>دندان ۵ فک بالا – Dio Regular</li>
                <li>دندان ۶ فک پایین – Straumann NC</li>
            </ul>
        </div>

        <div class="card">
            <h3>5. تطابق اطلاعات ایمپلنت</h3>
            <p>
                صحت اطلاعات اعلام‌شده در خصوص سیستم ایمپلنت بر عهده پزشک معالج است.
                در صورت عدم تطابق سیستم موجود با اطلاعات ارسال‌شده، انجام اسکن ممکن است
                با تأخیر مواجه شده یا امکان‌پذیر نباشد.
            </p>
        </div>

        <div class="card">
            <h3>6. حضور به‌موقع بیمار</h3>
            <p>
                لطفاً بیمار در زمان تعیین‌شده در مطب حضور داشته باشد.
                تأخیر در حضور بیمار ممکن است باعث تغییر زمان‌بندی اسکن و ایجاد اختلال
                در برنامه مراجعه سایر بیماران شود.
            </p>
        </div>

    </div>

</div>

</section>
    <section id="services" class="section">
        <div class="container">
            <h2>خدمات ما</h2>
            <div class="grid">
                <div class="card">
                    <h3>لابراتوار دیجیتال دندانسازی</h3>
                    <p>طراحی و ساخت ترمیم‌های ثابت و متحرک با استفاده از فناوری CAD/CAM و فرآیندهای دیجیتال.</p>
                </div>
                <div class="card">
                    <h3>اسکن داخل دهانی</h3>
                    <p>ارائه خدمات اسکن داخل دهانی در مطب دندانپزشکان برای ثبت دقیق اطلاعات و حذف مراحل قالب‌گیری سنتی.</p>
                </div>
                <div class="card">
                    <h3>خدمات میلینگ سنتری</h3>
                    <p>ساخت فریم‌ها و ترمیم‌های دیجیتال برای لابراتوارهای همکار با دقت بالا و زمان تحویل مناسب.</p>
                </div>
            </div>
        </div>
    </section>
    <section id="prices" class="section alt">
        <div class="container">
            <h2>لیست قیمت</h2>
            <p>لیست قیمت‌ها از پایگاه داده خوانده می‌شود تا مدیر سایت بتواند به راحتی آن را ویرایش کند.</p>
            <?php if (count($prices) === 0): ?>
                <p class="empty">هنوز قیمت ثبت نشده است. لطفاً در پنل مدیریت اضافه کنید.</p>
            <?php else: ?>
                <div class="price-grid">
                    <?php foreach ($prices as $item): ?>
                        <article class="price-card">
                            <h3><?= htmlspecialchars($item['title']) ?></h3>
                            <p><?= nl2br(htmlspecialchars($item['description'])) ?></p>
                            <span class="price"><?= formatAmountToman($item['price']) ?></span>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <section id="works" class="section">
        <div class="container">
            <h2>نمونه کار</h2>
            <p style="text-align: center; margin-bottom: 32px;">نمونه‌ای از ترمیم‌ها و درمان‌های انجام‌شده با همکاری دندانپزشکان و لابراتوار.</p>
            <?php if (count($works) === 0): ?>
                <div class="lightbox-gallery" style="grid-template-columns: 1fr;">
                    <div class="placeholder">هنوز نمونه کاری ثبت نشده است. لطفاً از پنل مدیریت اضافه کنید.</div>
                </div>
            <?php else: ?>
                <div class="lightbox-gallery" id="works-gallery">
                    <?php foreach ($works as $work): ?>
                        <div class="lightbox-item">
                            <img src="media.php?f=<?= rawurlencode($work['image_filename']) ?>" alt="<?= htmlspecialchars($work['title']) ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
        <section id="aligner" class="section alt">
        <div class="container">
            <h2>الاینر شفاف؛ فرصت همکاری برای دندانپزشکان عمومی</h2>
            <p class="section-lead">
                EXOLAB به دندانپزشکان عمومی و کلینیک‌های همکار در اسلامشهر و قزوین این امکان را می‌دهد تا بدون نیاز به تخصص ارتودنسی، درمان الاینر شفاف را در مطب خود ارائه دهند.
                طرح درمان هر بیمار پیش از ساخت، توسط دندانپزشکی با سابقه بالا در درمان‌های ارتودنسی بررسی و تایید می‌شود.
            </p>

            <div class="aligner-flow">
                <svg class="aligner-flow-svg" viewBox="0 0 1040 170" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="پنج مرحله همکاری: ارسال عکس بیمار، تایید طرح درمان، هماهنگی اسکن داخل دهانی، ارسال مراحل الاینر، ویزیت هر دو هفته">
                    <title>مراحل همکاری در درمان الاینر شفاف</title>
                    <line x1="1000" y1="60" x2="80" y2="60" stroke="#f5c518" stroke-width="3" stroke-dasharray="2 10" stroke-linecap="round" opacity=".6"/>
                    <path d="M848 52 L832 60 L848 68 Z" fill="#f5c518"/>
                    <path d="M648 52 L632 60 L648 68 Z" fill="#f5c518"/>
                    <path d="M448 52 L432 60 L448 68 Z" fill="#f5c518"/>
                    <path d="M248 52 L232 60 L248 68 Z" fill="#f5c518"/>

                    <g>
                        <circle cx="960" cy="60" r="40" fill="#f5c518"/>
                        <text x="960" y="71" text-anchor="middle" font-size="28" font-weight="700" fill="#1a1a1a">۱</text>
                        <text x="960" y="128" text-anchor="middle" font-size="17" font-weight="600" fill="currentColor">ارسال عکس بیمار</text>
                    </g>
                    <g>
                        <circle cx="740" cy="60" r="40" fill="#f5c518"/>
                        <text x="740" y="71" text-anchor="middle" font-size="28" font-weight="700" fill="#1a1a1a">۲</text>
                        <text x="740" y="128" text-anchor="middle" font-size="17" font-weight="600" fill="currentColor">تایید طرح درمان</text>
                    </g>
                    <g>
                        <circle cx="540" cy="60" r="40" fill="#f5c518"/>
                        <text x="540" y="71" text-anchor="middle" font-size="28" font-weight="700" fill="#1a1a1a">۳</text>
                        <text x="540" y="128" text-anchor="middle" font-size="17" font-weight="600" fill="currentColor">هماهنگی اسکن</text>
                    </g>
                    <g>
                        <circle cx="340" cy="60" r="40" fill="#f5c518"/>
                        <text x="340" y="71" text-anchor="middle" font-size="28" font-weight="700" fill="#1a1a1a">۴</text>
                        <text x="340" y="128" text-anchor="middle" font-size="17" font-weight="600" fill="currentColor">ارسال مراحل الاینر</text>
                    </g>
                    <g>
                        <circle cx="120" cy="60" r="40" fill="#f5c518"/>
                        <text x="120" y="71" text-anchor="middle" font-size="28" font-weight="700" fill="#1a1a1a">۵</text>
                        <text x="120" y="128" text-anchor="middle" font-size="17" font-weight="600" fill="currentColor">ویزیت هر دو هفته</text>
                    </g>
                </svg>
            </div>

            <ol class="aligner-steps">
                <li>
                    <strong>ارسال عکس بیمار</strong>
                    عکس‌های بالینی بیمار (نمای روبه‌رو، نیم‌رخ و داخل دهانی) را برای تیم EXOLAB ارسال می‌کنید.
                </li>
                <li>
                    <strong>تایید طرح درمان</strong>
                    طرح درمان اختصاصی بر اساس عکس‌ها طراحی و توسط دندانپزشکی با سابقه بالا در درمان ارتودنسی بررسی و تایید می‌شود.
                </li>
                <li>
                    <strong>هماهنگی اسکن داخل دهانی</strong>
                    پس از تایید طرح درمان، زمان حضور اپراتور اسکن داخل دهانی با مطب شما هماهنگ می‌شود. اسکن دقیق، پایه‌ی ساخت الاینرهایی است که کاملاً روی دندان‌های بیمار می‌نشینند.
                </li>
                <li>
                    <strong>ارسال مراحل الاینر</strong>
                    پس از انجام اسکن و آماده‌سازی مدل دیجیتال، تمام مراحل الاینر بیمار ظرف یک تا دو هفته یکجا برای مطب شما ارسال می‌شود.
                    <span class="step-time">زمان تحویل: ۱ تا ۲ هفته</span>
                </li>
                <li>
                    <strong>ویزیت هر دو هفته</strong>
                    بیمار را هر دو هفته یک‌بار ویزیت می‌کنید و در صورت رضایت از روند درمان، الاینر مرحله بعد را در اختیارش قرار می‌دهید.
                </li>
            </ol>

            <h3 style="text-align:center;">کیس‌های مناسب برای شروع درمان با الاینر شفاف</h3>
            <p class="section-lead">انتخاب درست بیمار نقش مهمی در موفقیت درمان دارد. فهرست زیر می‌تواند در ارزیابی اولیه به شما کمک کند.</p>

            <div class="aligner-cases">
                <div class="case-col suitable">
                    <h4>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2e9e5b" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><path d="M7.5 12.5l3 3 6-6.5"></path></svg>
                        مناسب برای الاینر شفاف
                    </h4>
                    <ul>
                        <li>نامرتبی خفیف تا متوسط دندان‌ها</li>
                        <li>فاصله‌های بین دندانی (دیاستما) خفیف تا متوسط</li>
                        <li>چرخش‌های خفیف دندان‌های قدامی</li>
                        <li>اورجت و اوربایت خفیف تا متوسط</li>
                        <li>عود جزئی (relapse) پس از درمان ارتودنسی قبلی</li>
                        <li>بیمار با انگیزه و همکاری خوب برای استفاده ۲۰ تا ۲۲ ساعت در روز</li>
                    </ul>
                </div>
                <div class="case-col caution">
                    <h4>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#c98f00" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.5 21 19.5H3z"></path><line x1="12" y1="9.5" x2="12" y2="14"></line><circle cx="12" cy="16.8" r=".9" fill="#c98f00" stroke="none"></circle></svg>
                       نامناسب برای الاینر شفاف
                    </h4>
                    <ul>
                        <li>ناهنجاری‌های اسکلتال شدید فک (کلاس II یا III شدید)</li>
                        <li>چرخش‌های شدید دندان‌های نیش و پرمولر</li>
                        <li>نیاز به اکستروژن یا جابجایی عمودی قابل‌توجه دندان</li>
                        <li>اوپن‌بایت (open bite) شدید</li>
                        <li>نیاز به اکسپنشن قابل‌توجه قوس دندانی</li>
                        <li>بیماری که امکان رعایت ساعت استفاده روزانه الاینر را ندارد</li>
                    </ul>
                </div>
            </div>
            <p class="aligner-hint">در موارد نامشخص، همین حالا عکس بیمار را برای ما ارسال کنید؛ بررسی نهایی توسط دندانپزشک باسابقه EXOLAB انجام و نتیجه به شما اعلام می‌شود.</p>

            <div class="aligner-pricing">
                <h3 style="text-align:center;">هزینه‌های همکاری</h3>
                <div class="price-grid">
                    <article class="price-card">
                        <h3><?= htmlspecialchars($alignerPlanTitle) ?></h3>
                        <p><?= htmlspecialchars($alignerPlanDesc) ?></p>
                        <span class="price"><?= formatAmountToman($alignerPlanPrice) ?></span>
                    </article>
                    <article class="price-card">
                        <h3><?= htmlspecialchars($alignerStageTitle) ?></h3>
                        <p><?= htmlspecialchars($alignerStageDesc) ?></p>
                        <span class="price"><?= formatAmountToman($alignerStagePrice) ?></span>
                    </article>
                </div>
                <p class="aligner-note">
                    این مبالغ مستقیماً از لیست قیمت رسمی EXOLAB خوانده می‌شوند و در صورت به‌روزرسانی قیمت‌ها در پنل مدیریت، همین صفحه نیز به‌طور خودکار به‌روز می‌شود.
                    تعیین تعرفه نهایی برای بیمار بر عهده مطب شماست.
                </p>
            </div>

            <h3 style="text-align:center; margin-top:48px;">پرسش‌های متداول درباره الاینر شفاف</h3>
            <div class="aligner-faq">
                <details>
                    <summary>برای شروع درمان الاینر شفاف در مطب، به تخصص ارتودنسی نیاز دارم؟</summary>
                    <p>خیر. EXOLAB این امکان را فراهم کرده است که دندانپزشکان عمومی و کلینیک‌های همکار نیز بتوانند درمان الاینر شفاف را ارائه دهند. طرح درمان هر بیمار پیش از ساخت، توسط دندانپزشکی با سابقه بالا در درمان‌های ارتودنسی بررسی و تایید می‌شود.</p>
                </details>
                <details>
                    <summary>چرا پس از تایید طرح درمان، اسکن داخل دهانی لازم است؟</summary>
                    <p>طرح درمان بر اساس عکس‌های بالینی طراحی می‌شود، اما ساخت الاینر به مدل دیجیتال دقیق از قوس دندانی نیاز دارد. به همین دلیل پس از تایید طرح درمان، زمان حضور اپراتور اسکن داخل دهانی هماهنگ می‌شود تا الاینرها با دقت بالا و تطابق کامل روی دندان‌های بیمار بنشینند.</p>
                </details>
                <details>
                    <summary>مراحل الاینر چه زمانی به مطب ارسال می‌شود؟</summary>
                    <p>پس از انجام اسکن داخل دهانی و آماده‌سازی مدل دیجیتال، تمام مراحل الاینر بیمار ظرف یک تا دو هفته یکجا برای مطب شما ارسال می‌شود.</p>
                </details>
                <details>
                    <summary>هزینه طرح درمان و هر مرحله الاینر چگونه محاسبه می‌شود؟</summary>
                    <p>هزینه طرح درمان یک‌بار برای هر بیمار محاسبه می‌شود و هزینه هر عدد الاینر جداگانه است. قیمت‌های به‌روز در بخش «هزینه‌های همکاری» بالای همین صفحه و همچنین در لیست قیمت سایت قابل مشاهده است.</p>
                </details>
                <details>
                    <summary>چه بیمارانی برای درمان با الاینر شفاف مناسب‌تر هستند؟</summary>
                    <p>نامرتبی خفیف تا متوسط، فاصله‌های بین دندانی، چرخش‌های خفیف دندان‌های قدامی و عود جزئی پس از درمان ارتودنسی قبلی معمولاً گزینه‌های مناسبی هستند. مواردی مانند ناهنجاری‌های اسکلتال شدید، اوپن‌بایت شدید یا نیاز به جابجایی عمودی قابل‌توجه برای الاینر مناسب نیستند.</p>
                </details>
            </div>

            <div class="aligner-links">
                <a href="#scanner-guide">راهنمای آماده‌سازی بیمار برای اسکن<span>چک‌لیست پیش از حضور اپراتور اسکن</span></a>
                <a href="#services">خدمات لابراتوار دیجیتال<span>میلینگ سنتری، روکش زیرکونیا، لمینیت و...</span></a>
                <a href="#prices">لیست قیمت کامل خدمات<span>قیمت‌های به‌روز از پنل مدیریت</span></a>
                <a href="#works">نمونه کارها<span>نمونه‌ای از درمان‌های انجام‌شده</span></a>
            </div>

            <div style="text-align:center; margin-top:32px;">
                <a class="btn" href="#contact">شروع همکاری با EXOLAB</a>
            </div>
        </div>
    </section>
        <section id="contact" class="section alt">
        <div class="container">
            <h2>تماس و دسترسی</h2>
            <p>برای تماس با ما و دسترسی به آدرس‌ها و شبکه‌های اجتماعی، لطفاً از اطلاعات زیر استفاده کنید.</p>
            <div class="contact-grid">
                <div class="contact-card">
                    <h3><img src="assets/icons/Locationino.svg" alt="آدرس" class="icon-svg" style="vertical-align:middle; margin-left:8px;"> آدرس</h3>
                    <p><?= htmlspecialchars($contact['address_text']) ?></p>
                    <div class="maps-city-buttons">
                        <button type="button" class="btn-maps city-toggle" data-city="eslamshahr" onclick="event.stopPropagation(); openMapsMenu('eslamshahr')">مسیریابی اسلامشهر</button>
                        <button type="button" class="btn-maps city-toggle" data-city="qazvin" onclick="event.stopPropagation(); openMapsMenu('qazvin')">مسیریابی قزوین</button>
                    </div>
                    <div id="maps-menu" class="maps-menu hidden">
                        <div id="maps-links-eslamshahr" class="maps-menu-links hidden">
                            <a href="<?= htmlspecialchars($contact['maps']['eslamshahr']['neshan']) ?>" target="_blank" rel="noopener noreferrer">
                                <img src="assets/icons/Neshan.svg" alt="نشان" class="icon-svg"> نشان
                            </a>
                            <a href="<?= htmlspecialchars($contact['maps']['eslamshahr']['balad']) ?>" target="_blank" rel="noopener noreferrer">
                                <img src="assets/icons/Balad.svg" alt="بلد" class="icon-svg"> بلد
                            </a>
                            <a href="<?= htmlspecialchars($contact['maps']['eslamshahr']['google']) ?>" target="_blank" rel="noopener noreferrer">
                                <img src="assets/icons/GoogleMaps.svg" alt="گوگل مپ" class="icon-svg"> گوگل مپ
                            </a>
                        </div>
                        <div id="maps-links-qazvin" class="maps-menu-links hidden">
                            <a href="<?= htmlspecialchars($contact['maps']['qazvin']['neshan']) ?>" target="_blank" rel="noopener noreferrer">
                                <img src="assets/icons/Neshan.svg" alt="نشان" class="icon-svg"> نشان
                            </a>
                            <a href="<?= htmlspecialchars($contact['maps']['qazvin']['balad']) ?>" target="_blank" rel="noopener noreferrer">
                                <img src="assets/icons/Balad.svg" alt="بلد" class="icon-svg"> بلد
                            </a>
                            <a href="<?= htmlspecialchars($contact['maps']['qazvin']['google']) ?>" target="_blank" rel="noopener noreferrer">
                                <img src="assets/icons/GoogleMaps.svg" alt="گوگل مپ" class="icon-svg"> گوگل مپ
                            </a>
                        </div>
                    </div>
                </div>
                <div class="contact-card">
                    <h3><img src="assets/icons/Phone.svg" alt="تماس" class="icon-svg" style="vertical-align:middle; margin-left:8px;"> تماس</h3>
                    <p><strong>اسلامشهر:</strong> <a href="tel:<?= htmlspecialchars($contact['phone_eslamshahr']) ?>" class="contact-link"><?= htmlspecialchars($contact['phone_eslamshahr']) ?></a></p>
                    <p><strong>قزوین:</strong> <a href="tel:<?= htmlspecialchars($contact['phone_qazvin']) ?>" class="contact-link"><?= htmlspecialchars($contact['phone_qazvin']) ?></a></p>
                </div>
                <div class="contact-card">
                    <h3>🔗 شبکه‌های اجتماعی</h3>
                    <div class="social-links">
                        <a href="<?= htmlspecialchars($contact['social']['instagram']) ?>" target="_blank" rel="noopener noreferrer" title="اینستاگرام" class="social-btn">
                            <img src="assets/icons/instagram.svg" alt="اینستاگرام">
                        </a>
                        <a href="<?= htmlspecialchars($contact['social']['bale']) ?>" target="_blank" rel="noopener noreferrer" title="بله" class="social-btn">
                            <img src="assets/icons/Bale.svg" alt="بله">
                        </a>
                        <a href="<?= htmlspecialchars($contact['social']['rubika']) ?>" target="_blank" rel="noopener noreferrer" title="روبیکا" class="social-btn">
                            <img src="assets/icons/Rubika.svg" alt="روبیکا">
                        </a>
                        <a href="<?= htmlspecialchars($contact['social']['whatsapp']) ?>" target="_blank" rel="noopener noreferrer" title="واتساپ" class="social-btn">
                            <img src="assets/icons/WhatsApp.svg" alt="واتساپ">
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>
<footer class="site-footer">
    <div class="container footer-content">
        <div>
            <p>© <?= date('Y') ?> <?= SITE_NAME ?></p>
            <p><a href="panel/login.php">ورود مدیر</a></p>
        </div>
        <div>
            <p>تلفن: <strong><a href="tel:<?= htmlspecialchars($contact['phone_eslamshahr']) ?>" style="color:#f5c518;"><?= htmlspecialchars($contact['phone_eslamshahr']) ?></a></strong></p>
        </div>
    </div>
</footer>
<script src="assets/js/main.js"></script>
<script src="assets/js/lightbox.js"></script>
<?php
// ─── داده ساخت‌یافته (Schema.org / JSON-LD) برای نمایش بهتر در نتایج جستجوی گوگل ───
$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'Organization',
            '@id' => $canonical . '#organization',
            'name' => $siteName,
            'alternateName' => 'EXOLAB',
            'url' => $canonical,
            'logo' => $canonical . 'assets/icons/EXOLAB_LOGO_FULL.svg',
            'description' => $siteDesc,
            'telephone' => $contact['phone_eslamshahr'],
            'sameAs' => array_values(array_filter([
                $contact['social']['instagram'] ?? null,
                $contact['social']['whatsapp'] ?? null,
                $contact['social']['rubika'] ?? null,
                $contact['social']['bale'] ?? null,
            ])),
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => $contact['address_text'],
                'addressLocality' => 'اسلامشهر',
                'addressRegion' => 'تهران',
                'addressCountry' => 'IR',
            ],
        ],
        [
            '@type' => 'LocalBusiness',
            '@id' => $canonical . '#business',
            'name' => $siteName,
            'description' => $siteDesc,
            'url' => $canonical,
            'image' => $canonical . 'assets/icons/EXOLAB_LOGO_FULL.svg',
            'telephone' => $contact['phone_eslamshahr'],
            'priceRange' => '$$',
            'areaServed' => ['اسلامشهر', 'قزوین', 'تهران'],
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => $contact['address_text'],
                'addressLocality' => 'اسلامشهر',
                'addressRegion' => 'تهران',
                'addressCountry' => 'IR',
            ],
            'makesOffer' => [
                [
                    '@type' => 'Offer',
                    'name' => $alignerPlanTitle,
                    'description' => $alignerPlanDesc,
                    'priceCurrency' => 'IRR',
                    'price' => (int) $alignerPlanPrice,
                ],
                [
                    '@type' => 'Offer',
                    'name' => $alignerStageTitle,
                    'description' => $alignerStageDesc,
                    'priceCurrency' => 'IRR',
                    'price' => (int) $alignerStagePrice,
                ],
            ],
        ],
        [
            '@type' => 'Service',
            'name' => 'درمان الاینر شفاف',
            'serviceType' => 'ارتودنسی با الاینر شفاف',
            'provider' => ['@id' => $canonical . '#organization'],
            'areaServed' => ['اسلامشهر', 'قزوین'],
            'description' => 'همکاری با دندانپزشکان عمومی برای ارائه درمان الاینر شفاف؛ شامل طراحی و تایید طرح درمان توسط متخصص، اسکن داخل دهانی و ارسال مراحل الاینر.',
        ],
        [
            '@type' => 'FAQPage',
            'mainEntity' => [
                [
                    '@type' => 'Question',
                    'name' => 'برای شروع درمان الاینر شفاف در مطب، به تخصص ارتودنسی نیاز دارم؟',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'خیر. EXOLAB این امکان را فراهم کرده است که دندانپزشکان عمومی و کلینیک‌های همکار نیز بتوانند درمان الاینر شفاف را ارائه دهند. طرح درمان هر بیمار پیش از ساخت، توسط دندانپزشکی با سابقه بالا در درمان‌های ارتودنسی بررسی و تایید می‌شود.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'چرا پس از تایید طرح درمان، اسکن داخل دهانی لازم است؟',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'طرح درمان بر اساس عکس‌های بالینی طراحی می‌شود، اما ساخت الاینر به مدل دیجیتال دقیق از قوس دندانی نیاز دارد. پس از تایید طرح درمان، زمان حضور اپراتور اسکن داخل دهانی هماهنگ می‌شود تا الاینرها با دقت بالا روی دندان‌های بیمار بنشینند.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'مراحل الاینر چه زمانی به مطب ارسال می‌شود؟',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'پس از انجام اسکن داخل دهانی و آماده‌سازی مدل دیجیتال، تمام مراحل الاینر بیمار ظرف یک تا دو هفته یکجا برای مطب شما ارسال می‌شود.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'چه بیمارانی برای درمان با الاینر شفاف مناسب‌تر هستند؟',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'نامرتبی خفیف تا متوسط، فاصله‌های بین دندانی، چرخش‌های خفیف دندان‌های قدامی و عود جزئی پس از درمان ارتودنسی قبلی معمولاً گزینه‌های مناسبی هستند. مواردی مانند ناهنجاری‌های اسکلتال شدید، اوپن‌بایت شدید یا نیاز به جابجایی عمودی قابل‌توجه برای الاینر مناسب نیستند.',
                    ],
                ],
            ],
        ],
    ],
];
?>
<script type="application/ld+json">
<?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>
</body>
</html>