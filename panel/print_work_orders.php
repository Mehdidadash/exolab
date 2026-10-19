<?php
/**
 * panel/print_work_orders.php
 *
 * «دستور کار» پرینت‌شوندهٔ هر کیس — برگهٔ A4.
 *
 * مکانیزم دقیقاً مثل print_labels.php است (POST با case_ids[] و خروجی PDF با mPDF)،
 * با این تفاوت‌ها:
 *   • هر کیس «یک ردیفِ کاملِ یک A4» را می‌گیرد (نه برچسب ۹۰×۱۰).
 *   • متن‌ها بزرگ‌تر و خوانا هستند و «توضیحات کیس» هم کامل چاپ می‌شود.
 *   • برای کیس‌های کاستوم اباتمنت (کره‌ای/اروپایی): نوع اسکن‌بادی + نوع اتصال.
 *   • اگر کیس، کیسِ اصلی داشته باشد: شمارهٔ کیس اصلی هم ذکر می‌شود.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

require_login();

use Mpdf\Mpdf;

// ─── مجوز ───────────────────────────────────────────────────────
// مدیران (admin/branch_admin)، کارمندانِ شعبه (staff/secretary/technician) و طراح‌ها.
// مثل پرینت برچسب، بدون این بررسی هر کاربرِ واردشده می‌توانست با ارسال شناسهٔ دلخواهِ
// کیس، دستور کارِ کیس‌های دیگران را بگیرد.
$woUser = current_user();
$woAllowed = is_admin()
    || is_designer_user($woUser)
    || has_permission('batch_print_labels')
    || has_permission('export_csv')
    || has_permission('edit_cases')
    || in_array($woUser['role'] ?? '', ['staff', 'secretary', 'technician'], true);

if (!$woAllowed) {
    http_response_code(403);
    die('دسترسی غیرمجاز — شما مجوز پرینت دستور کار را ندارید.');
}

// ─── دریافت ورودی ───────────────────────────────────────────────
$caseIds = isset($_POST['case_ids']) && is_array($_POST['case_ids'])
    ? array_values(array_unique(array_map('intval', $_POST['case_ids'])))
    : [];
$caseIds = array_values(array_filter($caseIds, static fn($v) => $v > 0));

if (empty($caseIds)) {
    die('هیچ کیسی انتخاب نشده است.');
}

// ─── واکشی کیس‌ها ───────────────────────────────────────────────
// include_notes=0 → یادداشت پزشک چاپ نشود (پیش‌فرض: چاپ شود؟ خیر — فقط فیلدهای کیس).
// دستور کار شامل «توضیحات کیس» است، نه یادداشتِ خصوصیِ پزشک.
$placeholders = implode(',', array_fill(0, count($caseIds), '?'));
$stmt = db()->prepare("
    SELECT c.*,
           u.full_name  AS doctor_name,
           docu.full_name AS designer_name,
           p.title      AS service_title,
           p.short_name AS service_short,
           cs.name      AS status_name,
           lab.full_name AS lab_name,
           br.name      AS branch_name,
           par.patient_name    AS parent_patient,
           par.receipt_number  AS parent_receipt,
           par.service_id      AS parent_service_id,
           parp.title          AS parent_service_title,
           parp.short_name     AS parent_service_short
    FROM cases c
    LEFT JOIN users        u    ON c.doctor_id  = u.id
    LEFT JOIN users        docu ON c.designer_id = docu.id
    LEFT JOIN site_prices  p    ON c.service_id = p.id
    LEFT JOIN case_statuses cs  ON c.status_id  = cs.id
    LEFT JOIN users        lab  ON c.lab_id     = lab.id
    LEFT JOIN branches     br   ON c.branch_id  = br.id
    LEFT JOIN cases        par  ON c.parent_id  = par.id
    LEFT JOIN site_prices  parp ON par.service_id = parp.id
    WHERE c.id IN ({$placeholders})
    ORDER BY c.id
");
$stmt->execute($caseIds);
$cases = $stmt->fetchAll();

// فقط کیس‌هایی که کاربر اجازهٔ دیدنشان را دارد (شعبه/رابطه/مجوز)
$cases = array_values(array_filter($cases, static function ($c) use ($woUser) {
    return userCanViewCase((int) ($c['id'] ?? 0), $woUser, $c);
}));

if (empty($cases)) {
    die('کیسی یافت نشد.');
}

// نام انواع اسکن‌بادی (id → نام) برای جلوگیری از کوئری در حلقه
$scanBodyNames = getScanBodyTypeNames();

/**
 * آیا این کیس «کاستوم اباتمنت» است (کره‌ای/اروپایی)؟
 * تشخیص دو لایه دارد:
 *   ۱) تیکِ requires_scan_body روی خدمت (خدماتِ اباتمنت/فیکسچر این تیک را دارند).
 *   ۲) تطبیقِ عنوان: «کاستوم» + «اباتمنت».
 * اگر کیس اصلیِ اباتمنتی داشته باشد هم اطلاعات اتصال/اسکن‌بادی نمایش داده می‌شود.
 */
function woIsCustomAbutment(array $case): bool
{
    $title = (string) ($case['service_title'] ?? '');
    if ($title !== '' && function_exists('serviceTitleIsCustomAbutment') && serviceTitleIsCustomAbutment($title)) {
        return true;
    }
    return (bool) ($case['requires_scan_body'] ?? 0);
}

/** برچسبِ محل/دندان به‌صورت خوانا (فک بالا / فک پایین / شمارهٔ دندان‌ها). */
function woLocationLabel(array $case): string
{
    $loc = (string) ($case['location_type'] ?? '');
    $teeth = trim((string) ($case['teeth'] ?? ''));
    if ($loc === 'teeth' && $teeth !== '') {
        // (11_12,21_22) → ۱۱,۱۲,۲۱,۲۲  (گروه‌ها با «/» و دندان‌ها با «،»)
        $parts = [];
        foreach (explode(',', $teeth) as $group) {
            $group = trim($group);
            if ($group === '') continue;
            $teethList = array_filter(array_map('trim', explode('_', $group)), static fn($t) => $t !== '');
            if (!empty($teethList)) {
                $parts[] = implode(',', array_map(static fn($t) => toPersianDigits($t), $teethList));
            }
        }
        $txt = implode(' / ', $parts);
        if ($txt === '') return '—';
        // جا برای شمارهٔ کامل دندان‌ها باز است، ولی اگر باز هم از سقفِ کاراکتر
        // بیشتر شد، ادامه‌اش با «...» خلاصه می‌شود تا ابعادِ کارت به‌هم نریزد.
        if (mb_strlen($txt, 'UTF-8') > 52) {
            return rtrim(mb_substr($txt, 0, 49, 'UTF-8'), ' /،,') . '...';
        }
        return $txt;
    }
    static $map = ['upper' => 'فک بالا', 'lower' => 'فک پایین', 'both' => 'فک بالا و پایین'];
    return $map[$loc] ?? ($teeth !== '' ? toPersianDigits($teeth) : '—');
}

/**
 * آماده‌سازی «توضیحات کیس» برای چاپِ فشرده:
 *   • اینترها (خطوطِ خالی/چندخطی) به «||» تبدیل می‌شوند تا کارت چند برابر
 *     بلند نشود و ابعاد به‌هم نریزد.
 *   • خطوطِ خالیِ پشت‌سرهم جمع می‌شوند.
 *   • اگر از سقفِ کاراکتر بیشتر شد، ادامه‌اش با «...» خلاصه می‌شود.
 * متن به‌صورت «یک یا دو خط» چاپ می‌شود (کوتاه: یک خط، بلند: دو خط).
 *
 * @return array{text:string, lines:int}
 */
function woPrepareDescription(string $desc, int $maxChars = 150): array
{
    // نرمال‌سازیِ خطوطِ جدید (CRLF/CR → LF)
    $desc = str_replace(["\r\n", "\r"], "\n", $desc);
    // هر «اینتر» (خطِ جدید) → «||» و فاصله‌های اضافی حذف
    $flat = trim((string) preg_replace('/[ \t]*\n+[ \t]*/u', ' || ', $desc));
    $flat = trim((string) preg_replace('/\s{2,}/u', ' ', $flat));
    $flat = trim($flat, "| \t");

    if ($flat === '') return ['text' => '', 'lines' => 0];

    $len = mb_strlen($flat, 'UTF-8');
    if ($len > $maxChars) {
        $flat = rtrim(mb_substr($flat, 0, $maxChars - 3, 'UTF-8'), ' |') . '...';
        $len = mb_strlen($flat, 'UTF-8');
    }

    // «یک یا دو خط»: تا ~۷۵ کاراکتر یک خط، بیشتر → دو خط.
    // (خودِ mPDF متنِ بلند را در دو خط می‌شکند؛ این فقط برای تصمیم‌گیری است.)
    return ['text' => $flat, 'lines' => $len > 75 ? 2 : 1];
}

/**
 * یک ردیفِ «برچسب : مقدار» در جدولِ اطلاعات کیس.
 * @param string $label    برچسب
 * @param string $valueHtml مقدار (HTML آماده)
 * @param string $cls      کلاسِ افزودنی برای سلولِ مقدار
 */
function woCell(string $label, string $valueHtml, string $cls = ''): string
{
    return '<td class="wo-lbl">' . htmlspecialchars($label) . '</td>'
         . '<td class="wo-val' . ($cls !== '' ? ' ' . $cls : '') . '">' . $valueHtml . '</td>';
}

/**
 * برچسبِ اختصاریِ نوع اتصال — برای چیدمانِ فشرده.
 *   screw-retained  → پیچی
 *   cement-retained → چسبی
 *   screw-cemented  → پیچی+چسبی
 */
function woConnectionShort(?string $key): string
{
    static $map = [
        'screw-retained'  => 'پیچی',
        'cement-retained' => 'چسبی',
        'screw-cemented'  => 'پیچی+چسبی',
    ];
    if ($key === null || !isset($map[$key])) return '';
    return $map[$key];
}

/**
 * نام اختصاریِ نوع اسکن‌بادی (اگر نام خیلی بلند باشد کوتاه می‌شود).
 */
function woScanBodyShort(string $name): string
{
    $name = trim($name);
    if ($name === '') return '';
    if (mb_strlen($name, 'UTF-8') <= 14) return $name;
    return rtrim(mb_substr($name, 0, 14, 'UTF-8'));
}

/**
 * HTML کاملِ دستور کارِ یک کیس (نسخهٔ «دو ستونه»).
 *
 * هر کیس یک «کارتِ فشرده» است که در یکی از دو ستونِ A4 قرار می‌گیرد، تا حداقل
 * ۸ کیس در یک برگه جا شود:
 *   • سرصفحه: #کیس | بیمار  ←(تب)←  خدمت | تاریخ
 *   • ردیف ۱: پزشک · تعداد · سایه · قبض
 *   • ردیف ۲: کیس اصلی · اسکن‌بادی · اتصال (اختصاری) · محل
 *   • توضیحات کیس
 *   • نوبت‌های اسکن (اگر باشد)
 *
 * @param array<int,array{label:string}> $appts نوبت‌های اسکن کیس (فقط برچسبِ متنی)
 */
function buildWorkOrderHtml(array $case, array $scanBodyNames, array $appts): string
{
    $caseId   = (int) $case['id'];
    $isCustom = woIsCustomAbutment($case);

    $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $val = static fn($v): string => (!empty($v) || $v === 0 || $v === '0')
        ? htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8')
        : '<span class="wo-muted">—</span>';

    // ── سرصفحه: شمارهٔ کیس + بیمار + (تب) + خدمت + تاریخ ──
    // بینِ نامِ بیمار و نامِ خدمت یک فاصلهٔ تبی («wo-tab») گذاشته می‌شود تا
    // به هم نچسبند.
    $head = '<div class="wo-head">'
          . '<span class="wo-id">#' . toPersianDigits((string) $caseId) . '</span>'
          . '<span class="wo-patient">' . $val($case['patient_name'] ?? '') . '</span>'
          . '<span class="wo-tab"></span>'
          . '<span class="wo-service">' . $val($case['service_title'] ?? '')
          . (!empty($case['service_short']) ? ' <span class="wo-short">(' . $esc($case['service_short']) . ')</span>' : '') . '</span>'
          . '<span class="wo-date">' . $val(toJalaliDateFormatted((string) ($case['received_date'] ?? ''))) . '</span>'
          . '</div>';

    // ── ردیف ۱: پزشک · تعداد · سایه · قبض ──
    $row1 = '<table class="wo-info"><tr>'
          . woCell('پزشک', $val($case['doctor_name'] ?? ''), 'wo-doctor')
          . woCell('تعداد', toPersianDigits((string) (int) ($case['quantity'] ?? 1)), 'wo-qty')
          . woCell('سایه', $val($case['shade'] ?? ''), 'wo-shade')
          . woCell('قبض', $val($case['receipt_number'] ?? ''), 'wo-receipt')
          . '</tr></table>';

    // ── ردیف ۲: کیس اصلی · اسکن‌بادی · اتصال · محل ──
    //
    // ⚠️ طبق درخواست کاربر: «کیس اصلی / اسکن‌بادی / اتصال» فقط زمانی چاپ می‌شوند
    // که **یا** کیس، زیرمجموعه (کیس اصلی دارد) باشد **یا** خدمتش کاستوم اباتمنت
    // (کره‌ای/اروپایی) باشد. در غیر این صورت این سه ستون حذف می‌شوند تا تمامِ
    // عرضِ ردیف به «محل/دندان» برسد و شمارهٔ دندان‌ها کامل نوشته شود.
    $hasParent   = !empty($case['parent_id']);
    $showAbutmentCols = $hasParent || $isCustom;

    if ($showAbutmentCols) {
        $parentTxt = $hasParent
            ? '<b class="wo-parent-id">#' . toPersianDigits((string) (int) $case['parent_id']) . '</b>'
            : '<span class="wo-muted">—</span>';

        $sbName = '';
        if ($isCustom && !empty($case['scan_body_type_id'])) {
            $sbName = (string) ($scanBodyNames[(int) $case['scan_body_type_id']] ?? '');
        }
        $sbTxt = ($isCustom && $sbName !== '')
            ? '<b>' . $esc(woScanBodyShort($sbName)) . '</b>'
            : '<span class="wo-muted">—</span>';

        // نوع اتصال به‌صورت اختصاری (پیچی / چسبی / پیچی+چسبی)
        $connTxt = '<span class="wo-muted">—</span>';
        if ($isCustom) {
            $short = woConnectionShort((string) ($case['connection_type'] ?? ''));
            if ($short !== '') $connTxt = '<b>' . $esc($short) . '</b>';
        }

        $row2 = '<table class="wo-info"><tr>'
              . woCell('کیس اصلی', $parentTxt, 'wo-parent')
              . woCell('اسکن‌بادی', $sbTxt, 'wo-scanbody')
              . woCell('اتصال', $connTxt, 'wo-conn')
              . woCell('محل', $val(woLocationLabel($case)), 'wo-loc')
              . '</tr></table>';
    } else {
        // بدونِ ستون‌های اباتمنت: «محل/دندان» تمامِ عرضِ ردیف را می‌گیرد.
        $row2 = '<table class="wo-info"><tr>'
              . woCell('محل / دندان', $val(woLocationLabel($case)), 'wo-loc-full')
              . '</tr></table>';
    }

    // ── توضیحات کیس ──
    // اینترها به «||» تبدیل و به یک/دو خط خلاصه می‌شوند تا ابعادِ کارت ثابت بماند
    // (وگرنه یک توضیحِ چندخطی کلِ چیدمان را به‌هم می‌ریخت).
    $descPrep = woPrepareDescription((string) ($case['description'] ?? ''), 150);
    $descHtml = '<div class="wo-desc-wrap">'
              . '<span class="wo-desc-lbl">توضیحات:</span>'
              . '<div class="wo-desc wo-desc-' . (int) $descPrep['lines'] . '">'
              . ($descPrep['text'] === '' ? '<span class="wo-muted">—</span>' : $esc($descPrep['text']))
              . '</div>'
              . '</div>';

    // ── نوبت‌های اسکن (اختیاری) ──
    $apptsHtml = '';
    if (!empty($appts)) {
        $items = '';
        foreach ($appts as $a) {
            $items .= '<div class="wo-appt">' . htmlspecialchars($a['label'] ?? '', ENT_QUOTES, 'UTF-8') . '</div>';
        }
        $apptsHtml = '<div class="wo-appts">'
                   . '<span class="wo-appts-lbl">نوبت اسکن:</span>'
                   . '<div class="wo-appts-list">' . $items . '</div>'
                   . '</div>';
    }

    return '<div class="wo-case">' . $head . $row1 . $row2 . $row3 . $descHtml . $apptsHtml . '</div>';
}

// ─── نوبت‌های اسکن هر کیس (برای دستور کار) ───────────────────────
$apptsByCase = [];
if (function_exists('getCaseScanAppointments')) {
    $saTypes = function_exists('scanAppointmentTypes') ? scanAppointmentTypes() : [];
    $saStatuses = function_exists('scanAppointmentStatuses') ? scanAppointmentStatuses() : [];
    foreach ($cases as $c) {
        $rows = getCaseScanAppointments((int) $c['id']);
        $list = [];
        foreach ($rows as $ap) {
            $t = $saTypes[(string) ($ap['appt_type'] ?? '')] ?? ['label' => '—', 'icon' => '📌'];
            $s = $saStatuses[(string) ($ap['status'] ?? '')] ?? ['label' => '—'];
            $line = ($t['icon'] ?? '') . ' ' . $t['label'] . ' — '
                  . toJalaliDateFormatted((string) $ap['appt_date'])
                  . ' ' . toPersianDigits(substr((string) $ap['start_time'], 0, 5));
            if (!empty($ap['end_time'])) {
                $line .= ' تا ' . toPersianDigits(substr((string) $ap['end_time'], 0, 5));
            }
            $line .= ' — ' . $s['label'];
            if (!empty($ap['scan_body_type_name'])) {
                $line .= ' — اسکن‌بادی: ' . $ap['scan_body_type_name'];
            }
            $list[] = ['label' => $line];
        }
        $apptsByCase[(int) $c['id']] = $list;
    }
}

// ─── موتور PDF (هم‌سان با print_labels.php) ──────────────────────
$mpdf = new Mpdf([
    'mode'          => 'utf-8',
    'format'        => 'A4',
    'margin_left'   => 12,
    'margin_right'  => 12,
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

// ─── CSS ────────────────────────────────────────────────────────
// چیدمانِ «دو ستونه»: کارتِ هر کیس نصفِ عرضِ A4 است و ستون‌ها کنار هم چیده
// می‌شوند تا ۱۰ کیس در یک برگه جا شود (۵ ردیف × ۲ ستون).
// اندازه‌ها طوری تنظیم شده‌اند که فضای خالیِ پایینِ صفحه کم شود.
$css = 'body{direction:rtl;font-family:vazir;font-size:9.5pt;color:#111827;}'
     // شبکهٔ دوستونه — هر کارت در یک خانه، کنارِ هم بدون فاصلهٔ اضافی
     . '.wo-grid{width:100%;border-collapse:collapse;table-layout:fixed;}'
     . '.wo-grid > tbody > tr > td{width:50%;vertical-align:top;padding:0;}'
     . '.wo-grid .wo-cell-l{padding-left:1.4mm;}'
     // شکستِ صفحهٔ اجباری بعد از ردیفِ آخرِ هر صفحه (۵ ردیف = ۱۰ کیس)
     . '.wo-page-break{page-break-after:always;}'
     // کارتِ هر کیس
     . '.wo-case{border:0.3mm solid #94a3b8;border-radius:1.2mm;padding:1.4mm 1.6mm;margin-bottom:2.2mm;'
     . 'page-break-inside:avoid;font-size:9.5pt;}'
     // سرصفحهٔ کارت (یک خط)
     . '.wo-head{display:block;border-bottom:0.3mm solid #cbd5e1;padding-bottom:1mm;margin-bottom:1mm;'
     . 'line-height:1.7;}'
     . '.wo-id{display:inline-block;background:#0f172a;color:#fff;font-family:vazirblack;font-size:11pt;'
     . 'padding:0.3mm 1.5mm;border-radius:0.9mm;white-space:nowrap;}'
     . '.wo-patient{font-family:vazirblack;font-size:12pt;margin-right:1.6mm;}'
     // فاصلهٔ «تب» بین نام بیمار و نام خدمت (کاربر خواسته خدمت نچسبد)
     . '.wo-tab{display:inline-block;width:6mm;}'
     . '.wo-service{font-size:8.5pt;color:#0369a1;font-weight:bold;}'
     . '.wo-short{color:#64748b;font-size:7pt;font-weight:normal;}'
     . '.wo-date{float:left;font-size:8.5pt;color:#475569;}'
     // جدول‌های اطلاعات (فشرده و هم‌تراز)
     . '.wo-info{width:100%;border-collapse:collapse;table-layout:fixed;margin:0;}'
     . '.wo-info td{padding:0.6mm 0.9mm;font-size:9.5pt;vertical-align:middle;border:0;line-height:1.6;}'
     . '.wo-lbl{color:#334155;font-weight:bold;white-space:nowrap;width:13mm;font-size:8.5pt;}'
     . '.wo-val{overflow:hidden;white-space:nowrap;}'
     . '.wo-doctor{width:40%;}'
     . '.wo-qty{width:10%;}'
     . '.wo-shade{width:12%;}'
     . '.wo-receipt{width:16%;}'
     . '.wo-parent{width:18%;}'
     . '.wo-scanbody{width:20%;}'
     . '.wo-conn{width:24%;}'
     . '.wo-loc{width:38%;}'
     // وقتی ستون‌های اباتمنت نیستند، «محل/دندان» تمامِ عرض را می‌گیرد
     . '.wo-loc-full{width:auto;}'
     . '.wo-parent-id{color:#7c3aed;font-family:vazirblack;}'
     . '.wo-muted{color:#94a3b8;}'
     // توضیحات (برچسب و متن در همان جریان؛ حداکثر دو خط)
     . '.wo-desc-wrap{margin-top:0.9mm;border-top:0.3mm dashed #cbd5e1;padding-top:0.9mm;line-height:1.8;}'
     . '.wo-desc-lbl{font-weight:bold;color:#1e3a8a;font-size:8.5pt;margin-left:1.2mm;}'
     . '.wo-desc{display:inline;font-size:9.5pt;}'
     . '.wo-desc-1{white-space:nowrap;overflow:hidden;}'
     . '.wo-desc-2{display:block;max-height:9mm;overflow:hidden;}'
     // نوبت‌های اسکن
     . '.wo-appts{margin-top:0.6mm;font-size:8.5pt;color:#075985;line-height:1.6;}'
     . '.wo-appts-lbl{font-weight:bold;margin-left:1.2mm;}'
     . '.wo-appts-list{display:inline;}'
     . '.wo-appt{display:inline;margin-left:2.5mm;}';

$html = '<html dir="rtl"><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>';

// چیدمانِ دوستونه با صفحه‌بندیِ **صریح**.
// ⚠️ درسِ مهم mPDF: اگر صفحه‌بندی را به خودِ موتور بسپاریم، mPDF کارت‌های
// سادهٔ div/table را «کوتاه» اندازه می‌گیرد و همه را روی یک صفحه می‌ریزد
// (۶۰ کارت هم ۱ صفحه می‌شد!). پس خودمان بعد از هر N ردیف، شکستِ صفحه می‌گذاریم.
//
// «ردیف» = یک جدولِ دوستونه (۲ کیس). با ۵ ردیف در صفحه، ۱۰ کیس در A4 جا می‌شود.
$rowsPerPage = 5;                 // ۵ ردیف × ۲ ستون = ۱۰ کیس در هر صفحه
$pairIndex   = 0;
$totalPairs  = (int) ceil(count($cases) / 2);

foreach (array_chunk($cases, 2) as $pair) {
    $right = buildWorkOrderHtml($pair[0], $scanBodyNames, $apptsByCase[(int) $pair[0]['id']] ?? []);
    $left  = isset($pair[1])
        ? buildWorkOrderHtml($pair[1], $scanBodyNames, $apptsByCase[(int) $pair[1]['id']] ?? [])
        : '';
    $pairIndex++;
    // کلاسِ شکست فقط روی ردیفِ آخرِ هر صفحه (و نه ردیفِ آخرِ کلِ سند)
    $breakCls = ($pairIndex % $rowsPerPage === 0 && $pairIndex < $totalPairs) ? ' wo-page-break' : '';
    $html .= '<table class="wo-grid' . $breakCls . '"><tbody><tr>'
           . '<td class="wo-cell-r">' . $right . '</td>'
           . '<td class="wo-cell-l">' . $left . '</td>'
           . '</tr></tbody></table>';
}
$html .= '</body></html>';

// حالتِ پیش‌نمایشِ HTML (فقط برای تست/بازرسی چشمی): همان HTML/CSS که به mPDF
// داده می‌شود، بی‌واسطه ذخیره می‌شود.
if (defined('PRINT_WORK_ORDERS_OUT_HTML')) {
    file_put_contents(constant('PRINT_WORK_ORDERS_OUT_HTML'), $html);
}

$mpdf->WriteHTML($html);

// تشخیصی: تعداد صفحاتی که mPDF واقعاً ساخته است (فقط در CLI با WO_DEBUG_PAGES=1)
if (PHP_SAPI === 'cli' && getenv('WO_DEBUG_PAGES')) {
    fwrite(STDOUT, 'mpdf pages: ' . $mpdf->page . "\n");
}

$filename = 'work_orders_' . implode('_', array_column($cases, 'id')) . '.pdf';

// حالتِ تست/CLI: اگر PRINT_WORK_ORDERS_OUT_FILE تعریف شده باشد، به‌جای ارسال به
// مرورگر روی دیسک ذخیره می‌شود (برای اسکریپتِ آزمایشی storage/_wo_test.php).
if (defined('PRINT_WORK_ORDERS_OUT_FILE')) {
    $mpdf->Output(constant('PRINT_WORK_ORDERS_OUT_FILE'), 'F');
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, "saved: " . constant('PRINT_WORK_ORDERS_OUT_FILE') . " (" . count($cases) . " cases)\n");
    }
    return;
}

$mpdf->Output($filename, 'I');
