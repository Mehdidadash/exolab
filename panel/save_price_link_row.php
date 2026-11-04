<?php
// panel/save_price_link_row.php
// ذخیرهٔ AJAX یک یا چند ردیفِ «نرخ اختصاصی» از تب‌های prices.php
// (designers / doctors / clinics / labs / branches) — **بدون رفرش صفحه**.
//
// ورودیِ JSON:
//   { kind, entity_id, counterparty_id, fees: { <serviceId>: "<مبلغ یا رشتهٔ خالی>" } }
// خروجیِ JSON:
//   { success, results: [{service_id, saved, price}], skipped, message }
//
// ⚠️ قواعدِ مهم:
//   • فقط کلیدهای تبِ شناخته‌شده پذیرفته می‌شوند.
//   • فقط خدماتی که در site_prices وجود دارند.
//   • قیمتِ خالی/صفر = حذفِ نرخ؛ متن/عددِ منفی = رد شدن (نرخ دست‌نخورده می‌ماند).
//   • دسترسیِ هر طرف با priceTabCanEdit() بررسی می‌شود (مدیر شعبه فقط طرف‌های خودش،
//     و در تبِ بین‌شعبه‌ای فقط نرخی که خودش پرداخت‌کننده است).
require_once __DIR__ . '/auth.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'روش درخواست مجاز نیست.']);
    exit;
}

// CSRF از هدر یا بدنهٔ JSON
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$raw = file_get_contents('php://input');
$payload = [];
if ($raw !== '' && $raw !== false) {
    $payload = json_decode($raw, true) ?: [];
}
if ($token === '') {
    $token = (string) ($payload['_csrf_token'] ?? ($_POST['_csrf_token'] ?? ''));
}
$sessionToken = $_SESSION['_csrf_token'] ?? '';
if (empty($sessionToken) || !hash_equals($sessionToken, (string) $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'توکن امنیتی نامعتبر است؛ صفحه را رفرش کنید.']);
    exit;
}

$kindKey        = (string) ($payload['kind'] ?? '');
$entityId       = (int) ($payload['entity_id'] ?? 0);
$counterpartyId = (int) ($payload['counterparty_id'] ?? 0);
$direction      = (string) ($payload['direction'] ?? '');
if (!in_array($direction, ['in', 'out'], true)) {
    $direction = 'out';
}
$fees           = $payload['fees'] ?? [];

if (!priceTabKind($kindKey)) {
    echo json_encode(['success' => false, 'message' => 'تب نامعتبر است.']);
    exit;
}
if (!is_array($fees)) {
    echo json_encode(['success' => false, 'message' => 'ساختار داده نامعتبر است.']);
    exit;
}

$user     = current_user();
$isRoot   = is_root_admin();
$myBranch = currentBranchId() ?? 1;

// ── تبِ بین‌شعبه‌ای ──
// جفتِ صریح: counterparty_id = خدمت‌دهنده (انجام‌دهنده) ، entity_id = خدمت‌گیرنده (پرداخت‌کننده).
// قاعده: هر شعبه فقط وقتی ویرایش می‌کند که **خودش خدمت‌دهنده** باشد.
if ($kindKey === 'branches') {
    if (!$isRoot && $counterpartyId !== $myBranch) {
        echo json_encode([
            'success' => false,
            'message' => 'برای تغییر این نرخ باید «خدمت‌دهنده» شعبهٔ خودتان باشد. این نرخ فقط برای مشاهده در دسترس است.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($entityId <= 0) {
    echo json_encode(['success' => false, 'message' => 'طرفِ حساب انتخاب نشده است.']);
    exit;
}

// ── بررسی دسترسی ──
$validEntityIds = array_map(fn($e) => (int) $e['id'], priceTabEntityOptions($kindKey));
if (!in_array($entityId, $validEntityIds, true)) {
    echo json_encode(['success' => false, 'message' => 'طرفِ حساب معتبر نیست.']);
    exit;
}
if ($kindKey === 'labs') {
    // در تبِ لابراتوار، counterparty_id = شعبهٔ طرفِ ما (نه لابراتوار)
    $validBranchIds = array_map(fn($b) => (int) $b['id'], getAllBranches());
    if ($counterpartyId <= 0) $counterpartyId = $myBranch;
    if (!in_array($counterpartyId, $validBranchIds, true)) {
        echo json_encode(['success' => false, 'message' => 'شعبهٔ انتخاب‌شده معتبر نیست.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    if ($counterpartyId > 0 && !in_array($counterpartyId, $validEntityIds, true)) {
        echo json_encode(['success' => false, 'message' => 'شعبهٔ خدمت‌دهنده معتبر نیست.']);
        exit;
    }
    if ($counterpartyId > 0 && $counterpartyId === $entityId) {
        echo json_encode(['success' => false, 'message' => 'خدمت‌دهنده و خدمت‌گیرنده نمی‌توانند یکی باشند.']);
        exit;
    }
}

// دسترسی: برای تبِ بین‌شعبه‌ای، «خدمت‌دهنده» بودنِ شعبهٔ جاری قبلاً بررسی شد.
if ($kindKey !== 'branches'
    && !priceTabCanEdit($kindKey, $entityId, $myBranch, $isRoot, $counterpartyId > 0 ? $counterpartyId : null, $kindKey === 'labs' ? $direction : null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'شما اجازهٔ تغییر این نرخ‌ها را ندارید.']);
    exit;
}

// ── خدماتِ معتبر ──
$validServiceIds = [];
foreach (db()->query('SELECT id FROM site_prices')->fetchAll(PDO::FETCH_COLUMN) as $v) {
    $validServiceIds[(int) $v] = true;
}

$results = [];
$skipped = 0;

foreach ($fees as $serviceIdRaw => $priceRaw) {
    $serviceId = (int) $serviceIdRaw;
    if ($serviceId <= 0 || !isset($validServiceIds[$serviceId])) continue;

    $parsed = priceTabParseAmount($priceRaw);
    if ($parsed === 'invalid') {
        $skipped++;
        continue;
    }

    $price = ($parsed === 'empty') ? null : (float) $parsed;

    $ok = priceTabUpsert($kindKey, $entityId, $serviceId, $price, $myBranch, $counterpartyId > 0 ? $counterpartyId : null, $kindKey === 'labs' ? $direction : null);
    if (!$ok) {
        $why = $GLOBALS['priceTabLastError'] ?? '';
        echo json_encode([
            'success' => false,
            'message' => $why === 'no_pair'
                ? 'خدمت‌دهنده و خدمت‌گیرنده باید هر دو مشخص باشند.'
                : 'ذخیره انجام نشد. لطفاً دوباره تلاش کنید.',
            'results' => $results,
            'skipped' => $skipped,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $results[] = [
        'service_id' => $serviceId,
        'saved'      => $price !== null,
        'price'      => $price,
    ];
}

if (empty($results) && $skipped === 0) {
    echo json_encode(['success' => false, 'message' => 'ردیفی برای ذخیره ارسال نشد.']);
    exit;
}

audit_log_save('price_link_row', $entityId, 'نرخ اختصاصی (' . $kindKey . ')');

echo json_encode([
    'success' => true,
    'results' => $results,
    'skipped' => $skipped,
    'message' => 'ذخیره شد.',
], JSON_UNESCAPED_UNICODE);
