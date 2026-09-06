<?php
// panel\delete_case.php

require_once __DIR__ . '/auth.php';
require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

$id = $_POST['id'] ?? null;
$token = $_POST['_csrf_token'] ?? '';
if (empty($token)) {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
}

if (empty($id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_id']);
    exit;
}

if (empty($_SESSION['_csrf_token']) || $token !== $_SESSION['_csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'csrf_invalid']);
    exit;
}

try {
    $id = (int) $id;
    $pdo = db();

    // Case must exist
    $caseStmt = $pdo->prepare('SELECT id FROM cases WHERE id = ?');
    $caseStmt->execute([$id]);
    if (!$caseStmt->fetch()) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'not_found']);
        exit;
    }

    // 1) Delete case files (DB rows + physical files)
    $files = $pdo->prepare('SELECT * FROM case_files WHERE case_id = ?');
    $files->execute([$id]);
    foreach ($files->fetchAll() as $f) {
        $path = resolve_upload_path('cases/' . $id . '/' . $f['filename']);
        if (is_file($path)) @unlink($path);
        $legacy = legacy_uploads_path('cases/' . $id . '/' . $f['filename']);
        if ($legacy !== $path && is_file($legacy)) @unlink($legacy);
    }
    $pdo->prepare('DELETE FROM case_files WHERE case_id = ?')->execute([$id]);
    // پاک‌سازی پوشه در هر دو مکان (جدید و قدیمی)
    foreach ([uploads_path('cases/' . $id), legacy_uploads_path('cases/' . $id)] as $caseUploadDir) {
        if (is_dir($caseUploadDir)) {
            $leftover = glob($caseUploadDir . '/*');
            if (empty($leftover)) @rmdir($caseUploadDir);
        }
    }

    // 2) Remove this case's line-items from payable invoices and recompute invoice totals.
    //    Deleting the case removes its design fee + side-outsourcing costs everywhere.
    $recompute = function (string $itemTable, string $invoiceTable) use ($pdo, $id) {
        $affected = [];
        $s = $pdo->prepare("SELECT DISTINCT invoice_id FROM {$itemTable} WHERE case_id = ?");
        $s->execute([$id]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $iid) {
            if ($iid) $affected[] = (int) $iid;
        }
        $pdo->prepare("DELETE FROM {$itemTable} WHERE case_id = ?")->execute([$id]);
        foreach ($affected as $iid) {
            $sum = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM {$itemTable} WHERE invoice_id = ?");
            $sum->execute([$iid]);
            $pdo->prepare("UPDATE {$invoiceTable} SET total_amount = ? WHERE id = ?")
                ->execute([(float) $sum->fetchColumn(), $iid]);
        }
    };
    $recompute('designer_invoice_items', 'designer_invoices');   // design fees
    $recompute('outsource_invoice_items', 'outsource_invoices'); // outsourcing costs
    $recompute('invoice_items', 'doctor_invoices');              // doctor revenue invoices

    // 3) Delete case comments
    $pdo->prepare("DELETE FROM entity_comments WHERE entity_type = 'case' AND entity_id = ?")->execute([$id]);

    // 4) Delete the case itself
    $stmt = $pdo->prepare('DELETE FROM cases WHERE id = ?');
    $stmt->execute([$id]);

    audit_log('delete_case', 'case', $id, 'حذف کیس #' . $id);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}
