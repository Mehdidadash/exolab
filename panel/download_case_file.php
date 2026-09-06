<?php
// panel/download_case_file.php
// Serve a case file for download with permission checks

require_once __DIR__ . '/auth.php';
require_login();

$fileId = !empty($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$fileId) {
    http_response_code(400);
    die('درخواست نامعتبر');
}

$user = current_user();

// Fetch file record
$stmt = db()->prepare('SELECT cf.*, c.doctor_id, c.lab_id, c.designer_id FROM case_files cf JOIN cases c ON cf.case_id = c.id WHERE cf.id = ?');
$stmt->execute([$fileId]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    die('فایل یافت نشد');
}

// Permission check: doctor and clinic roles cannot download
if ($user['role'] === 'doctor' || $user['role'] === 'clinic') {
    http_response_code(403);
    die('شما مجاز به دانلود فایل نیستید');
}

// Check specific permissions for other roles
$isAdmin = has_permission('view_all_cases');
$isLab = in_array($user['role'] ?? '', ['lab', 'outsource_lab', 'customer_lab', 'partner_lab']);
$isDesigner = ($user['role'] === 'designer');

if (!$isAdmin) {
    if ($isLab && $file['lab_id'] != $user['id']) {
        http_response_code(403);
        die('دسترسی غیرمجاز');
    } elseif ($isDesigner && $file['designer_id'] != $user['id']) {
        http_response_code(403);
        die('دسترسی غیرمجاز');
    } elseif (!$isDesigner && !has_permission('view_case_files')) {
        http_response_code(403);
        die('دسترسی غیرمجاز');
    }
}

$caseId = $file['case_id'];
$filename = $file['filename'];
$originalName = $file['original_name'];
$filePath = resolve_upload_path('cases/' . $caseId . '/' . $filename);

if (!file_exists($filePath)) {
    http_response_code(404);
    die('فایل در سرور یافت نشد');
}

// ثبت لاگ دانلود فایل (چه کسی / چه زمانی / کدام فایل / چه نوعی)
$dlType = 'raw';
if (($file['file_type'] ?? '') === 'final_design') {
    $dlType = 'design';
} elseif (in_array($file['file_type'] ?? null, [null, ''], true) && strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) === 'rar') {
    $dlType = 'design';
}
log_case_activity($caseId, 'file_download', json_encode(['file_id' => $fileId, 'file' => $originalName, 'type' => $dlType], JSON_UNESCAPED_UNICODE));

// Serve the file for download
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $originalName . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=0');
header('Pragma: public');

readfile($filePath);
exit;
