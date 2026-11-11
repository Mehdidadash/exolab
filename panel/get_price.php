<?php
// panel/get_price.php — خواندنِ «فی خدمت» و «فی طراحی»
//
// دو حالت:
//   ۱) ساده (سازگاری با کدِ قبلی و view_case.php):
//      GET  ?doctor_id=..&service_id=..
//      GET  ?doctor_id=..&service_id=..&price_type=design_fee
//      → {"price": 123}
//
//   ۲) ترکیبی — فرمِ کیس، **یک درخواست به‌جای دو درخواست**:
//      POST  combined=1&service_id=..&doctor_id=..&designer_id=..&lab_id=..&case_type=..
//      → {"ok":true,"price":123,"design_fee":456,"design_rate_found":true}
//
// ⚠️ خروجی همیشه JSON است — حتی در خطای داخلی؛ تا سمتِ کلاینت «پاسخِ خالی» نگیرد
//    (باگِ گزارش‌شده: در روزِ کندیِ سرور، این دو درخواست بدون خروجی برمی‌گشتند).
require_once __DIR__ . '/auth.php';
require_login();

/** خروجیِ JSON و پایان */
function gp_json(array $out)
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// ورودی: POST (فرمِ کیس) یا GET (سازگاری)
$src = (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') ? $_POST : $_GET;

$doctor_id   = isset($src['doctor_id'])   ? (int) $src['doctor_id']   : 0;
$lab_id      = isset($src['lab_id'])      ? (int) $src['lab_id']      : 0;
$service_id  = isset($src['service_id'])  ? (int) $src['service_id']  : 0;
$designer_id = isset($src['designer_id']) ? (int) $src['designer_id'] : 0;
$price_type  = isset($src['price_type'])  ? strtolower((string) $src['price_type']) : 'service';
$combined    = !empty($src['combined']);

/**
 * «فی خدمت» بر اساس نوعِ طرف‌حساب:
 *   lab_id  → نرخِ بین‌لابراتواری (کار از لابراتوار همکار)
 *   doctor  → نرخِ پزشک
 *   هیچ‌کدام → نرخِ اختصاصیِ شعبه، وگرنه قیمتِ عمومی
 */
function gp_unit_price(int $doctor_id, int $lab_id, int $service_id): ?float
{
    if ($service_id <= 0) return null;

    if ($lab_id > 0) {
        $p = getLabApplicablePrice($lab_id, $service_id);
        return $p !== null ? (float) $p : null;
    }
    if ($doctor_id > 0) {
        $p = getApplicablePrice($doctor_id, $service_id);
        return $p !== null ? (float) $p : null;
    }
    $p = getBranchServiceCustomPrice($service_id);
    if ($p === null) {
        $row = getPrice($service_id);
        $p = $row ? (float) $row['price'] : null;
    }
    return $p !== null ? (float) $p : null;
}

try {
    // ─── ۱) درخواستِ ترکیبی: فی خدمت + فی طراحی با هم ───
    if ($combined) {
        $unit   = gp_unit_price($doctor_id, $lab_id, $service_id);
        $design = null;
        if ($designer_id > 0 && $service_id > 0) {
            $design = getApplicableDesignFee($designer_id, $service_id);
        }
        gp_json([
            'ok'                => true,
            'price'             => $unit !== null ? round($unit) : null,
            'design_fee'        => $design !== null ? round((float) $design) : null,
            'design_rate_found' => $design !== null,
        ]);
    }

    // ─── ۲) نرخِ لابراتوار (مستقل از price_type — رفتارِ قبلی) ───
    if ($lab_id > 0) {
        if ($service_id <= 0) gp_json(['price' => null]);
        $price = getLabApplicablePrice($lab_id, $service_id);
        gp_json(['price' => $price !== null ? round((float) $price) : null]);
    }

    // ─── ۳) فی طراحی ───
    if ($price_type === 'design_fee') {
        $price = ($doctor_id > 0 && $service_id > 0)
            ? getApplicableDesignFee($doctor_id, $service_id)
            : null;
        gp_json(['price' => $price !== null ? round((float) $price) : null]);
    }

    // ─── ۴) فی خدمت ───
    $price = gp_unit_price($doctor_id, 0, $service_id);
    gp_json(['price' => $price !== null ? round($price) : null]);

} catch (Throwable $e) {
    // هیچ‌وقت خروجیِ خالی برنگردان (سمتِ کلاینت به‌جای «خطای شبکه» پیامِ روشن می‌گیرد)
    error_log('get_price.php: ' . $e->getMessage());
    gp_json([
        'ok'         => false,
        'error'      => 'internal',
        'price'      => null,
        'design_fee' => null,
    ]);
}
