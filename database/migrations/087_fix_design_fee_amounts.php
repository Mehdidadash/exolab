<?php
/**
 * 087_fix_design_fee_amounts.php — اصلاح «فی طراحی»هایی که با تقسیم بر تعداد خراب شده‌اند
 *
 * ── زمینه ──
 * ستون `cases.design_fee` «فی هر واحد» است (نه جمع کل). در مهاجرت 074 این مقدار
 * یک‌بار بر quantity تقسیم شد و بعداً (076/082/086) بخشی از خرابی‌ها برگردانده شد،
 * ولی چند دسته جا ماند؛ چون نتیجهٔ تقسیم (مثلاً ۸۰٬۰۰۰ برای کیسِ دو واحدی که فی
 * واقعی‌اش ۱۶۰٬۰۰۰ بوده) خودش «شبیه یک نرخ معتبر» است و فیلترهای قبلی آن را رد می‌کردند.
 *
 * ── نرخ‌های واقعی (تأیید کاربر) ──
 *   گروه A (روکش زیرکونیا/اباتمنت/اندو/مونولیتیک/PMMA ...):  ۱۶۰٬۰۰۰ ← ۱۸۰٬۰۰۰
 *   گروه B (فریم روی دندان، PFM روی دندان، فریم پرینت فلز دندانی): ۶۰٬۰۰۰ ← ۸۰٬۰۰۰
 *   گروه C (فریم روی اباتمنت، PFM روی ایمپلنت، فریم پرینت فلز ایمپلنت): ۸۰٬۰۰۰ ← ۱۱۰٬۰۰۰
 *   مرز تغییر نرخ = ۱۴۰۵/۰۸/۰۱ = 2026-10-23
 *
 * ── قاعده‌های اصلاح (فقط روی کیس‌های بدون فاکتور طراحی) ──
 *   R1) اگر `design_fee × quantity` برابرِ نرخِ درستِ همان خدمت باشد و خودِ design_fee نباشد
 *       → design_fee = نرخ درست (یعنی عدد قبلاً بر تعداد تقسیم شده بود).
 *   R3) اگر خدمت نرخِ اختصاصیِ خودش را دارد ولی روی کیس «نرخ عمومی ۱۶۰٬۰۰۰» نشسته باشد
 *       (نشانهٔ این‌که هنگام ثبت، نرخِ خدمت پیدا نشده و نرخ عمومی به ارث رسیده)
 *       → نرخِ همان دورهٔ تاریخِ دریافت گذاشته می‌شود.
 *   بقیهٔ مواردِ مشکوک فقط گزارش می‌شوند (تا با تأیید کاربر اصلاح شوند).
 *   کیس‌هایی که فاکتور طراحی دارند (designer_invoice_id) دست‌نخورده می‌مانند.
 *
 * اجرا:  php database/migrate.php            (یا مرورگر با کلید مهاجرت)
 * آزمون خشک: $GLOBALS['EXOLAB_DESIGN_FIX_DRY_RUN'] = true;  (چیزی ذخیره نمی‌شود)
 */

if (!defined('DB_NAME')) {
    require_once __DIR__ . '/../../panel/config.php';
}
require_once __DIR__ . '/../../panel/db.php';

/** نرخ قدیم/جدید هر خدمت (فی هر واحد، تومان) — null یعنی «تغییر نکرده / نامشخص» */
function exolab_design_rate_map(): array
{
    $groupA = [1, 2, 4, 5, 6, 7, 18, 26, 28, 29];  // 160,000 → 180,000
    $groupB = [14, 19, 24];                          //  60,000 →  80,000
    $groupC = [15, 25, 27];                          //  80,000 → 110,000

    $map = [];
    foreach ($groupA as $id) $map[$id] = ['old' => 160000.0, 'new' => 180000.0];
    foreach ($groupB as $id) $map[$id] = ['old' =>  60000.0, 'new' =>  80000.0];
    foreach ($groupC as $id) $map[$id] = ['old' =>  80000.0, 'new' => 110000.0];
    return $map;
}

function exolab_design_cutoff(): string
{
    return '2026-10-23';   // 1405/08/01
}

/**
 * نرخِ درستِ «فی طراحی» برای یک کیس بر اساس خدمت و تاریخ دریافت.
 * @return array|null ['rate'=>float, 'basis'=>'old|new'] یا null اگر نرخ تغییر نکرده/نامشخص است
 */
function exolab_design_expected_rate(array $case, array $map): ?array
{
    $svc = (int) ($case['service_id'] ?? 0);
    if (!isset($map[$svc])) return null;

    $recv = (string) ($case['received_date'] ?? '');
    $isBefore = ($recv === '' || $recv < exolab_design_cutoff());
    return $isBefore
        ? ['rate' => $map[$svc]['old'], 'basis' => 'old']
        : ['rate' => $map[$svc]['new'], 'basis' => 'new'];
}

$dryRun = !empty($GLOBALS['EXOLAB_DESIGN_FIX_DRY_RUN']);
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$map = exolab_design_rate_map();

$svcIds = array_keys($map);
$in = implode(',', array_map('intval', $svcIds));

$stmt = $pdo->query("
    SELECT c.id, c.service_id, c.quantity, c.design_fee, c.received_date,
           c.designer_invoice_id, c.designer_id
    FROM cases c
    WHERE c.service_id IN ($in)
      AND c.designer_id IS NOT NULL
      AND c.design_fee > 0
    ORDER BY c.id
");
$cases = $stmt->fetchAll();

$fix  = [];   // id => ['from'=>, 'to'=>, 'rule'=>, 'qty'=>]
$skip = [];   // id => دلیل

foreach ($cases as $c) {
    $exp = exolab_design_expected_rate($c, $map);
    if ($exp === null) continue;

    $qty  = max(1, (int) $c['quantity']);
    $fee  = (float) $c['design_fee'];
    $want = (float) $exp['rate'];

    if (abs($fee - $want) < 0.5) continue;   // درست است

    // کیسِ فاکتورشده دست نمی‌خورد (فاکتور طراحی قبلاً با همین عدد صادر شده)
    if (!empty($c['designer_invoice_id'])) {
        $skip[(int) $c['id']] = 'در فاکتور طراحی #' . (int) $c['designer_invoice_id'] . ' ثبت شده';
        continue;
    }

    // R1: فی × تعداد == نرخ درست → فی قبلاً بر تعداد تقسیم شده بود
    if (abs($fee * $qty - $want) < 0.5) {
        $fix[(int) $c['id']] = ['from' => $fee, 'to' => $want, 'rule' => 'R1/تقسیم بر تعداد', 'qty' => $qty];
        continue;
    }

    // R3: خدمت نرخ اختصاصی دارد ولی نرخِ عمومی ۱۶۰٬۰۰۰ روی کیس نشسته است
    $groupB = [14, 19, 24];
    $groupC = [15, 25, 27];
    $svcId = (int) $c['service_id'];
    if ((in_array($svcId, $groupB, true) || in_array($svcId, $groupC, true)) && abs($fee - 160000.0) < 0.5) {
        $fix[(int) $c['id']] = ['from' => $fee, 'to' => $want, 'rule' => 'R3/نرخ عمومی روی خدمتِ دارای نرخ اختصاصی', 'qty' => $qty];
        continue;
    }

    $skip[(int) $c['id']] = 'مقدار غیرِ استاندارد (' . number_format($fee) . ') — نیاز به تأیید دستی';
}

$out = function (string $s) { echo $s, PHP_EOL; };

$out('── کیس‌های بررسی‌شده: ' . count($cases));
$out('── نیازمند اصلاح: ' . count($fix));
foreach ($fix as $id => $f) {
    $out(sprintf('   #%d : %s → %s  (%s، تعداد %d)', $id, number_format($f['from']), number_format($f['to']), $f['rule'], $f['qty']));
}
if ($skip) {
    $out('── بدون تغییر (نیاز به تصمیم): ' . count($skip));
    foreach ($skip as $id => $why) $out(sprintf('   #%d : %s', $id, $why));
}

if ($dryRun) {
    $out('── حالت آزمون خشک: هیچ تغییری ذخیره نشد.');
    return;
}

if (!$fix) {
    $out('── چیزی برای اصلاح نبود.');
    return;
}

$upd = $pdo->prepare('UPDATE cases SET design_fee = ?, design_fee_is_unit = 1, updated_at = NOW() WHERE id = ? AND designer_invoice_id IS NULL');
$done = 0;
foreach ($fix as $id => $f) {
    $upd->execute([$f['to'], $id]);
    $done += $upd->rowCount();
}
$out('── اصلاح شد: ' . $done . ' کیس');
