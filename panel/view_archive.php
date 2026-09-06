<?php
// panel/view_archive.php
// Lists the contents of an uploaded .zip/.rar archive for a case file.
// Returns JSON: { success, filename, entries: [{name,size,is_dir}], rar_supported, message }
require_once __DIR__ . '/auth.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT f.*, c.designer_id AS case_designer_id, c.doctor_id AS case_doctor_id, c.lab_id AS case_lab_id FROM case_files f LEFT JOIN cases c ON f.case_id = c.id WHERE f.id = ?');
$stmt->execute([$id]);
$file = $stmt->fetch();

if (!$file) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'فایل یافت نشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Access scope check (mirrors view_case.php scoping)
$user = current_user();
$canAccess = false;
if (has_permission('view_all_cases') || has_permission('edit_cases')) {
    $canAccess = true;
} elseif ($user['role'] === 'doctor') {
    $canAccess = ((int) $file['case_doctor_id'] === (int) $user['id']) || canAccessDoctor($user['id']);
} elseif ($user['role'] === 'designer') {
    $canAccess = designerCanAccessUser($user['id'], (int) $file['case_doctor_id']) && (int) $file['case_designer_id'] === (int) $user['id'];
} elseif (in_array($user['role'] ?? '', ['lab', 'outsource_lab', 'customer_lab', 'partner_lab'], true)) {
    $canAccess = ((int) $file['case_lab_id'] === (int) $user['id']);
} elseif (has_permission('view_clinic_cases')) {
    $clinicScope = getClinicScope('c');
    $q = db()->prepare('SELECT COUNT(*) FROM cases c WHERE c.id = ? AND ' . $clinicScope['sql']);
    $q->execute(array_merge([$file['case_id']], $clinicScope['params']));
    $canAccess = ((int) $q->fetchColumn() > 0);
}

if (!$canAccess) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$path = resolve_upload_path('cases/' . (int) $file['case_id'] . '/' . $file['filename']);
$ext = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));

if (!file_exists($path)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'فایل روی سرور موجود نیست.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$entries = [];
$message = '';
$rarSupported = false;

try {
    if ($ext === 'zip') {
        $zip = new ZipArchive();
        $res = $zip->open($path);
        if ($res === true) {
            $count = $zip->numFiles;
            for ($i = 0; $i < $count; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) continue;
                $stat = $zip->statIndex($i);
                $isDir = substr($name, -1) === '/' || ($stat && ($stat['size'] === 0) && substr($name, -1) === '/');
                $entries[] = [
                    'name' => $name,
                    'size' => (int) ($stat['size'] ?? 0),
                    'is_dir' => (bool) $isDir,
                ];
            }
            $zip->close();
        } else {
            $message = 'فایل ZIP قابل خواندن نیست (کد خطا: ' . $res . ').';
        }
    } elseif ($ext === 'rar') {
        $rarSupported = class_exists('RarArchive');
        if ($rarSupported) {
            $rar = RarArchive::open($path);
            if ($rar) {
                $list = $rar->getEntries();
                if ($list !== false) {
                    foreach ($list as $entry) {
                        $entries[] = [
                            'name' => $entry->getName(),
                            'size' => (int) $entry->getUnpackedSize(),
                            'is_dir' => $entry->isDirectory(),
                        ];
                    }
                }
                $rar->close();
            } else {
                $message = 'فایل RAR قابل خواندن نیست.';
            }
        } else {
            $message = 'پسوند RAR روی این سرور نصب نیست؛ برای مشاهده محتویات، فایل را دانلود کنید.';
        }
    } else {
        $message = 'این فایل یک آرشیو ZIP یا RAR نیست.';
    }
} catch (Throwable $e) {
    $message = 'خطا در خواندن آرشیو: ' . $e->getMessage();
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => empty($message),
    'filename' => $file['original_name'],
    'entries' => $entries,
    'rar_supported' => $rarSupported,
    'message' => $message,
], JSON_UNESCAPED_UNICODE);
