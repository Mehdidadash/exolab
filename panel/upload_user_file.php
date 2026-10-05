<?php
// panel/upload_user_file.php
// AJAX handler for personal uploads (doctor / designer / any user).
require_once __DIR__ . '/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'method']);
    exit;
}

// CSRF via POST field or header
$sessionToken = $_SESSION['_csrf_token'] ?? '';
$token = $_POST['_csrf_token'] ?? '';
if (empty($token)) {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
}
if (empty($sessionToken) || !hash_equals($sessionToken, $token)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'csrf_invalid']);
    exit;
}

$user = current_user();
$caseId = !empty($_POST['case_id']) ? (int) $_POST['case_id'] : null;

// ─── جمع‌آوری فایل‌ها: هم حالت تک‌فایلی قدیمی (file) و هم چندانتخابی (files[]) ───
$incoming = [];
if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
    foreach ($_FILES['files']['name'] as $i => $nm) {
        $incoming[] = [
            'name'     => (string) $nm,
            'tmp_name' => (string) ($_FILES['files']['tmp_name'][$i] ?? ''),
            'size'     => (int) ($_FILES['files']['size'][$i] ?? 0),
            'type'     => (string) ($_FILES['files']['type'][$i] ?? ''),
            'error'    => (int) ($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE),
        ];
    }
} elseif (!empty($_FILES['file'])) {
    $incoming[] = [
        'name'     => (string) $_FILES['file']['name'],
        'tmp_name' => (string) $_FILES['file']['tmp_name'],
        'size'     => (int) $_FILES['file']['size'],
        'type'     => (string) ($_FILES['file']['type'] ?? ''),
        'error'    => (int) $_FILES['file']['error'],
    ];
}

// ─── مسیرهای نسبیِ آپلود پوشه‌ای (هم‌ترتیب با آرایهٔ بالا) ───
// فقط برای نمایش/گروه‌بندی؛ هرگز به فایل‌سیستم نمی‌رسد.
$relPathsRaw = $_POST['rel_paths'] ?? [];
if (!is_array($relPathsRaw)) $relPathsRaw = [$relPathsRaw];
foreach ($incoming as $i => $f) {
    $incoming[$i]['rel_path'] = sanitizeRelPath(is_string($relPathsRaw[$i] ?? null) ? $relPathsRaw[$i] : null);
}
$hasFolderFiles = !empty(array_filter($incoming, fn($f) => isFolderUploadFile($f['rel_path'] ?? null)));

// نام پوشهٔ ریشه (برای نام‌گذاری ZIP و عنوان گروه)
$folderName = sanitizeRelPath((string) ($_POST['folder_name'] ?? ''));
if ($folderName !== null && $folderName !== '') {
    $folderName = relPathRoot($folderName)['root'] ?? null;
} else {
    $folderName = null;
}

// ─── نام‌گذاری خودکار فایل‌های «طراحی نهایی» (کتابخانه) ───
// همان قاعدهٔ صفحهٔ کیس‌ها و مشاهدهٔ کیس: فقط وقتی نوع فایل «طراحی نهایی» باشد و
// کیس انتخاب شده باشد. از تابع مشترک استفاده می‌کنیم تا هرگز از هم دور نشوند.
$uploadFileType = trim((string) ($_POST['file_type'] ?? ''));
if ($uploadFileType !== '' && !in_array($uploadFileType, array_keys(caseFileTypeConfig()['options']), true)) {
    $uploadFileType = '';
}
$namingPrefix = ($caseId && $uploadFileType !== '') ? caseFilePrefixForUpload($caseId, $uploadFileType) : '';

// فقط فایل‌های سالم
$files = array_values(array_filter($incoming, function ($f) { return $f['error'] === UPLOAD_ERR_OK; }));

if (empty($files)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'فایلی انتخاب نشده است.']);
    exit;
}

$allowed = ['zip', 'rar', 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'stl', 'ply', 'stp', 'step', 'obj', '3mf',
    // فایل‌های خروجیِ دستگاه‌های اسکن
    'matrix4', 'dentalproject', 'iftscan', 'constructioninfo', 'dcm', 'dicom', 'txt', 'xml',
    // گزارش/طراحی HTML (هنگام نمایش به‌صورت متن سرو می‌شود تا اسکریپت اجرا نشود)
    'html', 'htm'];
foreach ($files as $f) {
    $e = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($e, $allowed)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'فرمت فایل مجاز نیست: ' . $f['name']]);
        exit;
    }
}

// If a case is selected, verify the user can access that case
if ($caseId) {
    $ok = false;
    if ($user['role'] === 'doctor') {
        $chk = db()->prepare('SELECT id FROM cases WHERE id = ? AND doctor_id = ?');
        $chk->execute([$caseId, $user['id']]);
        $ok = (bool) $chk->fetch();
    } elseif ($user['role'] === 'lab' || $user['role'] === 'outsource_lab' || $user['role'] === 'customer_lab' || $user['role'] === 'partner_lab') {
        $chk = db()->prepare('SELECT id FROM cases WHERE id = ? AND lab_id = ?');
        $chk->execute([$caseId, $user['id']]);
        $ok = (bool) $chk->fetch();
    } elseif ($user['role'] === 'clinic') {
        $clinicScope = getClinicScope('c');
        $chk = db()->prepare('SELECT id FROM cases c WHERE c.id = ? AND ' . $clinicScope['sql']);
        $chk->execute(array_merge([$caseId], $clinicScope['params']));
        $ok = (bool) $chk->fetch();
    } elseif (has_permission('view_all_cases')) {
        $chk = db()->prepare('SELECT id FROM cases WHERE id = ?');
        $chk->execute([$caseId]);
        $ok = (bool) $chk->fetch();
    }
    if (!$ok) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'دسترسی به کیس موردنظر ندارید.']);
        exit;
    }
}

$uploadDir = ensure_uploads_dir('user') . '/';
$description = trim($_POST['description'] ?? '');
$description = $description !== '' ? $description : null;
$compress = !empty($_POST['compress']) && count($files) > 1;

$savedIds = [];
$errors   = [];

if ($compress) {
    // ─── بسته‌بندی همهٔ فایل‌ها در یک ZIP (مثل صفحهٔ کیس‌ها) ───
    $zipBase = 'files_' . date('Ymd_His');
    if ($caseId) {
        $cs = db()->prepare('SELECT patient_name FROM cases WHERE id = ? LIMIT 1');
        $cs->execute([$caseId]);
        $pname = persian_to_finglish((string) ($cs->fetchColumn() ?: ''));
        if ($pname !== '') { $zipBase = $caseId . '_' . $pname; }
    }
    // پوشهٔ طراحی نهایی → پیشوند نام‌گذاری به نام ZIP هم اضافه می‌شود
    if ($namingPrefix !== '') {
        $zipBase = $namingPrefix . ($folderName ? '_' . $folderName : '_design');
    }
    $zipName = uniqueUserUploadName((int) $user['id'], $caseId, $zipBase . '.zip');
    $safe = bin2hex(random_bytes(8)) . '.zip';
    $zipPath = $uploadDir . $safe;

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'خطا در ساخت فایل ZIP.']);
        exit;
    }
    $used = [];
    foreach ($files as $f) {
        // نام داخل ZIP: اگر مسیر نسبی داریم ساختار پوشه‌ها حفظ می‌شود
        $nm = basename($f['name']);
        if (isFolderUploadFile($f['rel_path'] ?? null)) {
            $dir = dirname((string) $f['rel_path']);
            $nm = ($dir !== '' && $dir !== '.' ? $dir . '/' : '') . basename($f['name']);
        }
        // نام‌گذاری خودکار (پوشهٔ طراحی نهایی) — فقط روی نام فایل، نه مسیر پوشه
        if ($namingPrefix !== '') {
            $dir = (str_contains($nm, '/')) ? dirname($nm) : '';
            $nm = ($dir !== '' && $dir !== '.' ? $dir . '/' : '') . addCaseFilePrefix(basename($nm), $namingPrefix);
        }
        if (isset($used[$nm])) {
            $pi = pathinfo($nm);
            $d  = ($pi['dirname'] ?? '') !== '.' ? $pi['dirname'] . '/' : '';
            $nm = $d . $pi['filename'] . '_' . count($used) . '.' . ($pi['extension'] ?? '');
        }
        $used[$nm] = true;
        $zip->addFile($f['tmp_name'], $nm);
    }
    $zip->close();
    $size = (int) @filesize($zipPath);
    if ($size <= 0) {
        @unlink($zipPath);
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'خطا در ذخیره فایل ZIP.']);
        exit;
    }
    @chmod($zipPath, 0644);
    // rel_path ردیفِ ZIP = نام پوشه (تا در درخت به‌عنوان «فایل داخل پوشه» شمرده نشود)
    $zipRelPath = $folderName ?: null;
    $ins = db()->prepare('INSERT INTO user_uploads (user_id, case_id, filename, original_name, rel_path, description, mime, size, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $ins->execute([$user['id'], $caseId, $safe, $zipName, $zipRelPath, $description, 'application/zip', $size]);
    $savedIds[] = (int) db()->lastInsertId();
} else {
    $ins = db()->prepare('INSERT INTO user_uploads (user_id, case_id, filename, original_name, rel_path, description, mime, size, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    foreach ($files as $f) {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $safe = bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $uploadDir . $safe;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            $errors[] = $f['name'];
            continue;
        }
        @chmod($dest, 0644);
        // نام‌گذاری خودکار — همان قاعدهٔ مسیرهای دیگر (پوشه یا فایل تکی، یکسان)
        $displaySrc = basename($f['name']);
        if ($namingPrefix !== '') {
            $displaySrc = addCaseFilePrefix($displaySrc, $namingPrefix);
        }
        $displayName = uniqueUserUploadName((int) $user['id'], $caseId, $displaySrc);
        $ins->execute([$user['id'], $caseId, $safe, $displayName, ($f['rel_path'] ?? null), $description, $f['type'], $f['size']]);
        $savedIds[] = (int) db()->lastInsertId();
    }
}

if (empty($savedIds)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'خطا در ذخیره فایل.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'id' => $savedIds[0],
    'ids' => $savedIds,
    'uploaded' => count($savedIds),
    'zipped' => $compress,
    'errors' => $errors,
]);
