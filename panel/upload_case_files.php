<?php
// panel/upload_case_files.php
// Separate file upload endpoint – receives files AFTER case is created
// (Workaround for hosting that clears $_POST on multipart requests with files)

require_once __DIR__ . '/auth.php';
require_login();

// Permission: admins, and roles with file-upload / design-file permissions
if (!has_role('admin') && !has_permission('upload_design_files') && !has_permission('upload_files') && !has_permission('edit_cases')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

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

// Verify case exists and the user may access it
$user = current_user();
if ($user['role'] === 'designer') {
    $check = db()->prepare('SELECT id FROM cases WHERE id = ? AND designer_id = ?');
    $check->execute([$caseId, (int) $user['id']]);
} else {
    $check = db()->prepare('SELECT id FROM cases WHERE id = ?');
    $check->execute([$caseId]);
}
if (!$check->fetch()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'case_not_found', 'message' => 'کیس یافت نشد یا دسترسی ندارید.']);
    exit;
}

$uploadDir = rtrim(__DIR__ . '/../assets/uploads/cases/' . $caseId, '/') . '/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

$allowed = ['stl', 'ply', 'stp', 'step', 'obj', '3mf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'rar', 'zip'];
$errors = [];
$uploaded = 0;

if (empty($_FILES['case_files'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'uploaded' => 0]);
    exit;
}

$files = $_FILES['case_files'];

// ─── Optional: package all selected files into a single ZIP ───
$compress = !empty($_POST['compress']) || !empty($_GET['compress']);
$fileCount = isset($files['name']) && is_array($files['name']) ? count($files['name']) : 0;

if ($compress && $fileCount > 1) {
    // Readable filename: caseNo_patientNameFinglish_Shade_teethNumber.zip
    $caseInfo = db()->prepare('SELECT patient_name, shade, teeth FROM cases WHERE id = ?');
    $caseInfo->execute([$caseId]);
    $caseRow = $caseInfo->fetch() ?: [];
    $finglish = persian_to_finglish($caseRow['patient_name'] ?? '');
    $shade = trim((string) ($caseRow['shade'] ?? ''));
    $teeth = trim((string) ($caseRow['teeth'] ?? ''));
    $nameParts = array_filter([(string) $caseId, $finglish, $shade, $teeth], function ($p) { return $p !== ''; });
    $zipBase = implode('_', $nameParts);
    $zipBase = preg_replace('/[<>:"\/\\|?*\x00-\x1F]+/u', '_', $zipBase);
    $zipBase = preg_replace('/_+/', '_', $zipBase);
    $zipBase = trim($zipBase, " _.");
    if ($zipBase === '') {
        $zipBase = 'case_' . $caseId;
    }
    $zipName = $zipBase . '.zip';
    $zipPath = $uploadDir . $zipName;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $errors[] = 'zip_open_failed';
    } else {
        $used = [];
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
            // Keep the original filename inside the ZIP (avoid collisions)
            $base = basename($files['name'][$idx]);
            $name = $base;
            $i = 1;
            while (isset($used[$name])) {
                $p = pathinfo($base);
                $name = $p['filename'] . " ($i)." . ($p['extension'] ?? '');
                $i++;
            }
            $used[$name] = true;
            $zip->addFile($files['tmp_name'][$idx], $name);
        }
        $zip->close();
        $size = @filesize($zipPath);
        if ($size === false) {
            $errors[] = 'zip_failed';
            @unlink($zipPath);
        } else {
            try {
                $ins = db()->prepare('INSERT INTO case_files (case_id, filename, original_name, mime, size, created_at) VALUES (?, ?, ?, "application/zip", ?, NOW())');
                $ins->execute([$caseId, $zipName, $zipBase . '.zip', $size]);
                $uploaded = 1;
            } catch (\Throwable $e) {
                $errors[] = 'db_insert_error';
                error_log("upload_case_files: zip DB insert failed: " . $e->getMessage());
                @unlink($zipPath);
            }
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => empty($errors),
        'uploaded' => $uploaded,
        'errors' => $errors,
        'compressed' => true
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

foreach ($files['error'] as $idx => $err) {
    if ($err !== UPLOAD_ERR_OK) {
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        $errors[] = "upload_error_{$idx}_{$err}";
        error_log("upload_case_files: upload error idx=$idx err=$err");
        continue;
    }
    $tmp = $files['tmp_name'][$idx];
    $orig = $files['name'][$idx];
    $size = (int) $files['size'][$idx];
    $mime = $files['type'][$idx] ?? '';
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed)) {
        $errors[] = "ext_not_allowed_{$ext}";
        error_log("upload_case_files: extension $ext not allowed for $orig");
        continue;
    }

    $safe = bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $uploadDir . $safe;

    if (move_uploaded_file($tmp, $dest)) {
        @chmod($dest, 0644);
        try {
            $ins = db()->prepare(
                'INSERT INTO case_files (case_id, filename, original_name, mime, size, created_at) VALUES (?, ?, ?, ?, ?, NOW())'
            );
            $ins->execute([$caseId, $safe, $orig, $mime, $size]);
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
echo json_encode([
    'success' => empty($errors),
    'uploaded' => $uploaded,
    'errors' => $errors
], JSON_UNESCAPED_UNICODE);
