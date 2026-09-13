<?php
// panel\save_case.php

require_once __DIR__ . '/auth.php';
require_login();

// Accepts POST for create/update; returns JSON
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'method']);
    exit;
}

// Permission check: creating requires create_cases, editing requires edit_cases
$editingCase = !empty($_POST['id']);
if ($editingCase) {
    if (!has_role('admin') && !has_permission('edit_cases')) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'forbidden', 'message' => 'شما مجوز ویرایش کیس را ندارید.']);
        exit;
    }
} else {
    if (!has_role('admin') && !has_permission('create_cases')) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'forbidden', 'message' => 'شما مجوز ایجاد کیس را ندارید.']);
        exit;
    }
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
// Normalize teeth: convert Persian digits + Persian comma/separators to the
// ASCII format the teeth picker uses (e.g. "26،42_43_44_45" → "26,42_43_44_45").
$teeth = normalizePersianDigits(trim($data['teeth'] ?? ''));
$teeth = str_replace(['،', ';'], ',', $teeth);
$shade = trim($data['shade'] ?? '');
$quantity = !empty($data['quantity']) ? (int)$data['quantity'] : 1;
$unit_price = !empty($data['unit_price']) ? (float)$data['unit_price'] : 0;
$design_fee = !empty($data['design_fee']) ? (float)$data['design_fee'] : 0;
// Total is the work price only – the design fee is billed separately
$total_price = $quantity * $unit_price;
$received_date = parseJalaliToGregorian($data['received_date'] ?? '');
if ($received_date === '') {
    $received_date = parseDateInput($data['received_date'] ?? '');
    if ($received_date === '') {
        if ($id) {
            // ویرایش بدون ارسال تاریخ: مقدار ثبت‌شده در دیتابیس حفظ شود (نه «امروز»)
            $ex = db()->prepare('SELECT received_date FROM cases WHERE id = ?');
            $ex->execute([$id]);
            $received_date = (string) ($ex->fetchColumn() ?: '');
            if ($received_date === '') {
                $received_date = date('Y-m-d');
            }
        } else {
            $received_date = date('Y-m-d');
        }
    }
}
$status_id = !empty($data['status_id']) ? (int)$data['status_id'] : null;
$lab_id = !empty($data['lab_id']) ? (int)$data['lab_id'] : null;
$case_type = $data['case_type'] ?? 'doctor';
if (!in_array($case_type, ['doctor', 'lab_in', 'lab_out'])) $case_type = 'doctor';
$description = trim($data['description'] ?? '');
$parent_id = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
$designer_id = !empty($data['designer_id']) ? (int)$data['designer_id'] : null;
// If no designer chosen (e.g. partner lab / branch creating a case), auto-assign
// the DEFAULT designer (is_default_designer=1) so a designer is always present.
if (!$designer_id && !$editingCase) {
    $defaultDesigner = getDefaultDesigner();
    if ($defaultDesigner) {
        $designer_id = (int) $defaultDesigner['id'];
    }
}
// Side outsourcing: part of this case's work is performed by another lab (we owe them)
$outsourced_lab_id = !empty($data['outsourced_lab_id']) ? (int)$data['outsourced_lab_id'] : null;
$outsourced_service_id = !empty($data['outsourced_service_id']) ? (int)$data['outsourced_service_id'] : null;
$outsourced_qty = !empty($data['outsourced_qty']) ? (int)$data['outsourced_qty'] : 0;
if ($outsourced_qty < 0) $outsourced_qty = 0;
// Per-case side outsourcing rate. NULL means "use the outsource_rates table at billing time".
$outsourced_rate = (isset($data['outsourced_rate']) && $data['outsourced_rate'] !== '')
    ? (float) $data['outsourced_rate']
    : null;
if ($outsourced_rate !== null && $outsourced_rate < 0) $outsourced_rate = 0;
// If no lab or service selected, treat as no side outsourcing
if (!$outsourced_lab_id || !$outsourced_service_id || $outsourced_qty <= 0) {
    $outsourced_lab_id = null;
    $outsourced_service_id = null;
    $outsourced_qty = 0;
    $outsourced_rate = null;
}

// Doctors create only their own 'doctor' type cases via the restricted form
$currentUser = current_user();
if (!$editingCase && $currentUser['role'] === 'doctor') {
    $case_type = 'doctor';
    $doctor_id = (int) $currentUser['id'];
    $lab_id = null;
    $designer_id = null;
    $received_date = date('Y-m-d');
    $statusSt = db()->query("SELECT id FROM case_statuses WHERE name = 'ثبت شد' ORDER BY id LIMIT 1")->fetchColumn();
    $status_id = $statusSt ? (int) $statusSt : 1;
    // Force the unit price to the doctor's applicable price for the service
    if ($service_id) {
        $forcedPrice = getApplicablePrice($doctor_id, $service_id);
        if ($forcedPrice !== null) {
            $unit_price = $forcedPrice;
            $total_price = $quantity * $unit_price;
        }
    }
}

// ─── اطلاعات کیسِ فعلی (برای ویرایش) ───
// در ویرایش، بعضی مقادیر ممکن است در فرمِ ویرایش‌کننده قابل انتخاب نباشند (لیست پزشک،
// لابراتوار و طراح بر اساس شعبه/فعال‌بودن فیلتر می‌شوند). برای اینکه داده‌ی کیس
// ناخواسته پاک نشود، مقدار فعلیِ کیس را این‌جا داریم.
$existingCaseRow = null;
if ($id) {
    $exRow = db()->prepare('SELECT doctor_id, service_id, lab_id, case_type, branch_id, source_branch_id FROM cases WHERE id = ?');
    $exRow->execute([$id]);
    $existingCaseRow = $exRow->fetch() ?: null;
}

// اگر فرم، لابراتوارِ کیس را همراه نداشت (گزینه در لیستِ فیلترشده نبود) ولی نوع کیس
// هنوز لابراتواری است، لابراتوارِ قبلی حفظ می‌شود. (پاک شدن lab_id باعث می‌شد کیس از
// لیست شعبهٔ شریک ناپدید شود — گزارش‌شده روی هاست.)
if ($existingCaseRow
    && empty($lab_id)
    && !empty($existingCaseRow['lab_id'])
    && in_array($case_type, ['lab_in', 'lab_out'], true)
    && (string) ($existingCaseRow['case_type'] ?? '') === (string) $case_type) {
    $lab_id = (int) $existingCaseRow['lab_id'];
}

// ─── Branch assignment ───
// branch_id = the branch that OWNS the case (whose books it is in).
// source_branch_id = the partner branch that sent us the work (lab_in) or
//                    that we're outsourcing to (lab_out / side outsourcing).
// در ویرایش، مالکِ کیس همان شعبه‌ای است که کیس به آن تعلق دارد (نه شعبهٔ ویرایش‌کننده).
// بدون این، ویرایشِ کیسِ بین‌شعبه‌ای (مثلاً مدیر شعبهٔ مرکزی روی کیسِ قزوین) باعث
// NULL شدنِ source_branch_id می‌شد و کیس از لیستِ شعبهٔ شریک ناپدید می‌گشت
// (باگِ گزارش‌شده روی هاست، بدون هیچ خطا).
$caseOwnerBranch = $existingCaseRow ? (int) ($existingCaseRow['branch_id'] ?? 0) : 0;
$editorBranch = currentBranchId() ?? 1;   // root admin defaults to main branch (1)
$branch_id = $caseOwnerBranch > 0 ? $caseOwnerBranch : $editorBranch;
$source_branch_id = null;

if ($lab_id) {
    // The lab user's branch = the partner branch involved
    $labBranch = db()->prepare('SELECT branch_id FROM users WHERE id = ?');
    $labBranch->execute([(int) $lab_id]);
    $lb = $labBranch->fetchColumn();
    if ($lb && (int) $lb !== (int) $branch_id) {
        if ($case_type === 'lab_in') {
            // We received work FROM this lab's branch → they are the source
            $source_branch_id = (int) $lb;
        } elseif ($case_type === 'lab_out') {
            // We outsourced work TO this lab's branch → they are the source (shared case)
            $source_branch_id = (int) $lb;
        }
    }
} elseif (!empty($data['outsourced_lab_id']) && $outsourced_qty > 0) {
    $labBranch = db()->prepare('SELECT branch_id FROM users WHERE id = ?');
    $labBranch->execute([(int) $data['outsourced_lab_id']]);
    $lb = $labBranch->fetchColumn();
    if ($lb && (int) $lb !== (int) $branch_id) {
        $source_branch_id = (int) $lb;
    }
}

// A branch-scoped user cannot CREATE a case owned by another branch.
if (!$id && is_branch_scoped() && $branch_id !== currentBranchId()) {
    $branch_id = currentBranchId();
}

// ویرایشگرِ کیسِ بین‌شعبه‌ای مالکِ کیس نیست؛ اگر با محاسبهٔ بالا هم پیوندِ شعبهٔ همکار
// به‌دست نیامد، مقدار ثبت‌شدهٔ قبلی حفظ می‌شود تا کیس از لیستِ شعبهٔ شریک حذف نشود.
$isCrossBranchEdit = (bool) ($id && $caseOwnerBranch > 0 && $caseOwnerBranch !== $editorBranch);
if ($isCrossBranchEdit && $source_branch_id === null && ($existingCaseRow['source_branch_id'] ?? null) !== null) {
    $source_branch_id = (int) $existingCaseRow['source_branch_id'];
}

// ─── جلوگیری از برون‌سپاری به لابراتوارِ خودِ شعبه ───
// (یک شعبه نه به خودش می‌تواند کار بدهد نه از خودش بگیرد)
// این قاعده مربوط به «شعبهٔ مالکِ کیس» است؛ ویرایشگرِ کیسِ بین‌شعبه‌ای (مثلاً
// لابراتوارِ مقصد که مالک کیس نیست) نباید با این محدودیت متوقف شود.
$effBranchGuard = $isCrossBranchEdit ? null : $editorBranch;
if (!$isCrossBranchEdit && currentBranchId() === null && function_exists('is_root_admin') && is_root_admin()) {
    $effBranchGuard = 1; // مدیر کل = شعبهٔ مرکزی
}
if ($effBranchGuard !== null) {
    $labBranchOf = function ($labUserId) {
        if (!$labUserId) return null;
        $s = db()->prepare('SELECT branch_id FROM users WHERE id = ?');
        $s->execute([(int) $labUserId]);
        $v = $s->fetchColumn();
        return ($v === null || $v === '') ? null : (int) $v;
    };
    if (in_array($case_type, ['lab_out', 'lab_in'], true) && $lab_id) {
        $lb = $labBranchOf($lab_id);
        if ($lb !== null && $lb === (int) $effBranchGuard) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'self_lab', 'message' => 'یک شعبه نمی‌تواند به لابراتوارِ خودِ شعبه برون‌سپاری کند.']);
            exit;
        }
    }
    if ($outsourced_lab_id && $outsourced_qty > 0) {
        $lb = $labBranchOf($outsourced_lab_id);
        if ($lb !== null && $lb === (int) $effBranchGuard) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'self_lab', 'message' => 'برون‌سپاری جانبی نمی‌تواند به لابراتوارِ خودِ شعبه باشد.']);
            exit;
        }
    }
}

// ─── اعتبارسنجی کلیدهای خارجی (پزشک / خدمت) ───
// ممکن است فرمِ ویرایش، پزشکِ کیس را در لیستِ خود نداشته باشد (لیست پزشکان بر اساس
// شعبه و دسترسی فیلتر می‌شود و کیس‌های بین‌شعبه‌ای هم وجود دارند). در این حالت مقدار
// خالی ارسال می‌شود و چون ستون NOT NULL است، MySQL مقدارِ ضمنی صفر را می‌گذارد و
// ذخیره‌سازی با خطای نامفهومِ کلید خارجی شکست می‌خورد (1452 fk_cases_doctor).
// راه‌حل: مقدار خالی/نامعتبر → حفظ مقدار قبلیِ همان کیس (در ویرایش).
$idExistsIn = function (string $table, $value): bool {
    if (empty($value)) return false;
    $st = db()->prepare('SELECT 1 FROM ' . $table . ' WHERE id = ? LIMIT 1');
    $st->execute([(int) $value]);
    return (bool) $st->fetchColumn();
};

if (!$idExistsIn('users', $doctor_id)) {
    $doctor_id = ($existingCaseRow && !empty($existingCaseRow['doctor_id'])) ? (int) $existingCaseRow['doctor_id'] : null;
}
if (!$idExistsIn('site_prices', $service_id)) {
    $service_id = ($existingCaseRow && !empty($existingCaseRow['service_id'])) ? (int) $existingCaseRow['service_id'] : null;
}

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

if ($doctor_id === null) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'validation',
        'message' => 'انتخاب پزشک الزامی است.'
    ]);
    exit;
}

if ($service_id === null) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'validation',
        'message' => 'انتخاب خدمت الزامی است.'
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

    $uploadDir = ensure_uploads_dir('cases/' . $caseId) . '/';

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

        // Dedup display name: if a file with the same name already exists for this case, append _YYYYMMDD
        $displayName = uniqueCaseFileName($caseId, $orig);

        $safe = bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $uploadDir . $safe;

        if (move_uploaded_file($tmp, $dest)) {
            @chmod($dest, 0644);
            try {
                $ins = db()->prepare(
                    'INSERT INTO case_files (case_id, filename, original_name, mime, size, created_at) VALUES (?, ?, ?, ?, ?, NOW())'
                );
                $ins->execute([$caseId, $safe, $displayName, $mime, $size]);
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
        $sql = 'UPDATE cases SET doctor_id = ?, patient_name = ?, receipt_number = ?, service_id = ?, location_type = ?, teeth = ?, shade = ?, quantity = ?, unit_price = ?, total_price = ?, design_fee = ?, received_date = ?, status_id = ?, lab_id = ?, case_type = ?, designer_id = ?, outsourced_lab_id = ?, outsourced_service_id = ?, outsourced_qty = ?, outsourced_rate = ?, description = ?, parent_id = ?, source_branch_id = ?, updated_at = NOW() WHERE id = ?';
        $params = [$doctor_id, $patient_name, $receipt_number, $service_id, $location_type, $teeth, $shade, $quantity, $unit_price, $total_price, $design_fee, $received_date, $status_id, $lab_id, $case_type, $designer_id, $outsourced_lab_id, $outsourced_service_id, $outsourced_qty, $outsourced_rate, $description, $parent_id, $source_branch_id, $id];
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $caseId = $id;
    } else {
        $sql = 'INSERT INTO cases (parent_id, doctor_id, patient_name, receipt_number, service_id, location_type, teeth, shade, quantity, unit_price, total_price, design_fee, received_date, status_id, lab_id, case_type, designer_id, outsourced_lab_id, outsourced_service_id, outsourced_qty, outsourced_rate, description, branch_id, source_branch_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';
        $params = [$parent_id, $doctor_id, $patient_name, $receipt_number, $service_id, $location_type, $teeth, $shade, $quantity, $unit_price, $total_price, $design_fee, $received_date, $status_id, $lab_id, $case_type, $designer_id, $outsourced_lab_id, $outsourced_service_id, $outsourced_qty, $outsourced_rate, $description, $branch_id, $source_branch_id];
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $caseId = (int) db()->lastInsertId();
    }

    log_case_activity($caseId, $id ? 'update' : 'create', $id ? 'ویرایش کیس' : 'ایجاد کیس');

    // Handle file uploads
    $uploadErrors = [];
    if (!empty($_FILES['case_files'])) {
        $uploadErrors = handleCaseFileUploads($caseId, $_FILES['case_files']);
        if (empty($uploadErrors)) log_case_activity($caseId, 'file_upload', 'آپلود فایل هنگام ذخیره کیس');
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
