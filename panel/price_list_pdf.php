<?php
// panel/price_list_pdf.php
// ساخت «لیست قیمت PDF» از نرخ‌های تب‌های قیمت‌ها — با انتخابِ خدمت‌ها، ویرایشِ نرخ برای
// همان PDF (بدون تغییرِ نرخ‌های ذخیره‌شده)، ویرایشِ توضیحات، و انتخابِ ستون‌های خروجی.
//
// «با طراحی / بدون طراحی»:
//   اگر «نرخ با طراحی» را بخواهید، در PDF فقط یک ستونِ «نرخ با طراحی» چاپ می‌شود
//   (نه فی طراحی جداگانه). مقدارش با فرمولِ انتخابی حساب می‌شود:
//        نرخ با طراحی = نرخ پایه + رندِ‌بالا( فی طراحی × ضریب )
//
// هیچ چیزی در دیتابیس ذخیره نمی‌شود؛ فقط خروجی PDF ساخته می‌شود.
require_once __DIR__ . '/auth.php';
require_admin();

use Mpdf\Mpdf;
use Morilog\Jalali\Jalalian;

$user     = current_user();
$isRoot   = is_root_admin();
$myBranch = currentBranchId() ?? 1;
$csrf     = $_SESSION['_csrf_token'] ?? '';
if ($csrf === '') {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    $csrf = $_SESSION['_csrf_token'];
}

$sources = [
    'general'   => 'قیمت‌های عمومی (پیش‌فرض)',
    'doctors'   => 'نرخ پزشکان',
    'clinics'   => 'نرخ کلینیک‌ها',
    'designers' => 'نرخ طراحی طراحان',
    'labs'      => 'نرخ لابراتوارها',
    'branches'  => 'نرخ بین‌شعبه‌ای',
];

$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');
$in = function (string $key, string $default = '') use ($isPost) {
    $v = $isPost ? ($_POST[$key] ?? $default) : ($_GET[$key] ?? $default);
    return is_scalar($v) ? (string) $v : $default;
};

$src = $in('source', 'general');
if (!isset($sources[$src])) $src = 'general';
$entityId       = (int) $in('entity_id', '0');
$providerBranch = (int) $in('provider_branch', '0');
$labDir         = ($in('dir', 'out') === 'in') ? 'in' : 'out';
$designerId     = (int) $in('designer_id', '0');
$docTitle       = trim($in('doc_title'));
$docNote        = trim($in('doc_note'));

// ── گزینه‌های نمایش ──
$showDesign = $in('show_design', '0') === '1';
$designMult = (float) str_replace(',', '', $in('design_mult', '1'));
if ($designMult < 0) $designMult = 0;
$roundOptions = [
    0      => 'بدون رند',
    1000   => 'رند به بالا — ۱٬۰۰۰',
    5000   => 'رند به بالا — ۵٬۰۰۰',
    10000  => 'رند به بالا — ۱۰٬۰۰۰',
    50000  => 'رند به بالا — ۵۰٬۰۰۰',
    100000 => 'رند به بالا — ۱۰۰٬۰۰۰',
];
$designRound = (int) $in('design_round', '0');
if (!isset($roundOptions[$designRound])) $designRound = 0;
$showDesc  = $in('show_desc', '1') === '1';
$showShort = $in('show_short', '0') === '1';
// نامِ طرف (پزشک/کلینیک/لابراتوار) در سربرگ الزامی نیست؛ پیش‌فرض پنهان است.
$showParty = $in('show_party', '0') === '1';

/** نرخ با طراحی طبق فرمولِ انتخابی: پایه + رندِ‌بالا(فی طراحی × ضریب). */
function plWithDesign(float $base, float $design, float $mult, int $roundStep): float
{
    $adj = $design * $mult;
    if ($roundStep > 0 && $adj > 0) {
        $adj = ceil($adj / $roundStep) * $roundStep;
    }
    return round($base + $adj);
}

$entityOptions = $src === 'general' ? [] : priceTabEntityOptions($src);
$entityName = '';
foreach ($entityOptions as $e) {
    if ((int) $e['id'] === $entityId) { $entityName = (string) $e['name']; break; }
}
$providerName = '';
if ($src === 'branches' && $providerBranch > 0) {
    foreach (getAllBranches() as $b) {
        if ((int) $b['id'] === $providerBranch) { $providerName = (string) $b['name']; break; }
    }
}
$designerOptions = priceTabEntityOptions('designers');

/** @return array<int,float> service_id => نرخ اختصاصیِ ثبت‌شده */
function plUnitPriceMap(string $src, int $entityId, int $myBranch, string $labDir, int $providerBranch): array
{
    if ($src === 'general' || $entityId <= 0) return [];
    $counterparty = $src === 'branches' ? ($providerBranch > 0 ? $providerBranch : null)
                  : ($src === 'labs' ? $myBranch : null);
    $dir = $src === 'labs' ? $labDir : null;
    $map = [];
    foreach (priceTabLinks($src, $entityId, $myBranch, $counterparty, $dir) as $sid => $row) {
        $map[(int) $sid] = (float) $row['price'];
    }
    return $map;
}

$unitMap  = plUnitPriceMap($src, $entityId, $myBranch, $labDir, $providerBranch);
$services = getAllPrices();

/** فی طراحیِ پیشنهادی برای یک خدمت (نرخ طراحِ انتخابی، وگرنه پیش‌فرضِ خدمت). */
function plDesignFeeFor(int $designerId, array $svc): float
{
    if ($designerId > 0) {
        $v = getApplicableDesignFee($designerId, (int) $svc['id']);
        return $v !== null ? (float) $v : 0.0;
    }
    $d = $svc['default_design_fee'] ?? null;
    return ($d !== null && $d !== '') ? (float) $d : 0.0;
}

/**
 * انتخابِ پیش‌فرضِ هر خدمت بر اساسِ دسته و نوعِ طرف:
 *   • دندانپزشک/کلینیک → فقط «دندانپزشکان» و «مشترک» (همکاری/عمومی انتخاب‌نشده)
 *   • لابراتوار        → «همکاری»، «عمومی»، «مشترک» + هر خدمتی که نرخ اختصاصی دارد
 *   • بقیه             → فقط خدماتی که نرخ اختصاصی دارند
 */
function plDefaultSelected(string $src, string $category, bool $hasCustom): bool
{
    $cat = normalizeServiceCategory($category);
    if ($src === 'doctors' || $src === 'clinics') {
        return in_array($cat, ['دندانپزشکان', 'مشترک'], true);
    }
    if ($src === 'labs') {
        return $hasCustom || in_array($cat, ['همکاری', 'عمومی', 'مشترک'], true);
    }
    return $hasCustom;
}

// ─────────── تولید PDF ───────────
if ($isPost && $in('action') === 'generate') {
    if (!hash_equals($csrf, (string) ($_POST['_csrf_token'] ?? ''))) {
        http_response_code(403);
        die('توکن امنیتی نامعتبر است؛ صفحه را رفرش کنید.');
    }
    if ($src !== 'general' && $entityId <= 0) {
        die('ابتدا منبعِ نرخ (پزشک/طراح/کلینیک/لابراتوار/شعبه) را انتخاب کنید.');
    }

    $rowsIn = $_POST['rows'] ?? [];
    $lines  = [];
    foreach ($services as $svc) {
        $sid = (int) $svc['id'];
        if (empty($rowsIn[$sid]['on'])) continue;

        $unit   = priceTabParseAmount($rowsIn[$sid]['price'] ?? '');
        $design = priceTabParseAmount($rowsIn[$sid]['design'] ?? '');
        if ($unit === 'invalid' || $design === 'invalid') continue;

        $unitVal   = ($unit === 'empty') ? 0.0 : (float) $unit;
        $designVal = ($design === 'empty') ? 0.0 : (float) $design;
        $descRaw   = trim((string) ($rowsIn[$sid]['desc'] ?? ''));

        $lines[] = [
            'title'  => (string) $svc['title'],
            'short'  => (string) ($svc['short_name'] ?? ''),
            'desc'   => $descRaw !== '' ? $descRaw : (string) ($svc['description'] ?? ''),
            'unit'   => $unitVal,
            'design' => $designVal,
            'with'   => plWithDesign($unitVal, $designVal, $designMult, $designRound),
        ];
    }
    if (!$lines) {
        die('هیچ خدمتی برای ساخت PDF انتخاب نشده است.');
    }

    // همهٔ اعدادِ PDF با ارقام فارسی و جداکنندهٔ هزارگانِ فارسی (٬) چاپ می‌شوند
    $money = fn(float $v): string => toPersianDigits(str_replace(',', '٬', number_format($v, 0, '.', ',')));
    $docTitleSafe = $docTitle !== '' ? htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8') : 'لیست قیمت خدمات';
    $today = toPersianDigits(Jalalian::fromDateTime(new DateTime())->format('Y/m/d'));

    $metaParts = [$sources[$src]];
    if ($showParty) {
        if ($entityName !== '') {
            $metaParts[] = $entityName;
            if ($src === 'branches' && $providerName !== '') {
                $metaParts[] = 'انجام‌دهنده: ' . $providerName;
            }
        }
        if ($src === 'labs') {
            $metaParts[] = $labDir === 'in' ? 'کار از لابراتوار (دریافتی ما)' : 'برون‌سپاری (پرداختی ما)';
        }
    }
    $metaParts[] = 'تاریخ: ' . $today;
    $meta = implode(' — ', $metaParts);

    $priceLabel = $showDesign ? 'نرخ با طراحی' : 'نرخ';
    $multLabel  = toPersianDigits(rtrim(rtrim(number_format($designMult, 2, '.', ''), '0'), '.'));
    $formulaText = $showDesign
        ? ('نرخ پایه + ' . ($designMult == 1 ? 'فی طراحی' : 'فی طراحی × ' . $multLabel)
            . ($designRound > 0 ? ' (رند به بالا به مضربِ ' . toPersianDigits(number_format($designRound)) . ')' : '') . ' — تومان')
        : 'تومان';

    $thead = '<tr><th style="width:24px;">#</th><th>خدمت</th>'
        . ($showShort ? '<th style="width:44px;">اختصاری</th>' : '')
        . ($showDesc ? '<th>توضیحات</th>' : '')
        . '<th style="width:86px;">' . $priceLabel . '</th></tr>';

    $tbody = '';
    $i = 0;
    foreach ($lines as $l) {
        $i++;
        $tbody .= '<tr>'
            . '<td style="text-align:center;">' . toPersianDigits((string) $i) . '</td>'
            . '<td>' . htmlspecialchars($l['title'], ENT_QUOTES, 'UTF-8') . '</td>'
            . ($showShort ? '<td style="text-align:center;font-family:monospace;">' . htmlspecialchars($l['short'], ENT_QUOTES, 'UTF-8') . '</td>' : '')
            . ($showDesc ? '<td style="font-size:8.5px;color:#374151;">' . nl2br(htmlspecialchars($l['desc'], ENT_QUOTES, 'UTF-8')) . '</td>' : '')
            . '<td style="text-align:left;font-weight:bold;">' . $money($showDesign ? $l['with'] : $l['unit']) . '</td>'
            . '</tr>';
    }

    $noteHtml = $docNote !== ''
        ? '<div class="note">' . nl2br(htmlspecialchars($docNote, ENT_QUOTES, 'UTF-8')) . '</div>'
        : '';

    $html = '<html dir="rtl"><head><meta charset="UTF-8"><style>
        body { font-family: vazir; direction: rtl; text-align: right; }
        .header { text-align:center; border-bottom:1px solid #E5E7EB; padding-bottom:10px; margin-bottom:12px; }
        .brand { font-size:20px; font-weight:bold; color:#003D9A; letter-spacing:2px; }
        .title { font-size:15px; font-weight:bold; color:#0F172A; margin-top:4px; }
        .meta { font-size:9px; color:#475569; margin-top:4px; }
        .formula { font-size:8px; color:#64748b; margin-top:2px; }
        table { width:100%; border-collapse:collapse; margin-top:8px; }
        th { background:#E5E7EB; color:#0F172A; padding:6px; font-size:9px; text-align:right; border:1px solid #E5E7EB; }
        td { padding:5px 6px; font-size:9px; border-bottom:1px solid #E5E7EB; vertical-align:top; }
        .note { margin-top:14px; padding:8px 10px; border:1px solid #E5E7EB; background:#F8FAFC; font-size:9px; color:#334155; }
        .footer { margin-top:18px; text-align:center; font-size:8px; color:#94a3b8; border-top:1px solid #E5E7EB; padding-top:8px; }
    </style></head><body>
        <div class="header">
            <div class="brand">EXOLAB</div>
            <div class="title">' . $docTitleSafe . '</div>
            <div class="meta">' . htmlspecialchars($meta, ENT_QUOTES, 'UTF-8') . '</div>
            <div class="formula">' . htmlspecialchars($formulaText, ENT_QUOTES, 'UTF-8') . '</div>
        </div>
        <table><thead>' . $thead . '</thead><tbody>' . $tbody . '</tbody></table>'
        . $noteHtml
        . '<div class="footer">مبالغ به تومان است. این لیست اعتبار زمانی محدود دارد و جایگزین قرارداد نیست.</div>
    </body></html>';

    $mpdf = new Mpdf([
        'mode'              => 'utf-8',
        'format'            => 'A4',
        'default_font'      => 'vazir',
        'fontDir'           => [__DIR__ . '/../assets/fonts'],
        'fontdata'          => ['vazir' => ['R' => 'Vazir.ttf', 'B' => 'Vazir-Bold.ttf', 'useOTL' => 0xFF]],
        'margin_left'       => 12,
        'margin_right'      => 12,
        'margin_top'        => 12,
        'margin_bottom'     => 14,
        'default_font_size' => 9,
        'autoScriptToLang'  => true,
        'autoLangToFont'    => true,
    ]);
    $mpdf->SetDirectionality('rtl');
    $mpdf->WriteHTML($html);
    $mpdf->Output('price-list-' . date('Ymd-His') . '.pdf', \Mpdf\Output\Destination::INLINE);
    exit;
}

// ─────────── فرمِ سازنده ───────────
panel_layout_start('لیست قیمت PDF');
?>
<style>
    .plp-card{ background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px 18px; margin-bottom:16px; }
    .plp-grid{ display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
    .plp-grid .form-group{ margin:0; min-width:180px; }
    .plp-opts{ display:flex; gap:18px; flex-wrap:wrap; align-items:center; margin-top:12px; padding-top:12px; border-top:1px dashed #e2e8f0; font-size:.9rem; }
    .plp-opts label{ display:flex; gap:6px; align-items:center; cursor:pointer; font-weight:600; color:#334155; }
    .plp-table{ width:100%; border-collapse:collapse; }
    .plp-table th, .plp-table td{ padding:5px 6px; border-bottom:1px solid #eef2f7; font-size:.88rem; vertical-align:middle; }
    .plp-table th{ background:#f8fafc; color:#334155; white-space:nowrap; }
    .plp-table input[type=number]{ width:120px; }
    .plp-table input[type=text]{ width:100%; min-width:150px; }
    .plp-table .plp-short{ font-family:monospace; direction:ltr; text-align:center; color:#0369a1; font-weight:700; }
    .plp-table tr.is-off td{ opacity:.45; }
    .plp-with{ font-weight:700; color:#15803d; white-space:nowrap; }
    .plp-bar{ position:sticky; bottom:0; background:#fff; border-top:1px solid #e2e8f0; padding:10px 12px; display:flex; gap:10px; justify-content:space-between; align-items:center; border-radius:0 0 12px 12px; }
    .plp-hint{ color:#64748b; font-size:.82rem; }
</style>

<?php if ($src === 'general'): ?>
    <div class="plp-card" style="background:#f0f9ff; border-color:#bae6fd; color:#075985;">
        برای «قیمت‌های عمومی» نرخ‌ها از کاتالوگِ پیش‌فرضِ خدمات خوانده می‌شوند. برای لیستِ اختصاصیِ یک طرف،
        تبِ مربوطه (پزشک/کلینیک/طراح/لابراتوار/شعبه) را انتخاب کنید.
    </div>
<?php endif; ?>

<form method="get" action="price_list_pdf.php" class="plp-card" id="plp-settings">
    <div class="plp-grid">
        <div class="form-group">
            <label for="plp-source">منبعِ نرخ</label>
            <select id="plp-source" name="source" onchange="this.form.submit()">
                <?php foreach ($sources as $k => $lbl): ?>
                    <option value="<?= htmlspecialchars($k) ?>" <?= $src === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($src !== 'general'): ?>
        <div class="form-group">
            <label for="plp-entity"><?= htmlspecialchars(priceTabKind($src)['entityLabel']) ?></label>
            <select id="plp-entity" name="entity_id" onchange="this.form.submit()">
                <option value="">— انتخاب کنید —</option>
                <?php foreach ($entityOptions as $e): ?>
                    <option value="<?= (int) $e['id'] ?>" <?= (int) $e['id'] === $entityId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($e['name']) ?><?= $e['sub'] !== '' ? ' — ' . htmlspecialchars($e['sub']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php if ($src === 'labs'): ?>
        <div class="form-group">
            <label for="plp-dir">جهت</label>
            <select id="plp-dir" name="dir" onchange="this.form.submit()">
                <option value="out" <?= $labDir === 'out' ? 'selected' : '' ?>>برون‌سپاری (ما می‌پردازیم)</option>
                <option value="in" <?= $labDir === 'in' ? 'selected' : '' ?>>کار از لابراتوار (ما می‌گیریم)</option>
            </select>
        </div>
        <?php endif; ?>

        <?php if ($src === 'branches'): ?>
        <div class="form-group">
            <label for="plp-provider">انجام‌دهنده (خدمت‌دهنده)</label>
            <select id="plp-provider" name="provider_branch" onchange="this.form.submit()">
                <option value="">— انتخاب کنید —</option>
                <?php foreach (getAllBranches() as $b): ?>
                    <option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === $providerBranch ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="form-group">
            <label for="plp-designer">فی طراحی از کدام طراح؟</label>
            <select id="plp-designer" name="designer_id" onchange="this.form.submit()">
                <option value="">پیش‌فرضِ خودِ خدمت</option>
                <?php foreach ($designerOptions as $d): ?>
                    <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $designerId ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="flex:1; min-width:240px;">
            <label for="plp-title">عنوان لیست</label>
            <input type="text" id="plp-title" name="doc_title" value="<?= htmlspecialchars($docTitle) ?>" placeholder="مثلاً: تعرفهٔ همکاری — آبان ۱۴۰۵">
        </div>

        <button type="submit" class="btn" style="background:#0F172A; color:#fff; font-weight:700;">نمایش خدمات</button>
    </div>

    <div class="plp-opts">
        <label>
            <input type="checkbox" name="show_design" value="1" <?= $showDesign ? 'checked' : '' ?> onchange="this.form.submit()">
            نرخ با طراحی (به‌جای نرخ پایه)
        </label>
        <label title="فی طراحی × این ضریب، سپس رند به بالا، سپس جمع با نرخ پایه">
            ضریبِ طراحی
            <input type="number" name="design_mult" min="0" step="0.05" value="<?= htmlspecialchars((string) $designMult) ?>" style="width:80px;">
        </label>
        <label>
            رند کردن
            <select name="design_round" style="width:auto;">
                <?php foreach ($roundOptions as $rv => $rl): ?>
                    <option value="<?= (int) $rv ?>" <?= $designRound === (int) $rv ? 'selected' : '' ?>><?= htmlspecialchars($rl) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><input type="checkbox" name="show_desc" value="1" <?= $showDesc ? 'checked' : '' ?>> ستون توضیحات</label>
        <label><input type="checkbox" name="show_short" value="1" <?= $showShort ? 'checked' : '' ?>> ستون نام اختصاری</label>
        <label><input type="checkbox" name="show_party" value="1" <?= $showParty ? 'checked' : '' ?>> نمایش نام طرف در سربرگ</label>
        <button type="submit" class="btn" style="background:#E5E7EB; color:#0F172A;">اعمال</button>
    </div>
</form>

<?php if ($src !== 'general' && $entityId <= 0): ?>
    <div class="plp-card" style="text-align:center; color:#64748b;">
        ابتدا <?= htmlspecialchars(priceTabKind($src)['entityLabel']) ?> را انتخاب کنید تا نرخ‌ها و جدولِ انتخابِ خدمات نمایش داده شود.
    </div>
    <?php panel_layout_end(); return; ?>
<?php endif; ?>

<form method="post" action="price_list_pdf.php" id="plp-form">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="action" value="generate">
    <input type="hidden" name="source" value="<?= htmlspecialchars($src) ?>">
    <input type="hidden" name="entity_id" value="<?= (int) $entityId ?>">
    <input type="hidden" name="dir" value="<?= htmlspecialchars($labDir) ?>">
    <input type="hidden" name="provider_branch" value="<?= (int) $providerBranch ?>">
    <input type="hidden" name="designer_id" value="<?= (int) $designerId ?>">
    <input type="hidden" name="doc_title" value="<?= htmlspecialchars($docTitle) ?>">
    <input type="hidden" name="show_design" value="<?= $showDesign ? '1' : '0' ?>">
    <input type="hidden" name="design_mult" value="<?= htmlspecialchars((string) $designMult) ?>">
    <input type="hidden" name="design_round" value="<?= (int) $designRound ?>">
    <input type="hidden" name="show_desc" value="<?= $showDesc ? '1' : '0' ?>">
    <input type="hidden" name="show_short" value="<?= $showShort ? '1' : '0' ?>">
    <input type="hidden" name="show_party" value="<?= $showParty ? '1' : '0' ?>">

    <div class="plp-card">
        <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-bottom:12px;">
            <div class="form-group" style="flex:1; min-width:280px; margin:0;">
                <label for="plp-note">توضیحاتِ پایینِ لیست (اختیاری)</label>
                <textarea id="plp-note" name="doc_note" rows="2" style="width:100%;"><?= htmlspecialchars($docNote) ?></textarea>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
                <button type="button" class="btn" style="background:#E5E7EB; color:#0F172A;" onclick="plpSelectAll(true)">انتخاب همه</button>
                <button type="button" class="btn" style="background:#E5E7EB; color:#0F172A;" onclick="plpSelectAll(false)">حذف انتخاب‌ها</button>
            </div>
        </div>

        <div style="max-height:62vh; overflow:auto; border:1px solid #eef2f7; border-radius:10px;">
            <table class="plp-table" data-mult="<?= htmlspecialchars((string) $designMult) ?>" data-round="<?= (int) $designRound ?>" data-withdesign="<?= $showDesign ? '1' : '0' ?>">
                <thead>
                <tr>
                    <th style="width:34px;">✓</th>
                    <th>خدمت</th>
                    <?php if ($showShort): ?><th style="width:70px;">اختصاری</th><?php endif; ?>
                    <th style="width:110px;">دسته</th>
                    <th style="width:140px;">نرخ پایه</th>
                    <?php if ($showDesign): ?>
                        <th style="width:130px;">فی طراحی</th>
                        <th style="width:150px;">نرخ با طراحی</th>
                    <?php endif; ?>
                    <?php if ($showDesc): ?><th>توضیحات (قابل ویرایش)</th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($services as $svc):
                    $sid = (int) $svc['id'];
                    $unitDefault   = isset($unitMap[$sid]) ? (float) $unitMap[$sid] : (float) ($svc['price'] ?? 0);
                    $designDefault = plDesignFeeFor($designerId, $svc);
                    $hasCustom     = isset($unitMap[$sid]);
                    $cat           = normalizeServiceCategory($svc['category'] ?? '');
                    $preselect     = plDefaultSelected($src, (string) ($svc['category'] ?? ''), $hasCustom);
                    $withDefault   = plWithDesign($unitDefault, $designDefault, $designMult, $designRound);
                    ?>
                    <tr data-service-id="<?= $sid ?>" class="<?= $preselect ? '' : 'is-off' ?>">
                        <td style="text-align:center;">
                            <input type="checkbox" class="plp-on" name="rows[<?= $sid ?>][on]" value="1" <?= $preselect ? 'checked' : '' ?>>
                        </td>
                        <td><?= htmlspecialchars($svc['title']) ?></td>
                        <?php if ($showShort): ?>
                            <td class="plp-short"><?= htmlspecialchars((string) ($svc['short_name'] ?? '')) ?: '—' ?></td>
                        <?php endif; ?>
                        <td style="color:#64748b; font-size:.82rem; white-space:nowrap;"><?= htmlspecialchars($cat) ?></td>
                        <td>
                            <input type="number" step="1" min="0" class="plp-base" name="rows[<?= $sid ?>][price]" value="<?= (int) round($unitDefault) ?>">
                            <div style="font-size:.72rem; margin-top:2px; color:<?= $hasCustom ? '#b45309' : '#94a3b8' ?>;">
                                <?= $hasCustom ? 'نرخ اختصاصی' : 'نرخ عمومی' ?>
                            </div>
                        </td>
                        <?php if ($showDesign): ?>
                            <td><input type="number" step="1" min="0" class="plp-design" name="rows[<?= $sid ?>][design]" value="<?= (int) round($designDefault) ?>"></td>
                            <td><span class="plp-with"><?= number_format($withDefault) ?></span></td>
                        <?php else: ?>
                            <td style="display:none;"><input type="hidden" class="plp-design" name="rows[<?= $sid ?>][design]" value="<?= (int) round($designDefault) ?>"></td>
                        <?php endif; ?>
                        <?php if ($showDesc): ?>
                            <td><input type="text" name="rows[<?= $sid ?>][desc]" value="<?= htmlspecialchars((string) ($svc['description'] ?? '')) ?>"></td>
                        <?php else: ?>
                            <td style="display:none;"><input type="hidden" name="rows[<?= $sid ?>][desc]" value="<?= htmlspecialchars((string) ($svc['description'] ?? '')) ?>"></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="plp-bar">
        <span class="plp-hint">
            تغییرِ نرخ/توضیحات فقط روی همین فایل PDF اثر دارد و نرخ‌های ذخیره‌شده را تغییر نمی‌دهد.
            <?php if ($showDesign): ?>
                فرمول: نرخ پایه + <?= $designMult == 1 ? 'فی طراحی' : 'فی طراحی × ' . rtrim(rtrim(number_format($designMult, 2, '.', ''), '0'), '.') ?>
                <?= $designRound > 0 ? ' (رند به بالا به مضربِ ' . number_format($designRound) . ')' : '' ?>
            <?php endif; ?>
        </span>
        <button type="submit" class="btn" style="background:#16a34a; color:#fff; font-weight:700;">📄 ساخت PDF</button>
    </div>
</form>

<script>
function plpSyncRow(cb){ cb.closest('tr').classList.toggle('is-off', !cb.checked); }
function plpSelectAll(on){
    document.querySelectorAll('#plp-form .plp-on').forEach(function(cb){ cb.checked = on; plpSyncRow(cb); });
}
document.querySelectorAll('#plp-form .plp-on').forEach(function(cb){
    cb.addEventListener('change', function(){ plpSyncRow(cb); });
});

// پیش‌نمایشِ زندهٔ «نرخ با طراحی»
(function(){
    var t = document.querySelector('#plp-form .plp-table');
    if (!t || t.getAttribute('data-withdesign') !== '1') return;
    var mult = parseFloat(t.getAttribute('data-mult') || '1') || 0;
    var step = parseInt(t.getAttribute('data-round') || '0', 10) || 0;
    function recalc(tr){
        var base = parseFloat(tr.querySelector('.plp-base').value || '0') || 0;
        var desEl = tr.querySelector('.plp-design');
        var des = parseFloat((desEl && desEl.value) || '0') || 0;
        var adj = des * mult;
        if (step > 0 && adj > 0) adj = Math.ceil(adj / step) * step;
        var out = tr.querySelector('.plp-with');
        if (out) out.textContent = Math.round(base + adj).toLocaleString('en-US');
    }
    t.querySelectorAll('tr[data-service-id]').forEach(function(tr){
        tr.querySelectorAll('.plp-base, .plp-design').forEach(function(el){
            el.addEventListener('input', function(){ recalc(tr); });
        });
    });
})();
</script>
<?php
panel_layout_end();
