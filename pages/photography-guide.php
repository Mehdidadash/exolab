<?php
// pages/photography-guide.php
// راهنمای عکاسی بالینی — کیس‌های قدام فک بالا، فول آرچ و فول ماوث

$PAGE = [
    'key'         => 'photography-guide',
    'path'        => 'pages/photography-guide.php',
    'og_type'     => 'article',
    'title'       => 'راهنمای عکاسی فول ماوث و قدام فک بالا | لابراتوار دیجیتال اگزولب',
    'description' => 'چک‌لیست عکس‌های بالینی لازم پیش از اسکن برای کیس‌های قدام فک بالا، فول آرچ و فول ماوث: تمام رخ (Rest و Bite)، لبخند Wide و نیم‌رخ. راهنمای ارسال عکس برای دندانپزشکان و لابراتوارهای همکار EXOLAB در اسلامشهر و قزوین.',
    'keywords'    => 'راهنمای عکاسی فول ماوث, عکس بالینی فول آرچ, عکاسی قدام فک بالا, عکس قبل از اسکن داخل دهانی, فتوگرافی دندانپزشکی, عکس نیم رخ اورجت, لابراتوار دندانسازی اسلامشهر, لابراتوار دندانسازی قزوین, اسکن داخل دهانی, EXOLAB',
    'h1'          => 'راهنمای عکاسی فول ماوث و قدام فک بالا',
    'lead'        => 'برای کیس‌هایی که طرح درمانشان مربوط به قدام فک بالا است و همچنین کیس‌های فول آرچ و فول ماوث، چند عکس استاندارد لازم است تا بتوانیم با کمترین مراجعه و کمترین تری به نتیجه‌ی مطلوب برسیم.',
];
require __DIR__ . '/../includes/public_bootstrap.php';

// ─── شات‌لیست ───
// 'file' نامِ پایه‌ی فایل موردانتظار در uploads/guide است (با پسوندهای رایج تصویری
// جست‌وجو می‌شود). اگر تصویر موجود باشد نمایش داده می‌شود، وگرنه placeholder.
$shots = [
    [
        'file'  => '01-front-rest',
        'title' => 'تمام رخ — دهان باز در حالت Rest',
        'desc'  => 'بیمار کاملاً روبه‌روی دوربین و سر کاملاً عمود (بدون چرخش یا کج‌شدن). دهان باز و فک در حالت استراحت باشد تا خط میدلاین، فرم صورت و انحراف احتمالی فک دیده شود.',
        'tags'  => [['الزامی', 'warn'], ['تمام رخ', '']],
    ],
    [
        'file'  => '02-front-bite',
        'title' => 'تمام رخ — دهان باز در حالت Bite',
        'desc'  => 'همان نمای روبه‌رو، اما بیمار دندان‌ها را در حالت بایت قرار می‌دهد. این عکس برای تخمین مقدار BITE و VD و بررسی تطابق خط میدلاین دندانی با خط میدلاین صورت ثبت می‌شود.',
        'tags'  => [['الزامی', 'warn'], ['Bite', '']],
    ],
    [
        'file'  => '03-smile-wide',
        'title' => 'تمام رخ — لبخند Wide',
        'desc'  => 'لبخند پهن و طبیعی، به‌طوری‌که دندان‌های قدامی و مقدار نمایان‌شدن لثه (Gummy Smile) مشخص شود. برای طراحی خط لبخند، سایز دندان‌ها و میزان شو دندان‌ها لازم است.',
        'tags'  => [['الزامی', 'warn'], ['لبخند', '']],
    ],
    [
        'file'  => '04-profile',
        'title' => 'نیم‌رخ — بررسی اورجت',
        'desc'  => 'نمای نیم‌رخ کامل از سمت راست یا چپ، با نگاه رو به جلو و لب‌ها در حالت طبیعی. برای سنجش اورجت، پروفایل صورت و موقعیت لب‌ها نسبت به خط زیبایی ضروری است.',
        'tags'  => [['الزامی', 'warn'], ['نیم‌رخ', '']],
    ],
];

if (!function_exists('guideImageFile')) {
    /** آیا تصویری با نام پایه‌ی مشخص در uploads/guide وجود دارد؟ نام فایل کامل را برگردان. */
    function guideImageFile(string $base): ?string
    {
        $dir = PUBLIC_ROOT_DIR . '/uploads/guide';
        foreach (['jpg', 'jpeg', 'png', 'webp', 'avif'] as $ext) {
            $candidate = $base . '.' . $ext;
            if (is_file($dir . '/' . $candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}

// ─── پرسش‌های متداول ───
$faqs = [
    [
        'q' => 'چه کیس‌هایی به این راهنمای عکاسی نیاز دارند؟',
        'a' => 'کیس‌هایی که طرح درمانشان مربوط به قدام فک بالا است، و همچنین کیس‌های فول آرچ و فول ماوث. برای این درمان‌ها تعیین پلن افقی و عمودی (میدلاین)، آناتومی و ابعاد بر اساس فرم صورت، میزان شو دندان‌ها، مقدار تقریبی BITE و VD و سایر پارامترهای تعیین‌کننده به عکس‌های استاندارد نیاز دارد.',
    ],
    [
        'q' => 'چرا این عکس‌ها باید بعد از اسکن و با مشخصات مشخص گرفته شوند؟',
        'a' => 'هدف، رسیدن به نتیجه‌ی مطلوب با کمترین تعداد مراجعه و کمترین نیاز به اصلاح (تری) است. وقتی عکس‌ها با زاویه و حالت استاندارد ثبت شوند، طراحی طرح درمان دقیق‌تر انجام می‌شود و رفت‌وآمد بیمار کمتر می‌شود.',
    ],
    [
        'q' => 'در کیس‌های ایمپلنتی چه نکته‌ای مهم است؟',
        'a' => 'در کیس‌های ایمپلنتی حتماً بلافاصله بعد از اسکن و با وجود اسکن‌بادی‌ها عکس گرفته شود تا لندمارک مورد نیاز در عکس‌ها حفظ شود.',
    ],
    [
        'q' => 'در کیس‌های تراش دندانی چه باید کرد؟',
        'a' => 'در کیس‌های تراش دندانی علاوه بر عکس، حتماً اسکن قبل از تراش نیز به‌صورت کامل انجام شود.',
    ],
    [
        'q' => 'عکس‌ها را با موبایل هم می‌توان گرفت؟',
        'a' => 'بله. عکس‌ها با موبایل هم قابل ثبت هستند، به شرطی که نور یکنواخت باشد، دوربین کاملاً عمود به صورت قرار گیرد، پس‌زمینه ساده باشد و کیفیت تصویر برای دیدن جزئیات کافی باشد. از فلاش مستقیم و سایه‌ی شدید پرهیز کنید.',
    ],
    [
        'q' => 'عکس‌ها را چگونه برای EXOLAB ارسال کنیم؟',
        'a' => 'عکس‌ها را از طریق راه‌های ارتباطی اعلام‌شده در بخش تماس (واتساپ، بله، روبیکا یا پیام‌رسان‌های همکاران) برای تیم EXOLAB ارسال کنید. توجه کنید که عکس‌های بالینی بیمار محرمانه تلقی می‌شوند و فقط برای طراحی طرح درمان استفاده می‌شوند.',
    ],
];

// ─── خدمات مرتبط (از رجیستری مرکزی) ───
$relatedKeys = ['aligner', 'scanner-guide', 'prices', 'contact'];

// ─── داده ساخت‌یافته (JSON-LD) ───
$canonical = public_url('pages/photography-guide.php');
$PAGE['schema'] = [
    '@context' => 'https://schema.org',
    '@graph' => [
        public_breadcrumb_schema('راهنمای عکاسی بالینی', $canonical),
        [
            '@type' => 'HowTo',
            'name' => $PAGE['h1'],
            'description' => $PAGE['description'],
            'inLanguage' => 'fa-IR',
            'step' => array_map(static fn(array $s, int $i): array => [
                '@type' => 'HowToStep',
                'position' => $i + 1,
                'name' => $s['title'],
                'text' => $s['desc'],
            ], $shots, array_keys($shots)),
        ],
        [
            '@type' => 'FAQPage',
            'mainEntity' => array_map(static fn(array $f): array => [
                '@type' => 'Question',
                'name' => $f['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
            ], $faqs),
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <?php public_head(); ?>
    <link rel="stylesheet" href="<?= public_asset('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= public_asset('assets/css/lightbox.css') ?>">
    <link rel="stylesheet" href="<?= public_asset('assets/css/guide.css') ?>">
</head>
<body>
<?php public_header(); ?>
<main>

    <section class="page-hero">
        <div class="container">
            <nav class="breadcrumb" aria-label="مسیر صفحه">
                <a href="<?= public_url('index.php') ?>">خانه</a>
                <span class="sep">›</span>
                <span>راهنمای عکاسی بالینی</span>
            </nav>
            <h1><?= htmlspecialchars($PAGE['h1']) ?></h1>
            <p class="lead">
                برای کیس‌هایی که طرح درمانشان مربوط به <strong>قدام فک بالا</strong> است و همچنین
                کیس‌های <strong>فول آرچ</strong> و <strong>فول ماوث</strong>، چند عکس استاندارد لازم است
                تا بتوانیم با کمترین مراجعه و کمترین تری، به نتیجه‌ی مطلوب برسیم.
                این صفحه دقیقاً می‌گوید چه عکس‌هایی، با چه حالتی و برای چه هدفی لازم است.
            </p>
            <div class="hero-cta">
                <a class="btn" href="#shot-list">مشاهده‌ی چک‌لیست عکس‌ها</a>
                <a class="btn btn-ghost" href="<?= public_url('index.php#contact') ?>">ارسال عکس / هماهنگی</a>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <h2>چرا عکس‌های استاندارد پیش از اسکن لازم است؟</h2>
            <p style="max-width:820px; line-height:2;">
                فتوگرافی بالینی، مکملِ اسکن داخل دهانی است، نه جایگزین آن. اسکن، فرم دیجیتال
                دندان‌ها و بافت را ثبت می‌کند؛ اما پارامترهایی مثل میدلاین صورت، فرم و ابعاد صورت،
                میزان شو دندان‌ها و مقدار تقریبی BITE و VD فقط با عکس قابل ارزیابی هستند.
            </p>
            <div class="guide-intro-grid">
                <div class="card">
                    <h3>🎯 پلن افقی و عمودی (میدلاین)</h3>
                    <p>مطابقت میدلاین دندانی با میدلاین صورت، پایه‌ی طراحی درست است. در عکس تمام رخ، انحراف‌های جزئی هم قابل اندازه‌گیری می‌شوند.</p>
                </div>
                <div class="card">
                    <h3>📐 آناتومی و ابعاد بر اساس فرم صورت</h3>
                    <p>انتخاب سایز و فرم دندان‌ها باید با فرم صورت و نسبت‌های آن هماهنگ باشد، نه فقط با عرض قوس دندانی.</p>
                </div>
                <div class="card">
                    <h3>😁 میزان شو دندان‌ها</h3>
                    <p>در حالت لبخند Wide مشخص می‌شود چند دندان در لبخند دیده می‌شود و خط لبخند در چه سطحی قرار دارد.</p>
                </div>
                <div class="card">
                    <h3>📏 مقدار تقریبی BITE و VD</h3>
                    <p>عکس حالت Bite، تخمینی از فضای عمودی (VD) و رابطه‌ی بایت به ما می‌دهد تا طرح درمان واقع‌گرایانه‌تر باشد.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="section alt" id="shot-list">
        <div class="container">
            <h2>چک‌لیست عکس‌ها</h2>
            <p style="max-width:820px; line-height:2;">
                هر چهار عکس زیر <strong>الزامی</strong> هستند. همه‌ی عکس‌ها باید
                «تمام رخ و کاملاً عمود به صورت بیمار» ثبت شوند (به‌جز نیم‌رخ).
            </p>

            <div class="shot-list">
                <?php foreach ($shots as $i => $shot): ?>
                    <?php $img = guideImageFile($shot['file']); ?>
                    <article class="shot-card" id="shot-<?= $i + 1 ?>">
                        <figure class="shot-figure">
                            <?php if ($img !== null): ?>
                                <img src="<?= public_url('guide_media.php') ?>?f=<?= rawurlencode($img) ?>"
                                     alt="<?= htmlspecialchars($shot['title']) ?>"
                                     loading="lazy">
                            <?php else: ?>
                                <div class="shot-placeholder">
                                    تصویر نمونه به‌زودی اضافه می‌شود
                                    <code>uploads/guide/<?= htmlspecialchars($shot['file']) ?>.jpg</code>
                                </div>
                            <?php endif; ?>
                        </figure>
                        <div class="shot-head">
                            <span class="shot-no"><?= htmlspecialchars(toPersianDigits((string) ($i + 1))) ?></span>
                            <h3><?= htmlspecialchars($shot['title']) ?></h3>
                        </div>
                        <div class="shot-body">
                            <p><?= htmlspecialchars($shot['desc']) ?></p>
                            <div class="shot-tags">
                                <?php foreach ($shot['tags'] as $tag): ?>
                                    <span class="shot-tag <?= htmlspecialchars($tag[1]) ?>"><?= htmlspecialchars($tag[0]) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php
            // گالری مخفی برای lightbox مشترک سایت (فقط تصاویر موجود)
            $guideGalleryImages = [];
            foreach ($shots as $shot) {
                $img = guideImageFile($shot['file']);
                if ($img !== null) {
                    $guideGalleryImages[$img] = $shot['title'];
                }
            }
            ?>
            <?php if ($guideGalleryImages): ?>
                <div class="lightbox-gallery guide-lightbox-source" aria-hidden="true">
                    <?php foreach ($guideGalleryImages as $imgFile => $imgTitle): ?>
                        <div class="lightbox-item">
                            <img src="<?= public_url('guide_media.php') ?>?f=<?= rawurlencode($imgFile) ?>"
                                 alt="<?= htmlspecialchars($imgTitle) ?>"
                                 loading="lazy">
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h3 style="margin-top:44px;">نکات مهم کیس‌های خاص</h3>
            <div class="guide-alerts">
                <div class="alert-box implant">
                    <h3>⚠️ کیس‌های ایمپلنتی</h3>
                    <p>
                        حتماً <strong>بلافاصله بعد از اسکن</strong> و در حالی که اسکن‌بادی‌ها سر جای خود هستند،
                        عکس‌برداری انجام شود تا لندمارک مورد نیاز (موقعیت و زاویه‌ی اسکن‌بادی) در عکس‌ها ثبت شود.
                        اگر عکس بعد از برداشتن اسکن‌بادی گرفته شود، بخشی از اطلاعات موردنیاز از دست می‌رود.
                    </p>
                </div>
                <div class="alert-box prep">
                    <h3>🦷 کیس‌های تراش دندانی</h3>
                    <p>
                        در کیس‌های تراش، علاوه بر عکس‌های بالینی، حتماً <strong>اسکن قبل از تراش</strong>
                        به‌صورت کامل انجام شود. این اسکن مرجعِ شکل اولیه‌ی دندان است و برای مقایسه و طراحی
                        دقیق ترمیم استفاده می‌شود.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <h2>چطور عکس استاندارد بگیریم؟</h2>
            <p style="max-width:820px; line-height:2;">
                لازم نیست تجهیزات حرفه‌ای داشته باشید؛ رعایت همین نکات ساده کیفیت عکس را برای طراحی
                طرح درمان کافی می‌کند.
            </p>
            <div class="photo-tips">
                <div class="tip"><strong>💡 نور یکنواخت</strong>از نور پخش‌شده و روبه‌رو استفاده کنید. فلاش مستقیم و سایه‌ی شدید، جزئیات لثه و دندان را از بین می‌برد.</div>
                <div class="tip"><strong>📷 عمود به صورت</strong>دوربین را کاملاً روبه‌رو و در ارتفاع چشم بیمار بگیرید تا اعوجاج و کج‌نمایی ایجاد نشود.</div>
                <div class="tip"><strong>🧍 وضعیت بیمار</strong>سر در حالت طبیعی و بدون چرخش؛ نگاه رو به دوربین. در نمای نیم‌رخ، نگاه رو به جلو و لب‌ها شل.</div>
                <div class="tip"><strong>🖼️ پس‌زمینه‌ی ساده</strong>پشت‌سر بیمار دیوار یا پارچه‌ی ساده و تک‌رنگ باشد تا تمرکز روی صورت و لبخند بماند.</div>
                <div class="tip"><strong>🔍 فوکوس روی دندان‌ها</strong>مطمئن شوید دندان‌های قدامی و خط لبخند کاملاً واضح و شارپ هستند.</div>
                <div class="tip"><strong>📤 ارسال اصل فایل</strong>عکس‌ها را بدون فشرده‌سازی سنگین و در کیفیت اصلی ارسال کنید تا جزئیات حفظ شود.</div>
            </div>
        </div>
    </section>

    <section class="section alt">
        <div class="container">
            <h2>چک‌لیست نهایی قبل از ارسال</h2>
            <ul class="guide-checklist">
                <li>تمام رخ، دهان باز در حالت <strong>Rest</strong> ثبت شده است.</li>
                <li>تمام رخ، دهان باز در حالت <strong>Bite</strong> ثبت شده است.</li>
                <li>تمام رخ در حالت <strong>لبخند Wide</strong> ثبت شده است.</li>
                <li>نمای <strong>نیم‌رخ</strong> برای بررسی اورجت ثبت شده است.</li>
                <li>دوربین در همه‌ی نماهای روبه‌رو <strong>کاملاً عمود</strong> به صورت بوده است.</li>
                <li>در کیس ایمپلنتی، عکس <strong>بلافاصله بعد از اسکن و با اسکن‌بادی</strong> گرفته شده است.</li>
                <li>در کیس تراش، <strong>اسکن قبل از تراش</strong> به‌صورت کامل انجام شده است.</li>
                <li>نور، فوکوس و وضوح عکس‌ها برای دیدن جزئیات کافی است.</li>
            </ul>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <h2>پرسش‌های متداول درباره عکاسی فول ماوث</h2>
            <div class="guide-faq">
                <?php foreach ($faqs as $faq): ?>
                    <details>
                        <summary><?= htmlspecialchars($faq['q']) ?></summary>
                        <p><?= htmlspecialchars($faq['a']) ?></p>
                    </details>
                <?php endforeach; ?>
            </div>

            <div class="guide-links">
                <?php foreach ($relatedKeys as $rk): ?>
                    <?php $rp = $GLOBALS['PUBLIC_PAGES'][$rk] ?? null; if (!$rp) continue; ?>
                    <a href="<?= public_url($rp['url']) ?>">
                        <?= htmlspecialchars($rp['label']) ?>
                        <span><?= htmlspecialchars($rp['sub']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="section alt">
        <div class="container guide-cta">
            <h2>آماده‌ی شروع همکاری هستید؟</h2>
            <p>
                عکس‌های بیمار را برای تیم EXOLAB ارسال کنید؛ در صورت نیاز به هماهنگی اسکن داخل دهانی
                یا بررسی طرح درمان، کارشناسان ما در اسلامشهر و قزوین در کنار شما هستند.
            </p>
            <a class="btn" href="<?= public_url('index.php#contact') ?>">تماس و هماهنگی</a>
        </div>
    </section>

</main>
<?php public_footer_and_scripts(); ?>
