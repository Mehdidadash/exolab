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

$sessionToken = $_SESSION['_csrf_token'] ?? '';
// Accept token from POST field OR X-CSRF-Token header (hosting security filters may strip POST field)
$token = $_POST['_csrf_token'] ?? '';
if (empty($token)) {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
}

if (empty($sessionToken) || !hash_equals($sessionToken, $token)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'csrf_invalid',
        'message' => 'CSRF token نامعتبر. لطفاً صفحه را رفرش کنید و دوباره تلاش کنید.'
    ]);
    exit;
}

$data = $_POST;
$received_date = parseJalaliToGregorian($data['received_date'] ?? '');

$id = !empty($data['id']) ? (int)$data['id'] : null;
$doctor_id = !empty($data['doctor_id']) ? (int)$data['doctor_id'] : null;
$patient_name = trim($data['patient_name'] ?? '');
$receipt_number = !empty($data['receipt_number']) ? trim($data['receipt_number']) : null;
$service_id = !empty($data['service_id']) ? (int)$data['service_id'] : null;
$location_type = trim($data['location_type'] ?? '');
$teeth = trim($data['teeth'] ?? '');
$shade = trim($data['shade'] ?? '');
$quantity = !empty($data['quantity']) ? (int)$data['quantity'] : 1;
$unit_price = !empty($data['unit_price']) ? (float)$data['unit_price'] : 0;
$total_price = $quantity * $unit_price;
$received_date = parseJalaliToGregorian($data['received_date'] ?? '');
if ($received_date === '') {
    $received_date = parseDateInput($data['received_date'] ?? '');
    if ($received_date === '') {
        $received_date = date('Y-m-d');
    }
}
$status_id = !empty($data['status_id']) ? (int)$data['status_id'] : null;
$lab_id = !empty($data['lab_id']) ? (int)$data['lab_id'] : null;
$description = trim($data['description'] ?? '');
$parent_id = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
$designer_id = !empty($data['designer_id']) ? (int)$data['designer_id'] : null;

if (empty($patient_name)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'validation',
        'message' => 'نام بیمار الزامی است'
    ]);
    exit;
}

/**
 * Upload case files to the server
 * @return array List of errors (empty if all OK)
 */
function handleCaseFileUploads(int $caseId, array $files): array
{
    $errors = [];
    if (empty($files) || empty($files['name'])) return $errors;

    $uploadDir = rtrim(__DIR__ . '/../assets/uploads/cases/' . $caseId, '/') . '/';

    // Try to create directory
    if (!is_dir($uploadDir)) {
        $mkdirResult = @mkdir($uploadDir, 0755, true);
        if (!$mkdirResult && !is_dir($uploadDir)) {
            $errors[] = "cannot_create_dir";
            error_log("save_case: failed to create $uploadDir");
            return $errors;
        }
    }

    // Allowed extensions: 3D files + common image formats
    $allowed = ['stl', 'ply', 'stp', 'step', 'obj', '3mf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'rar', 'zip'];

    foreach ($files['error'] as $idx => $err) {
        if ($err !== UPLOAD_ERR_OK) {
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            $errors[] = "upload_error_{$idx}_{$err}";
            error_log("save_case: upload error idx=$idx err=$err");
            continue;
        }
        $tmp = $files['tmp_name'][$idx];
        $orig = $files['name'][$idx];
        $size = (int) $files['size'][$idx];
        $mime = $files['type'][$idx] ?? '';
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed)) {
            $errors[] = "ext_not_allowed_{$ext}";
            error_log("save_case: extension $ext not allowed for $orig");
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
            } catch (\Throwable $e) {
                $errors[] = "db_insert_error";
                error_log("save_case: DB insert failed for $orig: " . $e->getMessage());
            }
        } else {
            $errors[] = "move_failed_{$idx}";
            error_log("save_case: move_uploaded_file failed for $orig to $dest");
        }
    }

    return $errors;
}

try {
    if ($id) {
        $sql = 'UPDATE cases SET doctor_id = ?, patient_name = ?, receipt_number = ?, service_id = ?, location_type = ?, teeth = ?, shade = ?, quantity = ?, unit_price = ?, total_price = ?, received_date = ?, status_id = ?, lab_id = ?, designer_id = ?, description = ?, parent_id = ?, updated_at = NOW() WHERE id = ?';
        $params = [$doctor_id, $patient_name, $receipt_number, $service_id, $location_type, $teeth, $shade, $quantity, $unit_price, $total_price, $received_date, $status_id, $lab_id, $designer_id, $description, $parent_id, $id];
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $caseId = $id;
    } else {
        $sql = 'INSERT INTO cases (parent_id, doctor_id, patient_name, receipt_number, service_id, location_type, teeth, shade, quantity, unit_price, total_price, received_date, status_id, lab_id, designer_id, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';
        $params = [$parent_id, $doctor_id, $patient_name, $receipt_number, $service_id, $location_type, $teeth, $shade, $quantity, $unit_price, $total_price, $received_date, $status_id, $lab_id, $designer_id, $description];
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $caseId = (int) db()->lastInsertId();
    }

    // Handle file uploads
    $uploadErrors = [];
    if (!empty($_FILES['case_files'])) {
        $uploadErrors = handleCaseFileUploads($caseId, $_FILES['case_files']);
    }

    // Notifications for lab & designer assignment
    if ($lab_id) {
        $caseTitle = $patient_name ?: 'کیس #' . $caseId;
        createNotification($lab_id, "کیس جدید: {$caseTitle}", "یک کیس جدید برای شما به عنوان لابراتوار ثبت شده است.", $caseId, 'assignment');
    }
    if ($designer_id) {
        $caseTitle = $patient_name ?: 'کیس #' . $caseId;
        createNotification($designer_id, "کیس جدید: {$caseTitle}", "یک کیس جدید برای شما به عنوان طراح ثبت شده است.", $caseId, 'assignment');
    }

    header('Content-Type: application/json; charset=utf-8');
    $response = ['success' => true, 'id' => $caseId];
    if (!empty($uploadErrors)) {
        $response['upload_errors'] = $uploadErrors;
        $response['message'] = 'کیس ذخیره شد اما برخی فایل‌ها آپلود نشدند.';
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    error_log("save_case EXCEPTION: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
    exit;
}
