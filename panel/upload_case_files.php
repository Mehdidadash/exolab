<?php
// panel/upload_case_files.php
// Separate file upload endpoint – receives files AFTER case is created
// (Workaround for hosting that clears $_POST on multipart requests with files)

require_once __DIR__ . '/auth.php';
require_login();

// مجوز، پس از مشخص‌شدن کیس و نقش بررسی می‌شود (پایین): مدیران/کارکنان/طراح مثل قبل؛
// و اکنون «پزشکِ صاحبِ کیس» هم می‌تواند برای کیس خودش فایل آپلود کند.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'method']);
    exit;
}

// CSRF check via header (POST may be unreliable on this hosting)
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionToken = $_SESSION['_csrf_token'] ?? '';
if (empty($sessionToken) || !hash_equals($sessionToken, $token)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'csrf_invalid']);
    exit;
}

$caseId = !empty($_POST['case_id']) ? (int) $_POST['case_id'] : 0;
// Fallback: hosting may strip $_POST on multipart – read from query string or header
if ($caseId <= 0) {
    $caseId = !empty($_GET['case_id']) ? (int) $_GET['case_id'] : 0;
}
if ($caseId <= 0) {
    $caseId = !empty($_SERVER['HTTP_X_CASE_ID']) ? (int) $_SERVER['HTTP_X_CASE_ID'] : 0;
}
if ($caseId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'missing_case_id']);
    exit;
}

// Verify the user may upload to this case.
$user = current_user();
$permUpload = has_role('admin') || has_permission('upload_design_files') || has_permission('upload_files') || has_permission('edit_cases');
if ($user['role'] === 'designer' && has_permission('upload_design_files')) {
    $check = db()->prepare('SELECT id FROM cases WHERE id = ? AND designer_id = ?');
    $check->execute([$caseId, (int) $user['id']]);
} elseif ($user['role'] === 'doctor') {
    // پزشک فقط برای کیس‌های خودش
    $check = db()->prepare('SELECT id FROM cases WHERE id = ? AND doctor_id = ?');
    $check->execute([$caseId, (int) $user['id']]);
} elseif ($permUpload) {
    $check = db()->prepare('SELECT id FROM cases WHERE id = ?');
    $check->execute([$caseId]);
} else {
    $check = null;
}
if (!$check || !$check->fetch()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'case_not_found', 'message' => 'کیس یافت نشد یا دسترسی ندارید.']);
    exit;
}

$uploadDir = ensure_uploads_dir('cases/' . $caseId) . '/';

$allowed = ['stl', 'ply', 'stp', 'step', 'obj', '3mf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'rar', 'zip', 'pdf',
    // فایل‌های خروجیِ دستگاه‌های اسکن که پزشک‌ها آپلود می‌کنند
    'matrix4', 'dentalproject', 'iftscan', 'dcm', 'dicom', 'txt', 'xml',
    // گزارش/طراحی HTML (هنگام نمایش به‌صورت متن سرو می‌شود تا اسکریپت اجرا نشود)
    'html', 'htm'];
$errors = [];
$uploaded = 0;

if (empty($_FILES['case_files'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'uploaded' => 0]);
    exit;
}

$files = $_FILES['case_files'];

// Optional description applied to all files uploaded in this request
$description = trim($_POST['description'] ?? '');
if ($description === '') {
    $description = trim($_GET['description'] ?? '');
}
$description = $description !== '' ? $description : null;

// نوع فایل: پیش‌فرض بر اساس نقش (طراح → طراحی نهایی، بقیه → اسکن خام)
$fileType = trim($_POST['file_type'] ?? '');
if ($fileType === '') {
    $fileType = trim($_GET['file_type'] ?? '');
}
if (!in_array($fileType, array_keys(caseFileTypeConfig()['options']), true)) {
    $fileType = caseFileTypeDefault($user);
}

/**
 * مسیرهای نسبیِ آپلودِ پوشه‌ای.
 * مرورگر هنگام انتخاب پوشه (webkitdirectory) برای هر فایل مسیرش را در
 * webkitRelativePath می‌گذارد و ما آن را در فیلد rel_paths[] می‌فرستیم.
 * ترتیب این آرایه همان ترتیب case_files[] است (وگرنه نادیده گرفته می‌شود).
 * هر مقدار با sanitizeRelPath() پاک‌سازی می‌شود و هرگز به مسیر واقعیِ دیسک نمی‌رسد.
 */
$relPathsRaw = $_POST['rel_paths'] ?? ($_GET['rel_paths'] ?? []);
if (!is_array($relPathsRaw)) {
    $relPathsRaw = [$relPathsRaw];
}
$relPaths = [];
foreach ($relPathsRaw as $rp) {
    $relPaths[] = sanitizeRelPath(is_string($rp) ? $rp : null);
}
// آرایهٔ خالی/ناقص → برای همهٔ فایل‌ها null (آپلود معمولیِ تکی)
$hasRelPaths = !empty(array_filter($relPaths, fn($p) => $p !== null));

// نام پوشهٔ ریشه (برای نام‌گذاری فایل ZIP و عنوان گروه)
$folderName = sanitizeRelPath((string) ($_POST['folder_name'] ?? ($_GET['folder_name'] ?? '')));
if ($folderName !== null) {
    $folderName = relPathRoot($folderName)['root'] ?? null;
}

/**
 * ─── نام‌گذاری خودکار فایل‌های «طراحی نهایی» ───
 * نام هر فایل با {شماره کیس}_{سایه}_{نام بیمار}_{شماره قبض}_ شروع می‌شود.
 * مثال: 1252_A2_Fatemeh-Khani_0242_scan.stl
 *
 * ⚠️ این پیشوند از یک تابع مشترک می‌آید (caseFilePrefixForUpload) تا با
 * صفحهٔ کیس‌ها و کتابخانه **دقیقاً یکسان** بماند.
 */
$namingPrefix = caseFilePrefixForUpload($caseId, $fileType);

// ─── Optional: package all selected files into a single ZIP ───
$compress = !empty($_POST['compress']) || !empty($_GET['compress']);
$fileCount = isset($files['name']) && is_array($files['name']) ? count($files['name']) : 0;

// سقف تعداد فایل در یک ارسال (برای پوشه‌های بزرگ)
if ($fileCount > uploadFolderMaxFiles() && $hasRelPaths) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'uploaded' => 0,
        'errors' => ['folder_too_many_files'],
        'error_messages' => [uploadErrorLabel('folder_too_many_files')],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($compress && $fileCount > 1) {
    // Readable filename: caseNo_patientNameFinglish_Shade_teethNumber.zip
    $caseInfo = db()->prepare('SELECT patient_name, shade, teeth FROM cases WHERE id = ?');
    $caseInfo->execute([$caseId]);
    $caseRow = $caseInfo->fetch() ?: [];
    $finglish = persian_to_finglish($caseRow['patient_name'] ?? '');
    $shade = trim((string) ($caseRow['shade'] ?? ''));
    $teeth = trim((string) ($caseRow['teeth'] ?? ''));
    // اگر آپلود از نوع «پوشه» باشد، نام پوشه هم به نام ZIP اضافه می‌شود
    $nameParts = array_filter([(string) $caseId, $finglish, $shade, $teeth, $folderName], function ($p) { return $p !== ''; });
    $zipBase = implode('_', $nameParts);
    $zipBase = preg_replace('/[<>:"\/\\|?*\x00-\x1F]+/u', '_', $zipBase);
    $zipBase = preg_replace('/_+/', '_', $zipBase);
    $zipBase = trim($zipBase, " _.");
    if ($zipBase === '') {
        $zipBase = 'case_' . $caseId;
    }
    // Dedup: if this ZIP display name already exists for the case, append _YYYYMMDD
    $zipName = uniqueCaseFileName($caseId, $zipBase . '.zip');
    $zipPath = $uploadDir . $zipName;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $errors[] = 'zip_open_failed';
    } else {
        $used = [];
        $added = 0;
        foreach ($files['error'] as $idx => $err) {
            if ($err !== UPLOAD_ERR_OK) {
                if ($err === UPLOAD_ERR_NO_FILE) continue;
                $errors[] = "upload_error_{$idx}_{$err}";
                continue;
            }
            $ext = strtolower(pathinfo($files['name'][$idx], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) {
                $errors[] = "ext_not_allowed_{$ext}";
                continue;
            }
            // نام داخل ZIP: اگر مسیر نسبی داریم (آپلود پوشه) ساختار پوشه‌ها حفظ می‌شود
            $base = basename($files['name'][$idx]);
            $name = $relPaths[$idx] ?? $base;
            $name = str_replace('\\', '/', (string) $name);
            // نام‌گذاری خودکار (همان تابع مشترک با مسیر فایل تکی) — فقط نام فایل، نه مسیر پوشه
            if ($namingPrefix !== '') {
                $dir = (str_contains($name, '/')) ? dirname($name) : '';
                $name = ($dir !== '' && $dir !== '.' ? $dir . '/' : '') . addCaseFilePrefix(basename($name), $namingPrefix);
            }
            if ($name === '' || str_contains($name, '..')) {
                $name = $base;
            }
            // فقط نام فایل در آخر مجاز است (اگر فایل تکراری بود، شماره اضافه می‌شود)
            $name = ltrim($name, '/');
            $i = 1;
            while (isset($used[$name])) {
                $p = pathinfo($base);
                $name = ($p['dirname'] !== '.' && $p['dirname'] !== '' ? rtrim($p['dirname'], '/') . '/' : '')
                      . $p['filename'] . " ($i)." . ($p['extension'] ?? '');
                $i++;
            }
            $used[$name] = true;
            $zip->addFile($files['tmp_name'][$idx], $name);
            $added++;
        }
        $zip->close();
        if ($added === 0) {
            $errors[] = 'no_compressible_files';
            @unlink($zipPath);
        } else {
            $size = @filesize($zipPath);
            if ($size === false) {
                $errors[] = 'zip_failed';
                @unlink($zipPath);
            } else {
                try {
                    $ins = db()->prepare('INSERT INTO case_files (case_id, filename, original_name, rel_path, description, file_type, mime, size, uploader_id, created_at) VALUES (?, ?, ?, ?, ?, ?, "application/zip", ?, ?, NOW())');
                    $ins->execute([$caseId, $zipName, $zipName, $folderName, $description, $fileType, $size, (int) ($user['id'] ?? 0)]);
                    $uploaded = 1;
                } catch (\Throwable $e) {
                    $errors[] = 'db_insert_error';
                    error_log("upload_case_files: zip DB insert failed: " . $e->getMessage());
                    @unlink($zipPath);
                }
            }
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    if ($uploaded > 0) {
        log_case_activity($caseId, 'file_upload', 'آپلود فایل ZIP: ' . $zipName . ' (نوع: ' . $fileType . ')' . ($folderName ? ' — از پوشه: ' . $folderName : ''));
        notifyCaseFileParticipants($caseId, (int) ($user['id'] ?? 0), $uploaded);
    }
    echo json_encode([
        'success' => empty($errors),
        'uploaded' => $uploaded,
        'errors' => $errors,
        'error_messages' => array_map('uploadErrorLabel', $errors),
        'compressed' => true
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

foreach ($files['error'] as $idx => $err) {
    if ($err !== UPLOAD_ERR_OK) {
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        $errors[] = "upload_error_{$idx}_{$err}";
        // err=3 (PARTIAL) معمولاً یعنی اتصال/حجم/زمانِ آپلود کافی نبوده؛ برای عیب‌یابی
        // نام و حجم فایل و محدودیت‌های PHP را هم ثبت می‌کنیم.
        $lim = uploadLimits();
        error_log(sprintf(
            'upload_case_files: upload error idx=%d err=%d file=%s size=%s max_file=%s post_max=%s',
            $idx,
            $err,
            (string) ($files['name'][$idx] ?? '?'),
            (string) ($files['size'][$idx] ?? 0),
            (string) $lim['max_file'],
            (string) $lim['post_max']
        ));
        continue;
    }
    $tmp = $files['tmp_name'][$idx];
    $orig = $files['name'][$idx];
    $size = (int) $files['size'][$idx];
    $mime = $files['type'][$idx] ?? '';
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    // مسیر نسبیِ همین فایل (اگر آپلود پوشه‌ای باشد) — فقط برای نمایش/گروه‌بندی
    $relPath = $relPaths[$idx] ?? null;

    if (!in_array($ext, $allowed)) {
        $errors[] = "ext_not_allowed_{$ext}";
        error_log("upload_case_files: extension $ext not allowed for $orig");
        continue;
    }

    // Dedup display name: if a file with the same name exists for this case, append _YYYYMMDD
    // نام‌گذاری خودکار (مشترک با صفحهٔ کیس‌ها — تابع واحد caseFileDisplayName)
    $displayName = caseFileDisplayName($caseId, $orig, $fileType, isFolderUploadFile($relPath));

    $safe = bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $uploadDir . $safe;

    if (move_uploaded_file($tmp, $dest)) {
        @chmod($dest, 0644);
        try {
            $ins = db()->prepare(
                'INSERT INTO case_files (case_id, filename, original_name, rel_path, description, file_type, mime, size, uploader_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $ins->execute([$caseId, $safe, $displayName, $relPath, $description, $fileType, $mime, $size, (int) ($user['id'] ?? 0)]);
            $uploaded++;
        } catch (\Throwable $e) {
            $errors[] = "db_insert_error";
            error_log("upload_case_files: DB insert failed for $orig: " . $e->getMessage());
        }
    } else {
        $errors[] = "move_failed_{$idx}";
        error_log("upload_case_files: move_uploaded_file failed for $orig to $dest");
    }
}

header('Content-Type: application/json; charset=utf-8');
if ($uploaded > 0) {
    $folderNote = ($hasRelPaths && $folderName) ? ' — از پوشه: ' . $folderName : '';
    log_case_activity($caseId, 'file_upload', 'آپلود ' . $uploaded . ' فایل (نوع: ' . $fileType . ')' . $folderNote);
    notifyCaseFileParticipants($caseId, (int) ($user['id'] ?? 0), $uploaded);
}
echo json_encode([
    'success' => empty($errors),
    'uploaded' => $uploaded,
    'partial' => ($uploaded > 0 && !empty($errors)),
    'errors' => $errors,
    'error_messages' => array_map('uploadErrorLabel', $errors),
    'limits' => uploadLimits(),
], JSON_UNESCAPED_UNICODE);
