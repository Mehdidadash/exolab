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
    SELECT c.*, u.full_name AS doctor_name, p.title AS service_title
    FROM cases c
    LEFT JOIN users       u ON c.doctor_id = u.id
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
function abbrDoctor(string $name): string
{
    $name = trim($name);
    if ($name === '') return 'دکتر';
    $name = preg_replace('/^دکتر\s+/u', '', $name) ?? $name;
    $parts = preg_split('/\s+/u', $name, 2);
    return 'دکتر ' . mb_substr($parts[0], 0, 1, 'UTF-8') . '. ' . ($parts[1] ?? '');
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

function abbrLocation(?string $locationType, ?string $teeth): string
{
    if ($locationType === 'teeth' && !empty($teeth)) return $teeth;
    static $map = ['upper' => 'فک بالا', 'lower' => 'فک پایین', 'both' => 'فک بالا/پایین'];
    return $map[$locationType] ?? '';
}

// ─── Single label: 2 rows × 5 cols (ID vertical, QR rightmost) ─
function buildLabelHtml(array $case, string $baseUrl): string
{
    $caseId   = (int) $case['id'];
    $url      = $baseUrl . '/panel/view_case.php?id=' . $caseId;
    $doctor   = htmlspecialchars(abbrDoctor((string) ($case['doctor_name'] ?? '')));
    $patient  = htmlspecialchars((string) ($case['patient_name'] ?? ''));
    $service  = (string) ($case['service_title'] ?? '');
    $shade    = (string) ($case['shade'] ?? '');
    $location = abbrLocation($case['location_type'] ?? null, $case['teeth'] ?? null);
    $date     = toJalaliDateFormatted((string) ($case['received_date'] ?? ''));
    $qr       = QrGenerator::dataUri($url);

    // Vertical case ID (one digit per line)
    $idVertical = '';
    foreach (str_split((string) $caseId) as $ch) {
        $idVertical .= htmlspecialchars($ch) . '<br/>';
    }

    $w1 = '29.34mm'; $w2 = '11.7mm'; $w3 = '34.02mm'; $w4 = '3.74mm'; $w5 = '11.2mm';
    $bs = 'border:0.3mm solid #231f20;';
    $fs = 'font-family:vazirblack;';
    // mPDF respects padding on nested <td>.  We use padding to reach the
// target row height. 8pt Persian text with OTL ≈ 3.5mm,
// so padding each side = (desiredRow - textHeight) / 2
$pt = '0.7mm';
$ct = 'font-size:8pt; padding:' . $pt . ' 0.3mm; vertical-align:middle;';

    return '<table style="width:90mm; border-collapse:collapse;' . $fs . '">'
         . '<tr>'
         . '<td style="width:' . $w1 . ';' . $bs . $ct . 'font-weight:bold;">' . $doctor . '</td>'
         . '<td style="width:' . $w2 . ';' . $bs . $ct . 'color:#555;">' . htmlspecialchars($shade) . '</td>'
         . '<td style="width:' . $w3 . ';' . $bs . $ct . 'font-size:6.5pt; font-weight:bold;">' . htmlspecialchars($service) . '</td>'
         . '<td style="width:' . $w4 . ';' . $bs . $ct . 'text-align:center; font-size:5pt; font-weight:bold;" rowspan="2">' . $idVertical . '</td>'
         . '<td style="width:' . $w5 . ';' . $bs . 'text-align:center; vertical-align:middle; padding:0;" rowspan="2">'
         . '<img src="' . $qr . '" style="width:10.5mm; height:10.5mm;" alt=""/>'
         . '</td></tr>'
         . '<tr>'
         . '<td style="width:' . $w1 . ';' . $bs . $ct . '">' . $patient . '</td>'
         . '<td style="width:' . $w2 . ';' . $bs . $ct . 'color:#555;">' . $date . '</td>'
         . '<td style="width:' . $w3 . ';' . $bs . $ct . 'color:#555;">' . htmlspecialchars($location) . '</td>'
         . '</tr></table>';
}

// ─── Build the two-column grid (single outer table) ─────────────
$scheme  = $_SERVER['REQUEST_SCHEME'] ?? 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = "{$scheme}://{$host}/exolab";

$rowsHtml = '';
foreach (array_chunk($cases, 2) as $pair) {
    $left  = '<td class="label-cell">' . buildLabelHtml($pair[0], $baseUrl) . '</td>';
    $right = isset($pair[1])
        ? '<td class="label-cell">' . buildLabelHtml($pair[1], $baseUrl) . '</td>'
        : '<td class="label-cell"></td>';
    $rowsHtml .= '<tr class="label-row">' . $left . '<td class="gap-cell"></td>' . $right . '</tr>';
}

// ─── HTML / CSS ──────────────────────────────────────────────────
$css = 'body{direction:rtl;font-family:vazir;font-size:6pt;}'
     . 'table.labels-grid{width:180.3mm;margin:0 auto;border-collapse:collapse;}'
     . '.label-cell{width:90mm;vertical-align:top;padding:0 0 0.3mm 0;}'
     . '.gap-cell{width:0.3mm;padding:0 0 0.3mm 0;}'
     . '.label-row{page-break-inside:avoid;}';

$html = '<html dir="rtl"><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>'
      . '<table class="labels-grid">' . $rowsHtml . '</table>'
      . '</body></html>';

// ─── PDF generation ──────────────────────────────────────────────
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
$mpdf->WriteHTML($html);

$filename = 'labels_' . implode('_', array_column($cases, 'id')) . '.pdf';
$mpdf->Output($filename, 'I');