<?php
// panel/update_case_status.php
// Change the status of a single case, enforcing per-role allowed statuses.
require_once __DIR__ . '/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'method']);
    exit;
}

// CSRF via header or POST field
$sessionToken = $_SESSION['_csrf_token'] ?? '';
$token = $_POST['_csrf_token'] ?? '';
if (empty($token)) {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
}
if (empty($sessionToken) || !hash_equals($sessionToken, $token)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'csrf_invalid']);
    exit;
}

// Permission: admins, and users with edit_case_status / update_case_status
if (!has_role('admin') && !has_permission('edit_case_status') && !has_permission('update_case_status')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

$caseId = !empty($_POST['case_id']) ? (int) $_POST['case_id'] : 0;
$statusId = !empty($_POST['status_id']) ? (int) $_POST['status_id'] : 0;

if ($caseId <= 0 || $statusId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'invalid_params']);
    exit;
}

// Verify status exists
$checkStatus = db()->prepare('SELECT id FROM case_statuses WHERE id = ?');
$checkStatus->execute([$statusId]);
if (!$checkStatus->fetch()) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'invalid_status']);
    exit;
}

// Enforce per-role allowed statuses
if (!canUserSetStatus($statusId)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'status_not_allowed', 'message' => 'شما مجاز به تنظیم این وضعیت نیستید.']);
    exit;
}

// Scope the case to the current user
$user = current_user();
$scope = '';
$scopeParams = [];
if ($user['role'] === 'designer') {
    $scope = ' AND designer_id = ?';
    $scopeParams[] = (int) $user['id'];
} elseif ($user['role'] === 'doctor') {
    $scope = ' AND doctor_id = ?';
    $scopeParams[] = (int) $user['id'];
} elseif ($user['role'] === 'clinic') {
    $clinicScope = getClinicScope('c');
    $scope = ' AND ' . $clinicScope['sql'];
    $scopeParams = $clinicScope['params'];
} elseif ($user['role'] === 'lab' || $user['role'] === 'outsource_lab' || $user['role'] === 'customer_lab' || $user['role'] === 'partner_lab') {
    $scope = ' AND lab_id = ?';
    $scopeParams[] = (int) $user['id'];
}

$check = db()->prepare('SELECT id FROM cases c WHERE c.id = ?' . $scope);
$check->execute(array_merge([$caseId], $scopeParams));
if (!$check->fetch()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'not_found', 'message' => 'کیس یافت نشد یا دسترسی ندارید.']);
    exit;
}

try {
    $stmt = db()->prepare('UPDATE cases SET status_id = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$statusId, $caseId]);

    audit_log_save('case_status', $caseId, 'تغییر وضعیت کیس');

    $statusName = db()->prepare('SELECT name FROM case_statuses WHERE id = ?');
    $statusName->execute([$statusId]);
    log_case_activity($caseId, 'status_change', 'تغییر وضعیت به: ' . ($statusName->fetchColumn() ?: '#' . $statusId));

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'case_id' => $caseId, 'status_id' => $statusId], JSON_UNESCAPED_UNICODE);
    exit;
} catch (\Throwable $e) {
    error_log('update_case_status error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}
