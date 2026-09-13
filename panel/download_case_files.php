<?php
// panel/download_case_files.php
// دانلود هم‌زمان «فایل‌های خام» کیس‌های انتخاب‌شده (ZIP).
// فقط فایل‌های خام (اسکن) دانلود می‌شوند — نه فایل‌های طراحی و نه عکس‌های بیمار:
//   - file_type = 'raw_scan' → خام
//   - file_type IS NULL و پسوند rar نباشد → خام (فایل‌های قدیمی)
// فایل‌های طراحی (final_design یا rar) توسط «دانلود طراحی‌ها» دانلود می‌شوند.
require_once __DIR__ . '/auth.php';
require_login();

$caseIds = array_values(array_filter(array_map('intval', $_POST['case_ids'] ?? [])));
$caseIds = array_unique($caseIds);
if (empty($caseIds)) {
    http_response_code(400);
    echo '<meta charset="utf-8"><body style="font-family:Tahoma;padding:40px;text-align:center;">'
        . '<h3>کیسی انتخاب نشده است.</h3><a href="cases.php">بازگشت به کیس‌ها</a></body>';
    exit;
}

$user = current_user();

// ─── محدوده دسترسی: هر طرف فقط کیس‌های خودش را می‌تواند دانلود کند ───
$scopeSql = '';
$scopeParams = [];
if ($user['role'] === 'designer') {
    $scopeSql = ' AND c.designer_id = ?';
    $scopeParams[] = (int) $user['id'];
} elseif (in_array($user['role'] ?? '', ['lab', 'outsource_lab', 'customer_lab', 'partner_lab'], true)) {
    $scopeSql = ' AND (c.lab_id = ? OR c.outsourced_lab_id = ?)';
    $scopeParams[] = (int) $user['id'];
    $scopeParams[] = (int) $user['id'];
} elseif (!is_admin() && !has_permission('view_all_cases') && !has_permission('view_case_files') && !has_permission('edit_cases')) {
    http_response_code(403);
    die('دسترسی غیرمجاز');
}

$ph = implode(',', array_fill(0, count($caseIds), '?'));
$stmt = db()->prepare("
    SELECT cf.case_id, cf.filename, cf.original_name, c.received_date, cf.created_at AS file_created
    FROM case_files cf
    JOIN cases c ON c.id = cf.case_id
    WHERE cf.case_id IN ($ph) $scopeSql
      AND (cf.file_type = 'raw_scan'
           OR (cf.file_type IS NULL AND LOWER(RIGHT(cf.filename, 4)) <> '.rar'))
    ORDER BY cf.case_id ASC, cf.id ASC
");
$stmt->execute(array_merge($caseIds, $scopeParams));
$rows = $stmt->fetchAll();

if (empty($rows)) {
    http_response_code(404);
    echo '<meta charset="utf-8"><body style="font-family:Tahoma;padding:40px;text-align:center;">'
        . '<h3>فایل خامی برای کیس‌های انتخاب‌شده یافت نشد</h3>'
        . '<p>توجه: فقط فایل‌های خام (اسکن) دانلود می‌شوند؛ فایل‌های طراحی را از «دانلود طراحی‌ها» بگیرید.</p>'
        . '<a href="cases.php" style="color:#0F172A;">بازگشت به کیس‌ها</a></body>';
    exit;
}

$zipName = 'scan_' . jalaliDateForFilename(date('Y-m-d')) . '.zip';
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
    // نام داخل زیپ: scan_<تاریخ دریافت>.ext (در صورت تکرار: scan_<تاریخ>_2.ext)
    $dateStr = jalaliDateForFilename($r['received_date'] ?: ($r['file_created'] ?? date('Y-m-d')));
    $fe = pathinfo($r['original_name'], PATHINFO_EXTENSION);
    $entryBase = 'case_' . $r['case_id'] . '/scan_' . $dateStr . ($fe !== '' ? '.' . $fe : '');
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

// ─── ثبت لاگ دانلود برای هر کیس ───
$loggedCases = [];
foreach ($rows as $r) {
    $cid = (int) $r['case_id'];
    if (isset($loggedCases[$cid])) continue;
    $loggedCases[$cid] = 1;
    log_case_activity($cid, 'file_download', json_encode(['type' => 'raw', 'files' => $added], JSON_UNESCAPED_UNICODE));
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($outPath));
header('Cache-Control: private, max-age=0');
header('Pragma: public');
readfile($outPath);
@unlink($outPath);
exit;
