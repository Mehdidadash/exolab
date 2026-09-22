<?php
// panel/search_case_files.php
// جست‌وجوی فایل‌های کیس‌ها برای «اتصال به این کیس» (فایل مرتبط).
// فقط فایل‌های کیس‌هایی برمی‌گردد که کاربر اجازهٔ دیدنشان را دارد.
// GET: q (حداقل ۲ حرف)، exclude_case_id (کیس فعلی)، limit
require_once __DIR__ . '/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
$q = trim((string) ($_GET['q'] ?? ''));
$excludeCaseId = (int) ($_GET['exclude_case_id'] ?? 0);
$limit = (int) ($_GET['limit'] ?? 30);
if ($limit < 1 || $limit > 60) $limit = 30;

if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'results' => [], 'message' => 'برای جست‌وجو حداقل ۲ حرف وارد کنید.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── محدودهٔ دسترسی (هم‌ارز userCanViewCaseId ولی به‌صورت SQL برای جست‌وجوی گروهی) ──
$role = $user['role'] ?? '';
$scopeSql = '';
$scopeParams = [];
if ($role === 'doctor') {
    $scopeSql = 'c.doctor_id = ?';
    $scopeParams[] = (int) $user['id'];
} elseif ($role === 'clinic') {
    $sc = getClinicScope('c');
    $scopeSql = $sc['sql'];
    $scopeParams = $sc['params'];
} elseif (in_array($role, ['lab', 'outsource_lab', 'customer_lab', 'partner_lab'], true)) {
    // کارهای لابراتوارِ خودشان + کارهایی که (به‌عنوان طراح) به آن‌ها سپرده شده؛
    // یک کاربر می‌تواند هم‌زمان «لابراتوار برون‌سپاری» و «طراح» باشد.
    $scopeSql = '(c.lab_id = ? OR c.outsourced_lab_id = ? OR c.designer_id = ?)';
    $scopeParams[] = (int) $user['id'];
    $scopeParams[] = (int) $user['id'];
    $scopeParams[] = (int) $user['id'];
} elseif ($role === 'designer') {
    $scopeSql = 'c.designer_id = ?';
    $scopeParams[] = (int) $user['id'];
} elseif (has_permission('view_all_cases') || has_permission('view_assigned_cases') || has_permission('view_own_cases')) {
    $scopeBranch = currentBranchId();
    if ($scopeBranch === null && function_exists('is_root_admin') && is_root_admin()) $scopeBranch = 1;
    if ($scopeBranch !== null) {
        $sc = branchCaseScope('c', $scopeBranch);
        $scopeSql = $sc['sql'];
        $scopeParams = $sc['params'];
    } else {
        $scopeSql = '1=1';
    }
} else {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
    exit;
}

$like = '%' . $q . '%';
$where = [
    '(cf.original_name LIKE ? OR c.patient_name LIKE ? OR cf.description LIKE ? OR CAST(c.id AS CHAR) = ? OR CAST(cf.id AS CHAR) = ?)',
    $scopeSql,
];
$params = array_merge([$like, $like, $like, $q, $q], $scopeParams);

if ($excludeCaseId > 0) {
    // فایل‌های خودِ این کیس و فایل‌هایی که قبلاً به آن وصل شده‌اند نمایش داده نمی‌شوند
    $where[] = 'cf.case_id <> ?';
    $params[] = $excludeCaseId;
    $where[] = 'NOT EXISTS (SELECT 1 FROM case_file_case_links l WHERE l.case_file_id = cf.id AND l.case_id = ?)';
    $params[] = $excludeCaseId;
}

$sql = 'SELECT cf.id, cf.original_name, cf.size, cf.file_type, cf.description, cf.created_at, cf.uploader_id,
               c.id AS case_id, c.patient_name, c.received_date, c.case_type,
               up.full_name AS uploader_name
        FROM case_files cf
        JOIN cases c ON c.id = cf.case_id
        LEFT JOIN users up ON up.id = cf.uploader_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY cf.id DESC
        LIMIT ' . (int) $limit;

$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$results = [];
foreach ($rows as $r) {
    $ext = strtolower(pathinfo((string) $r['original_name'], PATHINFO_EXTENSION));
    $results[] = [
        'file_id' => (int) $r['id'],
        'name' => (string) $r['original_name'],
        'ext' => $ext,
        'type_label' => strip_tags(caseFileBadge($r['file_type'] ?? null, $ext)),
        'size' => !empty($r['size']) ? formatFileSize($r['size']) : '—',
        'description' => (string) ($r['description'] ?? ''),
        'case_id' => (int) $r['case_id'],
        'patient_name' => (string) ($r['patient_name'] ?? ''),
        'received' => !empty($r['received_date']) ? toJalaliDateFormatted($r['received_date']) : '—',
        'uploader' => (string) ($r['uploader_name'] ?? ''),
        'created' => !empty($r['created_at']) ? toJalaliDateTimeFormatted($r['created_at']) : '—',
        'url' => 'serve_case_file.php?id=' . (int) $r['id'] . '&n=' . rawurlencode((string) $r['original_name']),
        'download' => 'download_case_file.php?id=' . (int) $r['id'],
    ];
}

echo json_encode(['success' => true, 'results' => $results, 'count' => count($results)], JSON_UNESCAPED_UNICODE);
