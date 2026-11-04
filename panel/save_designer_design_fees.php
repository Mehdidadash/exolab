<?php
// panel/save_designer_design_fees.php
// ذخیرهٔ «فی طراحی» (نرخ هر واحد) همهٔ خدمات برای یک طراح — از تبِ
// prices.php?tab=designers. POST معمولی + ریدایرکت (بدون AJAX).
//
// منبعِ حقیقت: price_links با price_type='design_fee'، provider_type='designer'
// (یا 'doctor' برای ردیف‌های قدیمی)، provider_id = طراح، service_id = خدمت.
//
// ⚠️ هم‌گام‌سازی با جدول قدیمی:
// getApplicableDesignFee() وقتی USE_PRICE_LINKS=1 است ابتدا price_links را می‌خواند و
// اگر پیدا نکرد به doctor_price_overrides (design_fee) می‌رود. برای اینکه بقیهٔ
// بخش‌ها (فاکتور طراح، گزارش‌ها) همیشه عدد یکسان ببینند، هر ذخیره به هر دو جدول
// نوشته می‌شود — همان الگویی که syncLegacyFromPriceLink() استفاده می‌کند.
require_once __DIR__ . '/auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: prices.php?tab=designers');
    exit;
}
if (!verify_csrf()) {
    header('Location: prices.php?tab=designers&designer_error=csrf');
    exit;
}

$designerId = (int) ($_POST['designer_id'] ?? 0);

// طراح باید معتبر باشد (فعال و is_designer=1)
if ($designerId <= 0 || !isValidDesignerId($designerId)) {
    header('Location: prices.php?tab=designers&designer_error=designer');
    exit;
}

/**
 * ورودیِ مبلغ را تفسیر می‌کند و یکی از سه حالت را برمی‌گرداند:
 *   'empty'  → ورودی خالی یا «۰» = «حذف نرخ اختصاصی»
 *   'invalid'→ مقدارِ نامفهوم یا منفی (مثل «abc» یا «-5») = نادیده بگیر
 *   float    → مبلغِ معتبر
 *
 * ⚠️ این تفکیک مهم است: قبلاً هر مقدارِ نامعتبر هم «null» برمی‌گرداند و همان مسیرِ
 * حذف اجرا می‌شد؛ یعنی یک اشتباهِ تایپی می‌توانست نرخِ ثبت‌شده را بی‌صدا پاک کند.
 * حالا فقط خالی/صفر بودن «حذف» است؛ عددِ منفی یا متن، رد می‌شود.
 */
$parseAmount = function ($raw) {
    $s = trim((string) $raw);
    if ($s === '') return 'empty';
    // ارقام فارسی/عربی + جداکنندهٔ هزارگان
    $s = normalizePersianDigits($s);
    $s = str_replace([',', '،', ' ', '٫', '/'], '', $s);
    if (!is_numeric($s)) return 'invalid';
    $v = (float) $s;
    if ($v < 0) return 'invalid';   // منفی = ورودیِ اشتباه (نباید نرخ را پاک کند)
    if ($v == 0.0) return 'empty';  // صفر = بدون نرخ اختصاصی (مثل بقیهٔ صفحات قیمت)
    return round($v, 2);
};

$fees = $_POST['fees'] ?? [];
if (!is_array($fees)) $fees = [];

// فقط خدماتِ واقعی پذیرفته می‌شوند (جلوگیری از تزریق شناسهٔ دلخواه)
$validServiceIds = [];
foreach (db()->query('SELECT id FROM site_prices')->fetchAll(PDO::FETCH_COLUMN) as $v) {
    $validServiceIds[(int) $v] = true;
}

$branchId = currentBranchId() ?? 1;
$saved = 0;
$skipped = 0;

try {
    foreach ($fees as $serviceIdRaw => $priceRaw) {
        $serviceId = (int) $serviceIdRaw;
        if ($serviceId <= 0 || !isset($validServiceIds[$serviceId])) continue;

        $price = $parseAmount($priceRaw);

        // مقدارِ نامفهوم → نرخ دست‌نخورده می‌ماند (نه حذف و نه ثبتِ عددِ اشتباه)
        if ($price === 'invalid') {
            $skipped++;
            continue;
        }

        // آخرین ردیفِ design_fee این طراح برای این خدمت (همان ترتیبی که خوانده می‌شود)
        $find = db()->prepare("SELECT id, provider_type FROM price_links
                               WHERE price_type = 'design_fee'
                                 AND provider_type IN ('designer','doctor')
                                 AND provider_id = ?
                                 AND service_id = ?
                               ORDER BY id DESC LIMIT 1");
        $find->execute([$designerId, $serviceId]);
        $existing = $find->fetch();

        if ($price === 'empty') {
            // ── حذف نرخ اختصاصی ──
            if ($existing) {
                db()->prepare('DELETE FROM price_links WHERE id = ?')->execute([(int) $existing['id']]);
                // هم‌گام‌سازی: حذف ردیف قدیمی (همهٔ ردیف‌های معتبر، مستقل از شعبه)
                db()->prepare("DELETE FROM doctor_price_overrides WHERE doctor_id = ? AND price_type = 'design_fee' AND service_id = ?")
                    ->execute([$designerId, $serviceId]);
                $saved++;
            }
            continue;
        }

        // ── درج/به‌روزرسانی ──
        if ($existing) {
            db()->prepare('UPDATE price_links SET price = ?, branch_id = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$price, $branchId, (int) $existing['id']]);
        } else {
            // receiver: استرالی/شعبه‌ای که می‌پردازد. در این صفحه «گیرندهٔ» نرخ مشخص
            // نیست، پس شعبهٔ جاری (همان چیزی که syncLegacyFromPriceLink استفاده می‌کند)
            // به‌عنوان گیرنده ثبت می‌شود تا ردیف با جدولِ قدیمی هم‌خوان بماند.
            db()->prepare("INSERT INTO price_links (service_id, provider_type, provider_id, receiver_type, receiver_id, price, price_type, branch_id, note, active, created_at, updated_at)
                           VALUES (?, 'designer', ?, 'branch', ?, ?, 'design_fee', ?, '', 1, NOW(), NOW())")
                ->execute([$serviceId, $designerId, $branchId, $price, $branchId]);
        }

        // هم‌گام‌سازی با جدولِ قدیمی (اگر USE_PRICE_LINKS خاموش شود، این جدول خوانده می‌شود)
        try {
            upsertDoctorPriceOverrideLegacy($designerId, $serviceId, 'design_fee', $price, $branchId);
        } catch (Throwable $e) {
            error_log('save_designer_design_fees legacy sync: ' . $e->getMessage());
        }
        $saved++;
    }
} catch (Throwable $e) {
    error_log('save_designer_design_fees: ' . $e->getMessage());
    header('Location: prices.php?tab=designers&designer_id=' . $designerId . '&designer_error=db');
    exit;
}

audit_log_save('designer_design_fees', $designerId, 'نرخ طراحی طراح');
$q = 'prices.php?tab=designers&designer_id=' . $designerId . '&designer_saved=' . $saved;
if ($skipped > 0) $q .= '&designer_skipped=' . $skipped;
header('Location: ' . $q);
exit;
