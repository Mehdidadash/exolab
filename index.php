<?php
// index.php — صفحه‌ی اصلی سایت (تنها فایل نمایشیِ ریشه؛ بقیه در pages/)

$PAGE = [
    'key'         => '',
    'path'        => 'index.php',
    'title'       => 'لابراتوار دیجیتال دندانسازی اگزولب | اسکن داخل دهانی، میلینگ سنتری و الاینر شفاف',
    'description' => 'EXOLAB ارائه‌دهنده خدمات لابراتوار دیجیتال دندانسازی، اسکن داخل دهانی، میلینگ سنتری و درمان الاینر شفاف در اسلامشهر و قزوین — همراه با لیست قیمت و نمونه کار.',
    'keywords'    => 'لابراتوار دیجیتال دندانسازی, اسکن داخل دهانی, میلینگ سنتری, الاینر شفاف, ارتودنسی نامرئی, لابراتوار دندانسازی اسلامشهر, لابراتوار دندانسازی قزوین, CAD CAM دندانپزشکی, زیرکونیا, لمینیت ای مکس, اگزولب, EXOLAB',
];

/**
 * محافظِ دیپلوی (نسخه‌ی index): اگر بسته‌ی آپلود ناقص باشد، به‌جای Fatal error و صفحه‌ی
 * سفید/۵۰۰ پیام قابل‌فهم نشان بده. جزئیات مسیرها فقط برای مدیر لاگین‌شده نمایش داده می‌شود.
 * (صفحات داخل pages/ از محافظِ ساده‌ترِ includes/public_bootstrap.php استفاده می‌کنند.)
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
require_once __DIR__ . '/includes/public_bootstrap.php';

$prices = getPrices();
$works  = getPortfolioWorks();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<?php public_head(); ?>

    <link rel="stylesheet" href="<?= public_asset('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= public_asset('assets/css/lightbox.css') ?>">
    <style>
        /* استایل بخش خلاصه‌ی «الاینر شفاف» در صفحه‌ی اصلی (صفحه‌ی کامل: pages/aligner.php) */
        #aligner .aligner-teaser {
            max-width: 860px;
            margin: 0 auto;
            text-align: center;
        }
        #aligner .aligner-teaser p {
            line-height: 2;
            margin: 0 auto 20px;
            font-size: 1.02rem;
        }
        #aligner .aligner-teaser .teaser-points {
            list-style: none;
            padding: 0;
            margin: 0 auto 26px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
            text-align: right;
        }
        #aligner .aligner-teaser .teaser-points li {
            background: #fff;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            padding: 16px 18px;
            line-height: 1.9;
            font-size: .94rem;
            box-shadow: 0 8px 24px rgba(15, 92, 186, .04);
        }
        #aligner .aligner-teaser .teaser-points li strong {
            display: block;
            margin-bottom: 4px;
            color: #0F172A;
        }
    </style>
</head>
<body>
<?php public_header(); ?>
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
                <div class="card">
                    <h3>راهنمای عکاسی فول ماوث</h3>
                    <p>برای کیس‌های قدام فک بالا، فول آرچ و فول ماوث، چک‌لیست عکس‌های بالینی لازم پیش از اسکن را در <a href="<?= public_url('pages/photography-guide.php') ?>">راهنمای عکاسی</a> ببینید.</p>
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
                            <img src="<?= public_url('pages/media.php') ?>?f=<?= rawurlencode($work['image_filename']) ?>" alt="<?= htmlspecialchars($work['title']) ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <section id="aligner" class="section alt">
        <div class="container">
            <div class="aligner-teaser">
                <h2>الاینر شفاف؛ فرصت همکاری برای دندانپزشکان عمومی</h2>
                <p>
                    EXOLAB به دندانپزشکان عمومی و کلینیک‌های همکار در اسلامشهر و قزوین این امکان را می‌دهد
                    تا بدون نیاز به تخصص ارتودنسی، درمان الاینر شفاف را در مطب خود ارائه دهند.
                    طرح درمان هر بیمار پیش از ساخت، توسط دندانپزشکی با سابقه بالا در درمان‌های ارتودنسی
                    بررسی و تایید می‌شود.
                </p>
                <ul class="teaser-points">
                    <li><strong>۵ مرحله‌ی همکاری</strong>از ارسال عکس تا ویزیت هر دو هفته، همه‌چیز شفاف و زمان‌بندی‌شده.</li>
                    <li><strong>کیس‌های مناسب و نامناسب</strong>راهنمای انتخاب بیمار برای موفقیت بیشتر درمان.</li>
                    <li><strong>هزینه‌های همکاری</strong>قیمت طرح درمان و هر عدد الاینر، به‌روز از لیست رسمی.</li>
                    <li><strong>پرسش‌های متداول</strong>پاسخ به ابهامات رایج دندانپزشکان پیش از شروع.</li>
                </ul>
                <a class="btn" href="<?= public_url('pages/aligner.php') ?>">جزئیات کامل الاینر شفاف</a>
            </div>
        </div>
    </section>
        <section id="contact" class="section alt">
        <div class="container">
            <h2>تماس و دسترسی</h2>
            <p>برای تماس با ما و دسترسی به آدرس‌ها و شبکه‌های اجتماعی، لطفاً از اطلاعات زیر استفاده کنید.</p>
            <div class="contact-grid">
                <div class="contact-card">
                    <h3><img src="<?= public_url('assets/icons/Locationino.svg') ?>" alt="آدرس" class="icon-svg" style="vertical-align:middle; margin-left:8px;"> آدرس</h3>
                    <p><?= htmlspecialchars($contact['address_text']) ?></p>
                    <div class="maps-city-buttons">
                        <button type="button" class="btn-maps city-toggle" data-city="eslamshahr" onclick="event.stopPropagation(); openMapsMenu('eslamshahr')">مسیریابی اسلامشهر</button>
                        <button type="button" class="btn-maps city-toggle" data-city="qazvin" onclick="event.stopPropagation(); openMapsMenu('qazvin')">مسیریابی قزوین</button>
                    </div>
                    <div id="maps-menu" class="maps-menu hidden">
                        <div id="maps-links-eslamshahr" class="maps-menu-links hidden">
                            <a href="<?= htmlspecialchars($contact['maps']['eslamshahr']['neshan']) ?>" target="_blank" rel="noopener noreferrer">
                                <img src="<?= public_url('assets/icons/Neshan.svg') ?>" alt="نشان" class="icon-svg"> نشان
                            </a>
                            <a href="<?= htmlspecialchars($contact['maps']['eslamshahr']['balad']) ?>" target="_blank" rel="noopener noreferrer">
                                <img src="<?= public_url('assets/icons/Balad.svg') ?>" alt="بلد" class="icon-svg"> بلد
                            </a>
                            <a href="<?= htmlspecialchars($contact['maps']['eslamshahr']['google']) ?>" target="_blank" rel="noopener noreferrer">
                                <img src="<?= public_url('assets/icons/GoogleMaps.svg') ?>" alt="گوگل مپ" class="icon-svg"> گوگل مپ
                            </a>
                        </div>
                        <div id="maps-links-qazvin" class="maps-menu-links hidden">
                            <a href="<?= htmlspecialchars($contact['maps']['qazvin']['neshan']) ?>" target="_blank" rel="noopener noreferrer">
                                <img src="<?= public_url('assets/icons/Neshan.svg') ?>" alt="نشان" class="icon-svg"> نشان
                            </a>
                            <a href="<?= htmlspecialchars($contact['maps']['qazvin']['balad']) ?>" target="_blank" rel="noopener noreferrer">
                                <img src="<?= public_url('assets/icons/Balad.svg') ?>" alt="بلد" class="icon-svg"> بلد
                            </a>
                            <a href="<?= htmlspecialchars($contact['maps']['qazvin']['google']) ?>" target="_blank" rel="noopener noreferrer">
                                <img src="<?= public_url('assets/icons/GoogleMaps.svg') ?>" alt="گوگل مپ" class="icon-svg"> گوگل مپ
                            </a>
                        </div>
                    </div>
                </div>
                <div class="contact-card">
                    <h3><img src="<?= public_url('assets/icons/Phone.svg') ?>" alt="تماس" class="icon-svg" style="vertical-align:middle; margin-left:8px;"> تماس</h3>
                    <p><strong>اسلامشهر:</strong> <a href="tel:<?= htmlspecialchars($contact['phone_eslamshahr']) ?>" class="contact-link"><?= htmlspecialchars($contact['phone_eslamshahr']) ?></a></p>
                    <p><strong>قزوین:</strong> <a href="tel:<?= htmlspecialchars($contact['phone_qazvin']) ?>" class="contact-link"><?= htmlspecialchars($contact['phone_qazvin']) ?></a></p>
                </div>
                <div class="contact-card">
                    <h3>🔗 شبکه‌های اجتماعی</h3>
                    <div class="social-links">
                        <a href="<?= htmlspecialchars($contact['social']['instagram']) ?>" target="_blank" rel="noopener noreferrer" title="اینستاگرام" class="social-btn">
                            <img src="<?= public_url('assets/icons/instagram.svg') ?>" alt="اینستاگرام">
                        </a>
                        <a href="<?= htmlspecialchars($contact['social']['bale']) ?>" target="_blank" rel="noopener noreferrer" title="بله" class="social-btn">
                            <img src="<?= public_url('assets/icons/Bale.svg') ?>" alt="بله">
                        </a>
                        <a href="<?= htmlspecialchars($contact['social']['rubika']) ?>" target="_blank" rel="noopener noreferrer" title="روبیکا" class="social-btn">
                            <img src="<?= public_url('assets/icons/Rubika.svg') ?>" alt="روبیکا">
                        </a>
                        <a href="<?= htmlspecialchars($contact['social']['whatsapp']) ?>" target="_blank" rel="noopener noreferrer" title="واتساپ" class="social-btn">
                            <img src="<?= public_url('assets/icons/WhatsApp.svg') ?>" alt="واتساپ">
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>
<?php
// ─── داده ساخت‌یافته (Schema.org / JSON-LD) برای نمایش بهتر در نتایج جستجوی گوگل ───
// توجه: قیمت‌ها و FAQ الاینر به صفحه‌ی مستقل pages/aligner.php منتقل شده‌اند و اسکیمای
// کامل آنجا قرار دارد. اینجا فقط موجودیت سازمان/کسب‌وکار محلی اعلام می‌شود.
$canonical = public_url('index.php');
$PAGE['schema'] = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'Organization',
            '@id' => $canonical . '#organization',
            'name' => SITE_NAME,
            'alternateName' => 'EXOLAB',
            'url' => $canonical,
            'logo' => $canonical . 'assets/icons/EXOLAB_LOGO_FULL.svg',
            'description' => $PAGE['description'],
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
            'name' => SITE_NAME,
            'description' => $PAGE['description'],
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
        ],
        [
            '@type' => 'WebSite',
            '@id' => $canonical . '#website',
            'url' => public_url('index.php'),
            'name' => SITE_NAME,
            'inLanguage' => 'fa-IR',
            'publisher' => ['@id' => $canonical . '#organization'],
        ],
    ],
];
public_footer_and_scripts();
