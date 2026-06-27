<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/helpers.php';
$prices = getPrices();
$works = getPortfolioWorks();
$contact = require __DIR__ . '/contact.php';
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
    <title><?= SITE_NAME ?></title>
    <meta name="description" content="<?= SITE_DESCRIPTION ?>">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/lightbox.css">
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
                    <h3>لابراتوار دندانسازی دیجیتال</h3>
                    <p>ساخت پروتزهای دندانپزشکی با فناوری دیجیتال و کیفیت بالا.</p>
                </div>
                <div class="card">
                    <h3>خدمات اسکن داخل دهانی</h3>
                    <p>اسکن داخل دهانی در مطب دندانپزشکان برای ساخت سریع و دقیق پروتز و روکش.</p>
                </div>
                <div class="card">
                    <h3>خدمات میلینگ سنتری</h3>
                    <p>ارائه خدمات دقیق و سریع میلینگ سنتری برای لابراتوارهای همکار.</p>
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
                            <img src="assets/uploads/<?= htmlspecialchars($work['image_filename']) ?>" alt="<?= htmlspecialchars($work['title']) ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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
            <p><a href="admin/login.php">ورود مدیر</a></p>
        </div>
        <div>
            <p>دامنه: <strong>exolab.ir</strong></p>
            <p>IP: <strong>5.144.129.129</strong></p>
        </div>
    </div>
</footer>
<script src="assets/js/main.js"></script>
<script src="assets/js/lightbox.js"></script>
</body>
</html>
