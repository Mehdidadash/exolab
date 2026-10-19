<?php
// pages/aligner.php
// صفحه‌ی مستقل «الاینر شفاف» — استخراج‌شده از index.php برای سبک‌ماندن صفحه‌ی اصلی
// و گرفتن رتبه‌ی مستقل در جستجوی گوگل (کلیدواژه: الاینر شفاف، ارتودنسی نامرئی).

$PAGE = [
    'key'         => 'aligner',
    'path'        => 'pages/aligner.php',
    'og_type'     => 'article',
    'title'       => 'الاینر شفاف در مطب، بدون تخصص ارتودنسی | همکاری با لابراتوار اگزولب',
    'description' => 'EXOLAB به دندانپزشکان عمومی و کلینیک‌های همکار اجازه می‌دهد درمان الاینر شفاف را در مطب خود ارائه دهند. طرح درمان توسط دندانپزشک باسابقه ارتودنسی تایید می‌شود. راهنمای بیمار مناسب، مراحل همکاری و هزینه‌ها.',
    'keywords'    => 'الاینر شفاف, ارتودنسی نامرئی, الاینر شفاف در مطب, درمان الاینر بدون تخصص ارتودنسی, لابراتوار الاینر, الاینر دندانپزشکی, طرح درمان الاینر, لابراتوار دندانسازی اسلامشهر, لابراتوار دندانسازی قزوین, EXOLAB',
    'h1'          => 'الاینر شفاف؛ فرصت همکاری برای دندانپزشکان عمومی',
    'lead'        => 'EXOLAB به دندانپزشکان عمومی و کلینیک‌های همکار در اسلامشهر و قزوین این امکان را می‌دهد تا بدون نیاز به تخصص ارتودنسی، درمان الاینر شفاف را در مطب خود ارائه دهند. طرح درمان هر بیمار پیش از ساخت، توسط دندانپزشکی با سابقه بالا در درمان‌های ارتودنسی بررسی و تایید می‌شود.',
];
require __DIR__ . '/../includes/public_bootstrap.php';

// ─── قیمت‌های الاینر شفاف از دیتابیس ───
// شناسه‌های خدمات الاینر در کاتالوگ مشترک (site_prices):
//   ۱۶ = «طرح درمان الاینر شفاف»  |  ۱۷ = «الاینر شفاف» (هر عدد)
const ALIGNER_PLAN_PRICE_ID  = 16;
const ALIGNER_STAGE_PRICE_ID = 17;

if (!function_exists('alignerServicePrice')) {
    /** قیمت یک خدمت با احتساب قیمت اختصاصی شعبه در صورت وجود. */
    function alignerServicePrice(int $serviceId, float $fallback): float
    {
        $service = getPrice($serviceId);
        if (!$service) {
            return $fallback;
        }
        $custom = getBranchServiceCustomPrice($serviceId);
        return $custom !== null ? (float) $custom : (float) $service['price'];
    }
}

if (!function_exists('alignerServiceTitle')) {
    /** عنوان خدمت از دیتابیس (برای هم‌نام‌بودن سایت و پنل)، با مقدار پیش‌فرض. */
    function alignerServiceTitle(int $serviceId, string $fallback): string
    {
        $title = trim((string) (getPrice($serviceId)['title'] ?? ''));
        return $title !== '' ? $title : $fallback;
    }
}

$alignerPlanPrice  = alignerServicePrice(ALIGNER_PLAN_PRICE_ID, 6000000);
$alignerStagePrice = alignerServicePrice(ALIGNER_STAGE_PRICE_ID, 1200000);
$alignerPlanTitle  = alignerServiceTitle(ALIGNER_PLAN_PRICE_ID, 'طرح درمان الاینر شفاف');
$alignerStageTitle = alignerServiceTitle(ALIGNER_STAGE_PRICE_ID, 'الاینر شفاف');

$alignerPlanDesc  = 'بررسی عکس‌ها و مدل دیجیتال، طراحی مراحل حرکت دندان‌ها و تایید نهایی طرح درمان توسط دندانپزشک باسابقه ارتودنسی.';
$alignerStageDesc = 'ساخت و ارسال هر عدد الاینر، پس از تایید طرح درمان و انجام اسکن داخل دهانی.';

// ─── پرسش‌های متداول ───
$alignerFaqs = [
    [
        'q' => 'برای شروع درمان الاینر شفاف در مطب، به تخصص ارتودنسی نیاز دارم؟',
        'a' => 'خیر. EXOLAB این امکان را فراهم کرده است که دندانپزشکان عمومی و کلینیک‌های همکار نیز بتوانند درمان الاینر شفاف را ارائه دهند. طرح درمان هر بیمار پیش از ساخت، توسط دندانپزشکی با سابقه بالا در درمان‌های ارتودنسی بررسی و تایید می‌شود.',
    ],
    [
        'q' => 'چرا پس از تایید طرح درمان، اسکن داخل دهانی لازم است؟',
        'a' => 'طرح درمان بر اساس عکس‌های بالینی طراحی می‌شود، اما ساخت الاینر به مدل دیجیتال دقیق از قوس دندانی نیاز دارد. به همین دلیل پس از تایید طرح درمان، زمان حضور اپراتور اسکن داخل دهانی هماهنگ می‌شود تا الاینرها با دقت بالا و تطابق کامل روی دندان‌های بیمار بنشینند.',
    ],
    [
        'q' => 'مراحل الاینر چه زمانی به مطب ارسال می‌شود؟',
        'a' => 'پس از انجام اسکن داخل دهانی و آماده‌سازی مدل دیجیتال، تمام مراحل الاینر بیمار ظرف یک تا دو هفته یکجا برای مطب شما ارسال می‌شود.',
    ],
    [
        'q' => 'هزینه طرح درمان و هر مرحله الاینر چگونه محاسبه می‌شود؟',
        'a' => 'هزینه طرح درمان یک‌بار برای هر بیمار محاسبه می‌شود و هزینه هر عدد الاینر جداگانه است. قیمت‌های به‌روز در بخش «هزینه‌های همکاری» همین صفحه و همچنین در لیست قیمت سایت قابل مشاهده است.',
    ],
    [
        'q' => 'چه بیمارانی برای درمان با الاینر شفاف مناسب‌تر هستند؟',
        'a' => 'نامرتبی خفیف تا متوسط، فاصله‌های بین دندانی، چرخش‌های خفیف دندان‌های قدامی و عود جزئی پس از درمان ارتودنسی قبلی معمولاً گزینه‌های مناسبی هستند. مواردی مانند ناهنجاری‌های اسکلتال شدید، اوپن‌بایت شدید یا نیاز به جابجایی عمودی قابل‌توجه برای الاینر مناسب نیستند.',
    ],
];

// ─── ساخت داده ساخت‌یافته (JSON-LD) ───
$canonical = public_url('pages/aligner.php');
$PAGE['schema'] = [
    '@context' => 'https://schema.org',
    '@graph' => [
        public_breadcrumb_schema('الاینر شفاف', $canonical),
        [
            '@type' => 'Service',
            'name' => 'درمان الاینر شفاف',
            'serviceType' => 'ارتودنسی با الاینر شفاف',
            'provider' => [
                '@type' => 'Organization',
                'name' => SITE_NAME,
                'url' => public_url('index.php'),
            ],
            'areaServed' => ['اسلامشهر', 'قزوین', 'تهران'],
            'description' => $PAGE['description'],
            'offers' => [
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
            '@type' => 'FAQPage',
            'mainEntity' => array_map(static fn(array $f): array => [
                '@type' => 'Question',
                'name' => $f['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
            ], $alignerFaqs),
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
    <style>
        /* استایل اختصاصی صفحه‌ی الاینر (مراحل، کیس‌های مناسب/نامناسب، FAQ) */
        #aligner .aligner-flow { margin: 8px 0 36px; }
        #aligner .aligner-flow-svg { display: block; width: 100%; height: auto; color: inherit; }
        #aligner h3 { margin-top: 0; }
        #aligner .section-lead { max-width: 760px; margin: 0 auto 28px; text-align: center; line-height: 2; }

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
        #aligner .aligner-steps li strong { display: block; margin-bottom: 6px; font-size: 1.02rem; color: #0F172A; }
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

        #aligner .aligner-cases { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin: 20px 0 12px; }
        #aligner .case-col {
            border: 1px solid rgba(0, 0, 0, .12);
            border-top: 4px solid #2e9e5b;
            border-radius: 10px;
            padding: 22px 24px;
        }
        #aligner .case-col.caution { border-top-color: #c98f00; }
        #aligner .case-col h4 { display: flex; align-items: center; gap: 8px; margin: 0 0 14px; font-size: 1.05rem; }
        #aligner .case-col ul {
            margin: 0;
            padding-inline-start: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            line-height: 1.8;
        }
        #aligner .aligner-hint { max-width: 760px; margin: 22px auto 0; font-size: .95rem; opacity: .85; line-height: 2; }
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

        #aligner .aligner-faq { max-width: 860px; margin: 44px auto 0; display: flex; flex-direction: column; gap: 12px; }
        #aligner .aligner-faq details { background: #fff; border: 1px solid #E5E7EB; border-radius: 12px; padding: 4px 18px; }
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
        #aligner .aligner-faq summary::after { content: '+'; flex: 0 0 auto; font-size: 1.25rem; color: #0f5cba; line-height: 1; }
        #aligner .aligner-faq details[open] summary::after { content: '−'; }
        #aligner .aligner-faq p { margin: 0 0 16px; line-height: 2; font-size: .95rem; color: #334155; }

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
<?php public_header(); ?>
<main>
    <div id="aligner">
        <div class="container" style="padding-top:56px; padding-bottom:56px;">

            <nav class="breadcrumb" aria-label="مسیر صفحه" style="display:flex; gap:8px; align-items:center; font-size:.85rem; color:#64748b; margin-bottom:18px;">
                <a href="<?= public_url('index.php') ?>">خانه</a>
                <span style="opacity:.5">›</span>
                <span>الاینر شفاف</span>
            </nav>

            <h1 style="margin:0 0 14px;"><?= htmlspecialchars($PAGE['h1']) ?></h1>
            <p class="section-lead"><?= htmlspecialchars($PAGE['lead']) ?></p>

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
                    برای کیس‌های قدام فک بالا و فول ماوث، <a href="<?= public_url('pages/photography-guide.php') ?>">راهنمای عکاسی بالینی</a> را ببینید.
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
                <?php foreach ($alignerFaqs as $faq): ?>
                    <details>
                        <summary><?= htmlspecialchars($faq['q']) ?></summary>
                        <p><?= htmlspecialchars($faq['a']) ?></p>
                    </details>
                <?php endforeach; ?>
            </div>

            <div class="aligner-links guide-links">
                <a href="<?= public_url('pages/photography-guide.php') ?>">راهنمای عکاسی بالینی<span>عکس‌های لازم برای کیس‌های قدام فک بالا و فول ماوث</span></a>
                <a href="<?= public_url('index.php#scanner-guide') ?>">راهنمای آماده‌سازی بیمار برای اسکن<span>چک‌لیست پیش از حضور اپراتور اسکن</span></a>
                <a href="<?= public_url('index.php#prices') ?>">لیست قیمت کامل خدمات<span>قیمت‌های به‌روز از پنل مدیریت</span></a>
                <a href="<?= public_url('index.php#works') ?>">نمونه کارها<span>نمونه‌ای از درمان‌های انجام‌شده</span></a>
            </div>

            <div style="text-align:center; margin-top:32px;">
                <a class="btn" href="<?= public_url('index.php#contact') ?>">شروع همکاری با EXOLAB</a>
            </div>

        </div>
    </div>
</main>
<?php public_footer_and_scripts(); ?>
