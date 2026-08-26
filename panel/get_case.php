<?php
// panel/get_case.php
require_once __DIR__ . '/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
$doctorId = !empty($_GET['doctor_id']) ? (int) $_GET['doctor_id'] : null;
$uninvoicedOnly = !empty($_GET['uninvoiced']);
$id = !empty($_GET['id']) ? (int) $_GET['id'] : null;

// Mode 1: Get list of uninvoiced cases for a doctor (used by invoice_form.js)
if ($doctorId && $uninvoicedOnly) {
    if (!has_permission('view_all_cases') && $user['role'] !== 'doctor') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'forbidden']);
        exit;
    }
    // Only return cases that are delivered (status_id = 4) and not yet invoiced
    $stmt = db()->prepare(
        "SELECT c.*, p.title AS service_title
         FROM cases c
         LEFT JOIN site_prices p ON c.service_id = p.id
         WHERE c.doctor_id = ?
           AND c.invoice_id IS NULL
           AND c.status_id = 4
         ORDER BY c.received_date DESC"
    );
    $stmt->execute([$doctorId]);
    $cases = $stmt->fetchAll();
    echo json_encode($cases, JSON_UNESCAPED_UNICODE);
    exit;
}

// Mode 2: Get single case by ID (existing behavior)
if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_id']);
    exit;
}

if ($user['role'] === 'doctor') {
    $stmt = db()->prepare('SELECT * FROM cases WHERE id = ? AND doctor_id = ?');
    $stmt->execute([$id, $user['id']]);
} else {
    if (!has_permission('view_all_cases')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'forbidden']);
        exit;
    }
    $sql = 'SELECT * FROM cases c WHERE c.id = ?';
    $params = [$id];
    if (is_branch_scoped()) {
        $bScope = branchCaseScope('c');
        $sql .= ' AND ' . $bScope['sql'];
        $params = array_merge($params, $bScope['params']);
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
}

$case = $stmt->fetch();
if (!$case) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit;
}

$case['received_date'] = toJalaliDateFormatted($case['received_date']);
echo json_encode(['success' => true, 'case' => $case], JSON_UNESCAPED_UNICODE);