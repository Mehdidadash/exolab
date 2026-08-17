<?php
// panel/batch_update_status.php
// Batch update case statuses for selected case IDs

require_once __DIR__ . '/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'method']);
    exit;
}

// CSRF via header
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionToken = $_SESSION['_csrf_token'] ?? '';
if (empty($sessionToken) || !hash_equals($sessionToken, $token)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'csrf_invalid']);
    exit;
}

// Check permission
if (!has_permission('update_case_status') && !has_permission('edit_cases') && !has_role('admin')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

$caseIds = $_POST['case_ids'] ?? [];
$statusId = !empty($_POST['status_id']) ? (int) $_POST['status_id'] : 0;

if (empty($caseIds) || !is_array($caseIds) || !$statusId) {
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

$user = current_user();
$isAdmin = has_permission('view_all_cases');
$isDoctor = ($user['role'] === 'doctor');
$doctorId = $isDoctor ? $user['id'] : null;

$updated = 0;
$errors = [];

foreach ($caseIds as $cid) {
    $cid = (int) $cid;
    if ($cid <= 0) continue;

    // Check access
    if ($isDoctor) {
        $check = db()->prepare('SELECT id FROM cases WHERE id = ? AND doctor_id = ?');
        $check->execute([$cid, $doctorId]);
    } elseif (!$isAdmin) {
        $check = db()->prepare('SELECT id FROM cases WHERE id = ?');
        $check->execute([$cid]);
    }
    // For admin, no check needed

    if (!$isAdmin && !$isDoctor) {
        // For other roles, check if they have access
        $check = db()->prepare('SELECT id FROM cases WHERE id = ?');
        $check->execute([$cid]);
    }

    if (!$isAdmin && !$check->fetch()) {
        $errors[] = "کیس #{$cid} یافت نشد یا دسترسی ندارید";
        continue;
    }

    try {
        $stmt = db()->prepare('UPDATE cases SET status_id = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$statusId, $cid]);
        $updated++;
    } catch (\Throwable $e) {
        $errors[] = "خطا در بروزرسانی کیس #{$cid}";
        error_log("batch_update_status: error updating case $cid: " . $e->getMessage());
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => $updated > 0,
    'updated' => $updated,
    'errors' => $errors
], JSON_UNESCAPED_UNICODE);
