<?php
// panel/rename_upload_folder.php
// تغییر نامِ یک «پوشهٔ آپلودشده» در کتابخانهٔ فایل‌ها.
//
// مفهومِ «نام پوشه» در این پروژه: بخشِ اولِ مسیرِ نسبیِ فایل‌ها (rel_path) — مثل
// «پوشهٔ اسکن/فایل.stl» که نامِ پوشه همان «پوشهٔ اسکن» است. پس تغییر نام به‌معنای
// به‌روزرسانی rel_path همهٔ فایل‌های داخل آن پوشه است.
//
// دسترسی: مدیر سیستم، مدیر شعبه، یا صاحبِ همهٔ فایل‌های آن پوشه.
// (مثل delete_upload_folder.php: فایل‌هایی که کاربر مالکشان نیست، دست‌نخورده می‌مانند.)
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: uploads.php');
    exit;
}

$user   = current_user();
$folder = sanitizeRelPath((string) ($_POST['folder'] ?? ''));
$scopeAll = !empty($_POST['all']) && (is_admin() || ($user['role'] ?? '') === 'designer');
$newRaw = trim((string) ($_POST['new_name'] ?? ''));

$redirectBack = 'uploads.php' . ($scopeAll ? '?tab=all' : '');

if ($folder === null) {
    header('Location: ' . $redirectBack . (strpos($redirectBack, '?') === false ? '?' : '&') . 'error=invalid_folder');
    exit;
}
$root = relPathRoot($folder)['root'] ?? null;
if ($root === null) {
    header('Location: ' . $redirectBack . (strpos($redirectBack, '?') === false ? '?' : '&') . 'error=invalid_folder');
    exit;
}

// ─── اعتبارسنجی نامِ جدید ───
// همان قواعدِ sanitizeRelPath: فقط نامِ یک سگمنت، بدون / \ .. و کاراکترهای کنترلی.
$newName = sanitizeRelPath($newRaw);
if ($newName === null || $newName === '' || strpos($newName, '/') !== false) {
    header('Location: ' . $redirectBack . (strpos($redirectBack, '?') === false ? '?' : '&') . 'error=bad_name');
    exit;
}

// ─── فایل‌های این پوشه (و زیرپوشه‌هایش) ───
$sql = 'SELECT id, user_id, rel_path FROM user_uploads WHERE (rel_path = ? OR rel_path LIKE ?)';
$params = [$root, $root . '/%'];
if (!$scopeAll) {
    // حالت «فایل‌های من» → فقط آپلودهای خود کاربر تغییر می‌کنند
    $sql .= ' AND user_id = ?';
    $params[] = (int) $user['id'];
}
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

if (empty($rows)) {
    header('Location: ' . $redirectBack . (strpos($redirectBack, '?') === false ? '?' : '&') . 'error=notfound');
    exit;
}

// ─── بررسی تکراری نبودن نامِ جدید (در همان محدوده) ───
$dupSql = 'SELECT COUNT(*) FROM user_uploads WHERE (rel_path = ? OR rel_path LIKE ?) AND rel_path NOT IN (';
$dupSql .= implode(',', array_fill(0, count($rows), '?'));
$dupSql .= ')';
$dupParams = [$newName, $newName . '/%'];
foreach ($rows as $r) { $dupParams[] = $r['rel_path']; }
try {
    if (!$scopeAll) { $dupSql .= ' AND user_id = ?'; $dupParams[] = (int) $user['id']; }
    $dup = db()->prepare($dupSql);
    $dup->execute($dupParams);
    if ((int) $dup->fetchColumn() > 0) {
        header('Location: ' . $redirectBack . (strpos($redirectBack, '?') === false ? '?' : '&') . 'error=duplicate');
        exit;
    }
} catch (Throwable $e) {
    // اگر چک تکراری بودن خطا داد، از آن عبور می‌کنیم (بهتر از بلاک کردنِ کاربر)
}

// ─── به‌روزرسانی rel_path همهٔ فایل‌ها ───
// «root/rest» → «newName/rest»  (فایلِ بدون زیرمسیر: «root» → «newName»)
$upd = db()->prepare('UPDATE user_uploads SET rel_path = ? WHERE id = ?');
$renamed = 0;
foreach ($rows as $r) {
    $rel = (string) $r['rel_path'];
    $rest = '';
    if ($rel === $root) {
        $rest = '';
    } elseif (strpos($rel, $root . '/') === 0) {
        $rest = substr($rel, strlen($root) + 1);
    } else {
        continue;   // نباید پیش بیاید (کوئری بالا فقط همان‌ها را می‌آورد)
    }
    $newRel = $rest === '' ? $newName : ($newName . '/' . $rest);
    try {
        $upd->execute([$newRel, (int) $r['id']]);
        $renamed++;
    } catch (Throwable $e) {
        // احتمال برخورد با کلید یکتای rel_path — از این فایل عبور کن
    }
}

audit_log_save('user_upload_folder', 0, 'تغییر نام پوشهٔ آپلود: ' . $root . ' → ' . $newName);
header('Location: ' . $redirectBack . (strpos($redirectBack, '?') === false ? '?' : '&') . 'msg=renamed&n=' . $renamed);
exit;
