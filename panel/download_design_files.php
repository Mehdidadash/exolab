<?php
// panel/download_design_files.php
// دانلود تجمیعی فایل‌های آپلودشده توسط «طراح» کیس‌های انتخابی به‌صورت ZIP.
// هر فایل داخل زیپ در پوشه‌ی case_{id} قرار می‌گیرد تا فایل‌های هم‌نام باهم تداخل نداشته باشند.
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
// فقط فایل‌هایی که توسط طراحِ (فعلی) همان کیس آپلود شده‌اند
$stmt = db()->prepare("
    SELECT cf.case_id, cf.filename, cf.original_name
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

$zipName = 'designs_' . date('Ymd_His') . '.zip';
$outPath = __DIR__ . '/../storage/' . $zipName;
$zip = new ZipArchive();
if ($zip->open($outPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    die('خطا در ایجاد فایل ZIP');
}

$added = 0;
$used = [];
foreach ($rows as $r) {
    $filePath = resolve_upload_path('cases/' . $r['case_id'] . '/' . $r['filename']);
    if (!file_exists($filePath)) continue;
    // نام داخل زیپ: case_{id}/original_name (در صورت تکرار، _1، _2 و...)
    $entryBase = 'case_' . $r['case_id'] . '/' . $r['original_name'];
    $entry = $entryBase;
    $i = 1;
    while (isset($used[$entry])) {
        $p = pathinfo($entryBase);
        $entry = $p['dirname'] . '/' . $p['filename'] . "_($i)." . ($p['extension'] ?? '');
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
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($outPath));
header('Cache-Control: private, max-age=0');
header('Pragma: public');
readfile($outPath);
@unlink($outPath);
exit;
