<?php
// panel/download_design_files.php
// دانلود تجمیعی فایل‌های آپلودشده توسط «طراح» کیس‌های انتخابی به‌صورت ZIP.
//
// ★ ساختار ZIP دقیقاً مطابق صفحهٔ «مشاهدهٔ کیس» است:
//     case_{id}/<پوشهٔ طراحی>/<زیرپوشه‌ها...>/<نام نهاییِ فایل>
//   - فایل‌های داخل پوشهٔ آپلودشده، ساختار درختِ rel_path را حفظ می‌کنند.
//   - فایل‌های «تکی» (بدون rel_path) مستقیم زیر case_{id}/ قرار می‌گیرند.
//   - نام هر فایل همان نامی است که در صفحهٔ مشاهدهٔ کیس دیده می‌شود
//     (پیشوندِ {شماره‌کیس}_{سایه}_{بیمار}_{قبض} + نام اصلی).
// پوشهٔ case_{id} برای جلوگیری از تداخل فایل‌های هم‌نام بین کیس‌های انتخابی است.
require_once __DIR__ . '/auth.php';
require_login();

// دسترسی: مدیر/مدیر شعبه یا کاربر دارای مجوز ویرایش/مشاهده فایل
if (!is_admin() && !has_permission('view_case_files') && !has_permission('edit_cases')) {
    http_response_code(403);
    die('دسترسی غیرمجاز');
}

$raw = $_POST['case_ids'] ?? $_GET['case_ids'] ?? [];
$caseIds = array_values(array_unique(array_filter(array_map('intval', (array) $raw), fn($v) => $v > 0)));

if (empty($caseIds)) {
    http_response_code(400);
    die('کیسی انتخاب نشده است.');
}

$ph = implode(',', array_fill(0, count($caseIds), '?'));
// فقط فایل‌هایی که توسط طراحِ (فعلی) همان کیس آپلود شده‌اند.
// ستون‌های نام‌گذاری کیس هم لازم است تا نام داخل ZIP با صفحهٔ مشاهدهٔ کیس یکسان شود.
$stmt = db()->prepare("
    SELECT cf.case_id, cf.filename, cf.original_name, cf.rel_path, cf.file_type,
           c.received_date, c.shade, c.patient_name, c.receipt_number,
           cf.created_at AS file_created
    FROM case_files cf
    JOIN cases c ON c.id = cf.case_id
    WHERE cf.case_id IN ($ph)
      AND cf.uploader_id IS NOT NULL
      AND cf.uploader_id = c.designer_id
      AND (cf.file_type = 'final_design'
           OR (cf.file_type IS NULL AND LOWER(RIGHT(cf.filename, 4)) = '.rar'))
    ORDER BY cf.case_id ASC, cf.id ASC
");
$stmt->execute($caseIds);
$rows = $stmt->fetchAll();

if (empty($rows)) {
    http_response_code(404);
    echo '<meta charset="utf-8"><body style="font-family:Tahoma;padding:40px;text-align:center;">'
        . '<h3>فایل طراحی برای کیس‌های انتخاب‌شده یافت نشد</h3>'
        . '<p>توجه: فقط فایل‌هایی با نوع «طراحی نهایی» که توسط طراحِ هر کیس آپلود شده‌اند دانلود می‌شوند.</p>'
        . '<a href="cases.php" style="color:#0F172A;">بازگشت به کیس‌ها</a></body>';
    exit;
}

$zipName = 'design_files_' . jalaliDateForFilename(date('Y-m-d')) . '.zip';
$outPath = __DIR__ . '/../storage/' . 'tmp_' . bin2hex(random_bytes(6)) . '_' . $zipName;
$zip = new ZipArchive();
if ($zip->open($outPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    die('خطا در ایجاد فایل ZIP');
}

$added = 0;
$used = [];
$multiCase = (count(array_unique(array_column($rows, 'case_id'))) > 1);
foreach ($rows as $r) {
    $filePath = resolve_upload_path('cases/' . $r['case_id'] . '/' . $r['filename']);
    if (!file_exists($filePath)) continue;

    // ── پیشوندِ نام‌گذاری (نقطهٔ واحد: caseFileNamingPrefix) ──
    // دقیقاً همان منطقی که هنگام آپلود استفاده شده، تا نام نمایش‌داده‌شده در
    // صفحهٔ مشاهدهٔ کیس و نام داخل ZIP یکی باشد.
    $prefix = '';
    if (($r['file_type'] ?? null) === 'final_design') {
        $prefix = caseFileNamingPrefix([
            'id'             => $r['case_id'],
            'shade'          => $r['shade'] ?? '',
            'patient_name'   => $r['patient_name'] ?? '',
            'receipt_number' => $r['receipt_number'] ?? '',
        ]);
    }
    $baseName = basename((string) $r['original_name']);
    if ($prefix !== '') {
        $baseName = addCaseFilePrefix($baseName, $prefix);
    }

    // ── ساختار پوشه: همان قاعدهٔ صفحهٔ مشاهدهٔ کیس ──
    // پوشه است اگر rel_path چندبخشی باشد، یا تک‌بخشی و بدون پسوند فایل.
    $rp = relPathRoot($r['rel_path'] ?? null);
    $isFolder = $rp && (
        $rp['rest'] !== ''
        || pathinfo($rp['root'], PATHINFO_EXTENSION) === ''
    );

    if ($isFolder) {
        // ⚠️ پوشهٔ ریشهٔ آپلود (مثلاً «طراحی») دور ریخته می‌شود؛ فقط زیرپوشه‌ها حفظ می‌شوند
        // تا کاربر مجبور نباشد یک سطح اضافه را باز کند. (درخواست کاربر)
        $rel = sanitizeRelPath($r['rel_path']);
        $rest = ($rel !== null && str_contains($rel, '/')) ? substr($rel, strpos($rel, '/') + 1) : '';
        $dir = ($rest !== '' && str_contains($rest, '/')) ? dirname($rest) : '';
        $inner = ($dir !== '' && $dir !== '.') ? $dir . '/' . $baseName : $baseName;
    } else {
        // فایل تکی → مستقیم در ریشهٔ ZIP
        $inner = $baseName;
    }

    // ⚠️ پوشهٔ «case_{id}» فقط وقتی لازم است که بیش از یک کیس انتخاب شده باشد
    // (برای جلوگیری از تداخل فایل‌های هم‌نام). برای یک کیس، فایل‌ها مستقیم در ریشهٔ ZIP می‌آیند.
    $entryBase = ($multiCase ? 'case_' . $r['case_id'] . '/' : '') . ltrim(str_replace('\\', '/', $inner), '/');

    // ── یکتا‌سازی نامِ تکراری داخل ZIP ──
    $entry = $entryBase;
    $i = 1;
    while (isset($used[$entry])) {
        $p = pathinfo($entryBase);
        $dirPart = (($p['dirname'] ?? '') !== '.' && ($p['dirname'] ?? '') !== '') ? $p['dirname'] . '/' : '';
        $entry = $dirPart . $p['filename'] . "_($i)" . (($p['extension'] ?? '') !== '' ? '.' . $p['extension'] : '');
        $i++;
    }
    $used[$entry] = true;
    $zip->addFile($filePath, $entry);
    $added++;
}
$zip->close();

if ($added === 0) {
    @unlink($outPath);
    http_response_code(404);
    die('فایلی در سرور یافت نشد.');
}

// ثبت لاگ دانلود طراحی برای هر کیس (چه کسی / چه زمانی)
$loggedCases = [];
foreach ($rows as $r) {
    $cid = (int) $r['case_id'];
    if (isset($loggedCases[$cid])) continue;
    $loggedCases[$cid] = 1;
    log_case_activity($cid, 'file_download', json_encode(['type' => 'design', 'files' => $added], JSON_UNESCAPED_UNICODE));
}

header('Content-Type: application/zip');
// ⚠️ نام فایل در هر دانلود یکتاست تا دانلود‌منیجر (IDM) فایل را «قبلاً دانلودشده»
// نبیند و هرگز از کش/نسخهٔ قبلی استفاده نکند. (فایل روی سرور موقتی است و بعد پاک می‌شود.)
$dlName = 'design_files_' . jalaliDateForFilename(date('Y-m-d')) . '_' . date('His') . '.zip';
header('Content-Disposition: attachment; filename="' . $dlName . '"; filename*=UTF-8\'\'' . rawurlencode($dlName));
header('Content-Length: ' . filesize($outPath));
// جلوگیری از استفادهٔ کش/نسخهٔ قبلی توسط مرورگر یا دانلود‌منیجر
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
readfile($outPath);
@unlink($outPath);
exit;
