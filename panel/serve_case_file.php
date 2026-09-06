<?php
// panel/serve_case_file.php
// نمایش/پیش‌نمایش درون‌خطی فایل کیس (عکس یا مدل سه‌بعدی) برای کسی که اجازه‌ی دیدن
// کیس را دارد. فایل‌ها بیرون از ریشه‌ی وب ذخیره می‌شوند، بنابراین با URL مستقیم
// قابل دسترسی نیستند و همه‌ی نمایش‌ها از اینجا سرو می‌شوند.

require_once __DIR__ . '/auth.php';
require_login();

$fileId = !empty($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$fileId) {
    http_response_code(400);
    die('درخواست نامعتبر');
}

$user = current_user();
$isDoctor = ($user['role'] === 'doctor');
$isDesigner = ($user['role'] === 'designer');

// همان قواعد دسترسیِ صفحه‌ی مشاهده کیس: دکتر فقط کیس خودش، طراح فقط کیس خودش،
// بقیه نیاز به مجوز view_all_cases + محدوده‌ی شعبه.
$sql = 'SELECT cf.*, c.doctor_id, c.designer_id FROM case_files cf JOIN cases c ON cf.case_id = c.id WHERE cf.id = ?';
$params = [$fileId];
if ($isDoctor) {
    $sql .= ' AND c.doctor_id = ?';
    $params[] = (int) $user['id'];
} elseif ($isDesigner) {
    $sql .= ' AND c.designer_id = ?';
    $params[] = (int) $user['id'];
} elseif (!has_permission('view_all_cases')) {
    http_response_code(403);
    die('دسترسی غیرمجاز');
}
if (is_branch_scoped()) {
    $bScope = branchCaseScope('c');
    $sql .= ' AND ' . $bScope['sql'];
    $params = array_merge($params, $bScope['params']);
}

$stmt = db()->prepare($sql);
$stmt->execute($params);
$file = $stmt->fetch();
if (!$file) {
    http_response_code(404);
    die('فایل یافت نشد');
}

$path = resolve_upload_path('cases/' . $file['case_id'] . '/' . $file['filename']);
if (!is_file($path)) {
    http_response_code(404);
    die('فایل روی سرور یافت نشد');
}

$ext = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
$mimes = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp', 'svg' => 'image/svg+xml',
    'stl' => 'model/stl', 'ply' => 'model/ply', 'obj' => 'model/obj',
    '3mf' => 'application/vnd.ms-package.3dmanufacturing-3dmodel+xml',
    'stp' => 'application/step', 'step' => 'application/step',
];
$ctype = $mimes[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $ctype);
header('Content-Disposition: inline; filename="' . basename($file['original_name'] ?? $file['filename']) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
