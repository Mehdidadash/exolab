<?php
/**
 * panel/print_labels.php
 * Generates an A4 PDF sheet of 90mm x 10mm case labels, two columns per row.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/QrGenerator.php';
require_login();

use Mpdf\Mpdf;

// ─── Gather input ──────────────────────────────────────────────
$caseIds = isset($_POST['case_ids']) && is_array($_POST['case_ids'])
    ? array_map('intval', $_POST['case_ids'])
    : [];

if (empty($caseIds)) {
    die('هیچ کیسی انتخاب نشده است.');
}

// ─── Fetch cases ────────────────────────────────────────────────
$placeholders = implode(',', array_fill(0, count($caseIds), '?'));
$stmt = db()->prepare("
    SELECT c.*, u.full_name AS doctor_name, p.title AS service_title, p.short_name AS service_short,
           lab.full_name AS lab_name, lab.role AS lab_role
    FROM cases c
    LEFT JOIN users       u ON c.doctor_id = u.id
    LEFT JOIN users       lab ON c.lab_id = lab.id
    LEFT JOIN site_prices p ON c.service_id = p.id
    WHERE c.id IN ({$placeholders})
    ORDER BY c.id
");
$stmt->execute($caseIds);
$cases = $stmt->fetchAll();

if (empty($cases)) {
    die('کیسی یافت نشد.');
}

// ─── Abbreviation helpers ───────────────────────────────────────
function formatDoctorLabel(string $name): string
{
    $name = trim($name);
    if ($name === '') return 'دکتر';

    $name = preg_replace('/^دکتر\s+/u', '', $name) ?? $name;
    $name = preg_replace('/\s+/u', ' ', $name);
    $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($parts)) return 'دکتر';

    $display = implode(' ', array_slice($parts, 0, 3));
    return 'دکتر ' . $display;
}

function formatDoctorLine(array $case): string
{
    $doctor = formatDoctorLabel((string) ($case['doctor_name'] ?? ''));
    $labName = trim((string) ($case['lab_name'] ?? ''));
    $labRole = (string) ($case['lab_role'] ?? '');

    // Append the lab name only when the lab is a customer lab (لابراتوار مشتری)
    if ($labName !== '' && $labRole === 'customer_lab') {
        $labName = preg_replace('/^دکتر\s+/u', '', $labName) ?? $labName;
        $labName = preg_replace('/\s+/u', ' ', $labName);
        return clipLabel($doctor . ' / ' . $labName, 30);
    }

    return clipLabel($doctor, 30);
}

function abbrPatient(string $name): string
{
    $name = trim($name);
    if ($name === '') return '';
    $prefix = '';
    foreach (['خانوم', 'خانم', 'آقای', 'آقا'] as $pr) {
        if (mb_strpos($name, $pr) === 0) {
            $prefix = mb_substr($pr, 0, 1, 'UTF-8') . '. ';
            $name = trim(mb_substr($name, mb_strlen($pr, 'UTF-8')));
            break;
        }
    }
    if ($name === '') return trim($prefix);
    $parts = preg_split('/\s+/u', $name, 2);
    return $prefix . mb_substr($parts[0], 0, 1, 'UTF-8') . '. ' . ($parts[1] ?? '');
}

function abbrService(string $title): string
{
    static $map = [
        'روکش زیرکونیا مولتی لیر'     => 'مولتی',
        'روکش زیرکونیای پایه ایمپلنت' => 'مولتی ایمپلنت',
        'لمینیت ای-مکس'               => 'لمینیت',
        'مریلند بریج زیرکونیا'        => 'مریلند زیر',
        'کاستوم اباتمنت کره ای'       => 'ابات.کره',
        'کاستوم اباتمنت اروپایی'      => 'ابت.اروپا',
        'روکش موقت PMMA'              => 'PMMA',
        'نایت گارد سخت'               => 'نایت‌گارد',
        'نایت گارد نرم'               => 'نایت‌گارد نرم',
        'تری بلیچینگ'                 => 'بلیچینگ',
    ];
    return $map[$title] ?? mb_substr($title, 0, 8, 'UTF-8');
}

/**
 * کوتاه‌کردن متن برچسب: اگر از حد مجاز طولانی‌تر بود، ادامهٔ کاراکترها حذف می‌شود
 * تا متن از سلول بیرون نزند و چیدمان برچسب به‌هم نخورد. (بدون افزودن «...»)
 */
function clipLabel(?string $text, int $maxChars): string
{
    $text = trim((string) $text);
    if ($text === '' || $maxChars < 1) return $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    if (mb_strlen($text, 'UTF-8') <= $maxChars) return $text;
    return rtrim(mb_substr($text, 0, $maxChars, 'UTF-8'));
}

/** نام خدمت روی برچسب: اول نام اختصاری تعریف‌شده در صفحهٔ قیمت‌ها، بعد نقشهٔ داخلی */
function labelServiceName(array $case): string
{
    $short = trim((string) ($case['service_short'] ?? ''));
    if ($short !== '') return $short;
    return abbrService((string) ($case['service_title'] ?? ''));
}

/**
 * جا دادن متن در سلول برچسب: عرض متن با موتور mPDF اندازه‌گیری می‌شود
 * (خروجی GetStringWidth میلی‌متر است) و اول اندازهٔ فونت کمی کوچک می‌شود و
 * در نهایت اگر باز هم جا نشد، کاراکترهای آخر حذف می‌شوند. این‌طور ابعاد
 * برچسب (۹۰×۱۰ میلی‌متر) هرگز با متنِ طولانی به‌هم نمی‌ریزد.
 *
 * @return array{size: float, text: string}
 */
function fitCellText(Mpdf $mpdf, string $text, float $maxWidthMm, array $sizes = [8, 7.5, 7, 6.5, 6, 5.5], string $family = 'vazirblack', string $style = ''): array
{
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
    $first = (float) ($sizes[0] ?? 8);
    if ($text === '' || $maxWidthMm <= 0) return ['size' => $first, 'text' => ''];

    foreach ($sizes as $s) {
        $mpdf->SetFont($family, $style, (float) $s);
        if ($mpdf->GetStringWidth($text) <= $maxWidthMm) {
            return ['size' => (float) $s, 'text' => $text];
        }
    }

    // حتی با کوچک‌ترین فونت جا نشد → کاراکتر به کاراکتر کوتاه کن
    $size = (float) end($sizes);
    $mpdf->SetFont($family, $style, $size);
    $out = '';
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        $try = $out . $ch;
        if ($mpdf->GetStringWidth($try) > $maxWidthMm) break;
        $out = $try;
    }
    return ['size' => $size, 'text' => rtrim($out)];
}

/**
 * متن سلول خدمت: «نام اختصاری» (پُررنگ) و بعد از آن توضیحات کیس،
 * تا جایی که عرض سلول اجازه بدهد (بقیهٔ توضیحات حذف می‌شود).
 */
function labelServiceCell(Mpdf $mpdf, string $short, string $description, float $maxWidthMm, float $fontSize = 6.5): string
{
    $short = trim($short);
    $html  = '';
    if ($short !== '') {
        $shortFit = fitCellText($mpdf, $short, $maxWidthMm, [$fontSize, 6, 5.5], 'vazirblack', 'B');
        if ($shortFit['text'] !== '') {
            $html = '<b>' . htmlspecialchars($shortFit['text']) . '</b>';
        }
    }

    $desc = clipLabel($description, 120);
    if ($desc === '') return $html;

    // عرض باقی‌مانده = عرض سلول − نام اختصاری − فاصله
    $mpdf->SetFont('vazirblack', 'B', $fontSize);
    $used  = $html === '' ? 0.0 : $mpdf->GetStringWidth(strip_tags($html));
    $mpdf->SetFont('vazir', '', $fontSize);
    $space = $mpdf->GetStringWidth(' ') * 1.5;
    $remaining = $maxWidthMm - $used - $space;
    if ($remaining < 3) return $html;

    $descFit = fitCellText($mpdf, $desc, $remaining, [$fontSize, 6, 5.5], 'vazir', '');
    if ($descFit['text'] === '') return $html;
    return $html . '<span style="font-family:vazir; color:#555;"> ' . htmlspecialchars($descFit['text']) . '</span>';
}

function abbrLocation(?string $locationType, ?string $teeth): string
{
    if ($locationType === 'teeth' && !empty($teeth)) return $teeth;
    static $map = ['upper' => 'فک بالا', 'lower' => 'فک پایین', 'both' => 'فک بالا/پایین'];
    return $map[$locationType] ?? '';
}

// ─── Single label: 2 rows × 5 cols (ID vertical, QR rightmost) ─
// همهٔ متن‌ها با اندازه‌گیریِ واقعی mPDF داخل سلول جا داده می‌شوند تا ابعاد
// برچسب (۹۰×۱۰ میلی‌متر) با متن‌های بلند به‌هم نریزد.
function buildLabelHtml(Mpdf $mpdf, array $case, string $baseUrl): string
{
    // عرض متنِ قابل استفاده در هر سلول (میلی‌متر) = عرض ستون − حاشیهٔ داخلی − خطوط
    $inW1 = 29.34 - 1.2;
    $inW2 = 11.7 - 1.2;
    $inW3 = 34.02 - 1.2;

    $caseId = (int) $case['id'];
    $url    = $baseUrl . '/panel/view_case.php?id=' . $caseId;

    $doctor = fitCellText($mpdf, formatDoctorLine($case), $inW1, [8, 7.5, 7, 6.5, 6, 5.5], 'vazirblack', 'B');

    // نام بیمار کوتاه می‌شود ولی شمارهٔ قبض (کلید پیدا کردن کیس) همیشه کامل می‌ماند:
    // مثلا «زهرا زنده دل/01020»
    $receiptRaw  = clipLabel((string) ($case['receipt_number'] ?? ''), 8);
    $receiptTail = $receiptRaw === '' ? '' : '/' . $receiptRaw;
    $mpdf->SetFont('vazirblack', '', 8);
    $receiptW    = $mpdf->GetStringWidth($receiptTail);
    $patient     = fitCellText($mpdf, (string) ($case['patient_name'] ?? ''), max(4, $inW1 - $receiptW), [8, 7.5, 7, 6.5, 6, 5.5], 'vazirblack', '');
    $patientHtml = htmlspecialchars($patient['text'] . $receiptTail);

    $shade = fitCellText($mpdf, (string) ($case['shade'] ?? ''), $inW2, [8, 7, 6, 5.5], 'vazirblack', '');
    $date  = fitCellText($mpdf, toJalaliDateFormatted((string) ($case['received_date'] ?? '')), $inW2, [8, 7, 6.5, 6, 5.5], 'vazirblack', '');

    // خدمت: نام اختصاری + توضیحات کیس تا جایی که در سلول جا شود
    $serviceHtml = labelServiceCell($mpdf, labelServiceName($case), (string) ($case['description'] ?? ''), $inW3, 6.5);

    // محل/دندان: شمارهٔ دندان می‌تواند خیلی بلند باشد؛ اول فونت را کمی کوچک می‌کنیم
    // (تا ۵pt که همان اندازهٔ ستون شمارهٔ کیس است) و در آخر متن را به عرض سلول می‌بریم.
    $location = fitCellText($mpdf, clipLabel(abbrLocation($case['location_type'] ?? null, $case['teeth'] ?? null), 80), $inW3, [8, 7.5, 7, 6.5, 6, 5.5, 5], 'vazirblack', '');

    $qr = QrGenerator::dataUri($url);

    // Vertical case ID (one digit per line)
    $idVertical = '';
    foreach (str_split((string) $caseId) as $ch) {
        $idVertical .= htmlspecialchars($ch) . '<br/>';
    }

    $w1 = '29.34mm'; $w2 = '11.7mm'; $w3 = '34.02mm'; $w4 = '3.74mm'; $w5 = '11.2mm';
    $bs = 'border:0.3mm solid #231f20;';
    $fs = 'font-family:vazirblack;';
    // ارتفاع برچسب ثابت بمانند: line-height ثابت + padding معین.
    // mPDF ارتفاع خطِ کادر را هم به ردیف اضافه می‌کند، پس عددها طوری تنظیم شده‌اند
    // که «گام» هر ردیف برچسب دقیقاً ۱۰ میلی‌متر شود (۹٫۷۱ کادر + ۰٫۲۹ خطوط ≈ ۱۰).
    $lh = 'line-height:3.26mm;';
    $pd = 'padding:0.65mm 0.3mm;';
    $ct = $lh . $pd . 'vertical-align:middle; white-space:nowrap;';
    // ستون شمارهٔ کیس (عمودی، ۵pt) و کادر QR ارتفاع را تعیین نکنند
    $idStyle = 'font-size:5pt; line-height:1.7mm; padding:0.5mm 0.2mm; text-align:center; font-weight:bold; vertical-align:middle;';

    return '<table style="width:90mm; table-layout:fixed; border-collapse:collapse;' . $fs . '">'
         . '<tr>'
         . '<td nowrap="nowrap" style="width:' . $w1 . ';' . $bs . $ct . 'font-size:' . $doctor['size'] . 'pt; font-weight:bold;">' . htmlspecialchars($doctor['text']) . '</td>'
         . '<td nowrap="nowrap" style="width:' . $w2 . ';' . $bs . $ct . 'font-size:' . $shade['size'] . 'pt; color:#555;">' . htmlspecialchars($shade['text']) . '</td>'
         . '<td nowrap="nowrap" style="width:' . $w3 . ';' . $bs . $ct . 'font-size:6.5pt;">' . $serviceHtml . '</td>'
         . '<td style="width:' . $w4 . ';' . $bs . $idStyle . '" rowspan="2">' . $idVertical . '</td>'
         . '<td style="width:' . $w5 . ';' . $bs . 'text-align:center; vertical-align:middle; padding:0;" rowspan="2">'
         . '<img src="' . $qr . '" style="width:9.4mm; height:9.4mm;" alt=""/>'
         . '</td></tr>'
         . '<tr>'
         . '<td nowrap="nowrap" style="width:' . $w1 . ';' . $bs . $ct . 'font-size:' . $patient['size'] . 'pt;">' . $patientHtml . '</td>'
         . '<td nowrap="nowrap" style="width:' . $w2 . ';' . $bs . $ct . 'font-size:' . $date['size'] . 'pt; color:#555;">' . htmlspecialchars($date['text']) . '</td>'
         . '<td nowrap="nowrap" style="width:' . $w3 . ';' . $bs . $ct . 'font-size:' . $location['size'] . 'pt; color:#555;">' . htmlspecialchars($location['text']) . '</td>'
         . '</tr></table>';
}

// ─── PDF engine ─────────────────────────────────
// قبل از ساخت برچسب‌ها ساخته می‌شود تا بتوانیم عرض متن را با فونتِ خودِ mPDF اندازه بگیریم
// و متن‌ها را دقیقاً به اندازهٔ سلول جا بدهیم (جلوگیری از به‌هم‌ریختن ابعاد برچسب).
$mpdf = new Mpdf([
    'mode'          => 'utf-8',
    'format'        => 'A4',
    'margin_left'   => 10,
    'margin_right'  => 10,
    'margin_top'    => 10,
    'margin_bottom' => 10,
    'margin_header' => 0,
    'margin_footer' => 0,
    'fontDir'       => [__DIR__ . '/../assets/fonts'],
    'fontdata'      => [
        'vazir' => [
            'R' => 'Vazir.ttf',
            'B' => 'Vazir-Bold.ttf',
            'useOTL' => 0xFF,
        ],
        'vazirblack' => [
            'R' => 'Vazir-Black.ttf',
            'B' => 'Vazir-Black.ttf',
            'useOTL' => 0xFF,
        ],
    ],
    'default_font'     => 'vazir',
    'autoScriptToLang' => true,
    'autoLangToFont'   => true,
]);
$mpdf->SetDirectionality('rtl');

// ─── Build the two-column grid (single outer table) ──────
// آدرس پایه برای لینک QR: لوکال http://localhost/exolab و روی هاست https://exolab.ir.
// (قبلاً «/exolab» ثابت اضافه می‌شد و روی هاست لینک QR اشتباه درمی‌آمد.)
$baseUrl = appBaseUrl();

$rowsHtml = '';
foreach (array_chunk($cases, 2) as $pair) {
    $left  = '<td class="label-cell">' . buildLabelHtml($mpdf, $pair[0], $baseUrl) . '</td>';
    $right = isset($pair[1])
        ? '<td class="label-cell">' . buildLabelHtml($mpdf, $pair[1], $baseUrl) . '</td>'
        : '<td class="label-cell"></td>';
    $rowsHtml .= '<tr class="label-row">' . $left . '<td class="gap-cell"></td>' . $right . '</tr>';
}

// ─── HTML / CSS ──────────────────────────────────────────────────
// فاصلهٔ عمودی بین ردیف‌ها صفر است تا گامِ برچسب‌ها دقیقاً ۱۰ میلی‌متر بماند
// (در برگه‌های برچسب آماده، هر ردیف باید دقیقاً ۱۰mm پایین‌تر از ردیف قبلی باشد).
$css = 'body{direction:rtl;font-family:vazir;font-size:6pt;}'
     . 'table.labels-grid{width:180.3mm;margin:0 auto;border-collapse:collapse;table-layout:fixed;}'
     . '.label-cell{width:90mm;vertical-align:top;padding:0;}'
     . '.gap-cell{width:0.3mm;padding:0;}'
     . '.label-row{page-break-inside:avoid;}';

$html = '<html dir="rtl"><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>'
      . '<table class="labels-grid">' . $rowsHtml . '</table>'
      . '</body></html>';

// ─── PDF generation ──────────────────────────────────────────────
$mpdf->WriteHTML($html);

// Mark labels as printed (used for the green highlight in the case list)
$labelCaseIds = array_column($cases, 'id');
if (!empty($labelCaseIds)) {
    $ph = implode(',', array_fill(0, count($labelCaseIds), '?'));
    $upd = db()->prepare("UPDATE cases SET label_printed_at = NOW() WHERE id IN ($ph)");
    $upd->execute($labelCaseIds);
}

$filename = 'labels_' . implode('_', array_column($cases, 'id')) . '.pdf';
$mpdf->Output($filename, 'I');