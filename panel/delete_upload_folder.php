<?php
// panel/delete_upload_folder.php
// حذف یک «پوشهٔ آپلودشده» از کتابخانهٔ فایل‌ها — یعنی حذف همهٔ فایل‌های داخل آن پوشه.
//
// دسترسی: هر فایل فقط اگر مالکش کاربر باشد یا کاربر مدیر باشد حذف می‌شود؛
// فایل‌هایی که کاربر اجازهٔ حذفشان را ندارد، دست‌نخورده می‌مانند و در پاسخ گزارش می‌شوند.
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: uploads.php');
    exit;
}

$user   = current_user();
$folder = sanitizeRelPath((string) ($_POST['folder'] ?? ''));
$scopeAll = !empty($_POST['all']) && (is_admin() || $user['role'] === 'designer');

if ($folder === null) {
    header('Location: uploads.php?error=invalid_folder');
    exit;
}
$root = relPathRoot($folder)['root'] ?? null;
if ($root === null) {
    header('Location: uploads.php?error=invalid_folder');
    exit;
}

// ─── فایل‌های این پوشه (و زیرپوشه‌هایش) ───
$sql = 'SELECT id, user_id, filename FROM user_uploads WHERE (rel_path = ? OR rel_path LIKE ?)';
$params = [$root, $root . '/%'];
if (!$scopeAll) {
    // حالت «فایل‌های من» → فقط آپلودهای خود کاربر کاندید حذف‌اند
    $sql .= ' AND user_id = ?';
    $params[] = (int) $user['id'];
}
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

if (empty($rows)) {
    header('Location: uploads.php?error=notfound');
    exit;
}

$delFile   = db()->prepare('DELETE FROM user_uploads WHERE id = ?');
// پیوندهای چندبه‌چند هم پاک شوند (اگر جدول وجود داشت)
$hasLinks = false;
try {
    db()->query('SELECT 1 FROM user_upload_case_links LIMIT 1');
    $hasLinks = true;
} catch (\Throwable $e) {
    $hasLinks = false;
}
$delLink = $hasLinks ? db()->prepare('DELETE FROM user_upload_case_links WHERE upload_id = ?') : null;

$deleted = 0;
$skipped = 0;
foreach ($rows as $r) {
    // فقط مالک یا مدیر
    $isOwner = (int) $r['user_id'] === (int) $user['id'];
    if (!$isOwner && !is_admin()) {
        $skipped++;
        continue;
    }

    $path = resolve_upload_path('user/' . $r['filename']);
    if (file_exists($path)) @unlink($path);
    $legacyPath = legacy_uploads_path('user/' . $r['filename']);
    if ($legacyPath !== $path && file_exists($legacyPath)) @unlink($legacyPath);

    if ($delLink) $delLink->execute([(int) $r['id']]);
    $delFile->execute([(int) $r['id']]);
    $deleted++;
}

$qs = 'deleted=' . $deleted;
if ($skipped > 0) $qs .= '&skipped=' . $skipped;
header('Location: uploads.php?' . $qs);
exit;
