<?php
// panel/batch_update_designer.php
// Batch-change the designer of selected cases and recompute the design fee /
// total so financials stay correct.
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

// Permission: admins and roles that can edit cases
if (!has_role('admin') && !has_permission('edit_cases')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

$caseIds = $_POST['case_ids'] ?? [];
$designerId = !empty($_POST['designer_id']) ? (int) $_POST['designer_id'] : 0;

if (empty($caseIds) || !is_array($caseIds)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'invalid_params']);
    exit;
}

// Validate designer if provided
if ($designerId > 0) {
    $chk = db()->prepare('SELECT id FROM users WHERE id = ?');
    $chk->execute([$designerId]);
    if (!$chk->fetch()) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'invalid_designer']);
        exit;
    }
}

$updated = 0;
$errors = [];

$caseStmt = db()->prepare('SELECT * FROM cases WHERE id = ?');
$updStmt = db()->prepare('UPDATE cases SET designer_id = ?, design_fee = ?, total_price = ?, updated_at = NOW() WHERE id = ?');

foreach ($caseIds as $cid) {
    $cid = (int) $cid;
    if ($cid <= 0) continue;

    $caseStmt->execute([$cid]);
    $case = $caseStmt->fetch();
    if (!$case) {
        $errors[] = "کیس #{$cid} یافت نشد";
        continue;
    }

    // A case already billed in a designer invoice must not be re-assigned
    if (!empty($case['designer_invoice_id'])) {
        $errors[] = "کیس #{$cid} قبلاً در فاکتور طراحی شماره {$case['designer_invoice_id']} ثبت شده است";
        continue;
    }

    // Recompute design fee for the new designer
    $qty = (int) ($case['quantity'] ?? 1);
    $unitPrice = (float) ($case['unit_price'] ?? 0);
    if ($designerId > 0) {
        $unitFee = getApplicableDesignFee($designerId, (int) ($case['service_id'] ?? 0));
        $newDesignFee = $unitFee !== null ? round($unitFee * $qty) : 0;
    } else {
        $newDesignFee = 0;
    }
    // Total is the work price only – design fee is billed separately
    $newTotal = round($unitPrice * $qty);

    $updStmt->execute([$designerId > 0 ? $designerId : null, $newDesignFee, $newTotal, $cid]);
    $updated++;

    audit_log_save('case_designer', $cid, 'تغییر طراح کیس');
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => $updated > 0,
    'updated' => $updated,
    'errors' => $errors
], JSON_UNESCAPED_UNICODE);
