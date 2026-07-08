<?php
// panel\save_case.php

require_once __DIR__ . '/auth.php';
require_role('admin');

// Accepts POST for create/update; returns JSON
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'method']);
    exit;
}

$token = $_POST['_csrf_token'] ?? '';
if (empty($_SESSION['_csrf_token']) || $token !== $_SESSION['_csrf_token']) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'csrf_invalid']);
    exit;
}

$data = $_POST;
// Debug: log the received date
error_log('Received date raw: ' . ($data['received_date'] ?? 'NULL'));
$received_date = parseJalaliToGregorian($data['received_date'] ?? '');
error_log('Parsed date: ' . $received_date);

$id = !empty($data['id']) ? (int)$data['id'] : null;
$doctor_id = !empty($data['doctor_id']) ? (int)$data['doctor_id'] : null;
$patient_name = trim($data['patient_name'] ?? '');
$service_id = !empty($data['service_id']) ? (int)$data['service_id'] : null;
$location_type = trim($data['location_type'] ?? '');
$teeth = trim($data['teeth'] ?? '');
$shade = trim($data['shade'] ?? '');
$quantity = !empty($data['quantity']) ? (int)$data['quantity'] : 1;
$unit_price = !empty($data['unit_price']) ? (float)$data['unit_price'] : 0;
$total_price = $quantity * $unit_price;
$received_date = parseJalaliToGregorian($data['received_date'] ?? '');
if ($received_date === '') {
    // Try fallback using parseDateInput (maybe it's in another format)
    $received_date = parseDateInput($data['received_date'] ?? '');
    if ($received_date === '') {
        $received_date = date('Y-m-d');
    }
}
$status_id = !empty($data['status_id']) ? (int)$data['status_id'] : null;
$description = trim($data['description'] ?? '');

if (empty($patient_name)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'validation', 'message' => 'نام بیمار الزامی است']);
    exit;
}

try {
    if ($id) {
        $sql = 'UPDATE cases SET doctor_id = ?, patient_name = ?, service_id = ?, location_type = ?, teeth = ?, shade = ?, quantity = ?, unit_price = ?, total_price = ?, received_date = ?, status_id = ?, description = ?, updated_at = NOW() WHERE id = ?';
        $params = [$doctor_id, $patient_name, $service_id, $location_type, $teeth, $shade, $quantity, $unit_price, $total_price, $received_date, $status_id, $description, $id];
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $caseId = $id;
        // handle file uploads
        if (!empty($_FILES['case_files'])) {
            $uploadDir = __DIR__ . '/../assets/uploads/cases/' . $caseId . '/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
            foreach ($_FILES['case_files']['error'] as $idx => $err) {
                if ($err !== UPLOAD_ERR_OK) continue;
                $tmp = $_FILES['case_files']['tmp_name'][$idx];
                $orig = $_FILES['case_files']['name'][$idx];
                $size = (int) $_FILES['case_files']['size'][$idx];
                $mime = $_FILES['case_files']['type'][$idx] ?? '';
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                if (!in_array($ext, ['stl','ply'])) continue;
                $safe = bin2hex(random_bytes(8)) . '.' . $ext;
                if (move_uploaded_file($tmp, $uploadDir . $safe)) {
                    $ins = db()->prepare('INSERT INTO case_files (case_id, filename, original_name, mime, size, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
                    $ins->execute([$caseId, $safe, $orig, $mime, $size]);
                }
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'id' => $caseId]);
        exit;
    } else {
        $sql = 'INSERT INTO cases (doctor_id, patient_name, service_id, location_type, teeth, shade, quantity, unit_price, total_price, received_date, status_id, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';
        $params = [$doctor_id, $patient_name, $service_id, $location_type, $teeth, $shade, $quantity, $unit_price, $total_price, $received_date, $status_id, $description];
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $newId = (int) db()->lastInsertId();
        // handle initial file uploads
        if (!empty($_FILES['case_files'])) {
            $uploadDir = __DIR__ . '/../assets/uploads/cases/' . $newId . '/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
            foreach ($_FILES['case_files']['error'] as $idx => $err) {
                if ($err !== UPLOAD_ERR_OK) continue;
                $tmp = $_FILES['case_files']['tmp_name'][$idx];
                $orig = $_FILES['case_files']['name'][$idx];
                $size = (int) $_FILES['case_files']['size'][$idx];
                $mime = $_FILES['case_files']['type'][$idx] ?? '';
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                if (!in_array($ext, ['stl','ply'])) continue;
                $safe = bin2hex(random_bytes(8)) . '.' . $ext;
                if (move_uploaded_file($tmp, $uploadDir . $safe)) {
                    $ins = db()->prepare('INSERT INTO case_files (case_id, filename, original_name, mime, size, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
                    $ins->execute([$newId, $safe, $orig, $mime, $size]);
                }
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'id' => $newId]);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}
