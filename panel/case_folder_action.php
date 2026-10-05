<?php
/**
 * panel/case_folder_action.php
 *
 * مدیریت پوشه‌های فایلِ یک کیس — به سبک Windows Explorer / cPanel.
 *
 * مفهوم پوشه در این پروژه:
 *   پوشه‌ها «مجازی» هستند و از بخش‌های `case_files.rel_path` ساخته می‌شوند.
 *   یعنی پوشه‌ای که فایل ندارد، در واقعیت وجود ندارد (فایل‌ها تخت روی دیسک
 *   ذخیره می‌شوند). برای اینکه کاربر بتواند مثل ویندوز «پوشهٔ خالی» بسازد و
 *   بعد داخلش فایل بریزد، یک ردیفِ نگهدارنده (placeholder) می‌سازیم که
 *   `rel_path = '<folder>/.keep'` دارد. این ردیف در نمایش پوشه شمرده نمی‌شود
 *   و به‌عنوان فایل هم نشان داده نمی‌شود.
 *
 * عملیات (POST با فیلد action):
 *   create — ساخت پوشه (در ریشه یا داخل پوشهٔ دیگر با parent)
 *   rename — تغییر نام پوشه (کل زیردرخت منتقل می‌شود)
 *   delete — حذف پوشه، زیرپوشه‌ها و همهٔ فایل‌هایش
 *
 * ورودی: case_id، action، folder (مسیر نسبی)، name/parent (برای create)، new_name (برای rename)
 * خروجی: JSON {success:bool, error?:string, ...}
 *
 * دسترسی: مدیر، یا دارندهٔ edit_cases / upload_files که کیس را می‌بیند.
 */
require_once __DIR__ . '/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── CSRF ───
$token = (string) ($_POST['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (empty($_SESSION['_csrf_token']) || !hash_equals((string) $_SESSION['_csrf_token'], $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user   = current_user();
$caseId = !empty($_POST['case_id']) ? (int) $_POST['case_id'] : 0;
$action = (string) ($_POST['action'] ?? '');

if ($caseId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_case'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── دسترسی ───
$canManage = is_admin()
    || has_permission('edit_cases')
    || has_permission('upload_files')
    || has_permission('upload_design_files');
if (!$canManage) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (function_exists('userCanViewCaseId') && !userCanViewCaseId($caseId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'no_case_access'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** پاسخِ خطا و خروج. */
function folderError(string $code, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $code], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * اعتبارسنجی نام یک سگمنتِ پوشه (بدون اسلش).
 * @return string|null نامِ پاک یا null اگر نامعتبر باشد.
 */
function cleanFolderSegment(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;
    // نام باید تک‌سگمنت باشد: اسلش و بک‌اسلش مجاز نیست
    if (strpbrk($raw, "/\\") !== false) return null;
    $name = sanitizeRelPath($raw);
    if ($name === null || $name === '') return null;
    if (strpos($name, '/') !== false) return null;
    if (mb_strlen($name) > 120) return null;
    return $name;
}

/** آیا پوشه (یا زیرپوشه‌ای از آن) در این کیس وجود دارد؟ */
function folderExists(int $caseId, string $folder): bool {
    $st = db()->prepare('SELECT 1 FROM case_files WHERE case_id = ? AND (rel_path = ? OR rel_path LIKE ?) LIMIT 1');
    $st->execute([$caseId, $folder, $folder . '/%']);
    return (bool) $st->fetchColumn();
}

$pdo = db();

switch ($action) {

    // ══════════════ ساخت پوشه ══════════════
    case 'create': {
        $parent = sanitizeRelPath((string) ($_POST['parent'] ?? ''));
        $name   = cleanFolderSegment((string) ($_POST['name'] ?? ''));
        if ($name === null) folderError('bad_name');

        $folder = ($parent !== null) ? ($parent . '/' . $name) : $name;

        if ($parent !== null && !folderExists($caseId, $parent)) {
            folderError('parent_not_found');
        }
        if (folderExists($caseId, $folder)) {
            folderError('duplicate');
        }

        // ردیفِ نگهدارنده: پوشهٔ خالی را زنده نگه می‌دارد.
        // نام روی دیسک تصادفی/ساختگی است و هرگز سرو نمی‌شود.
        $st = $pdo->prepare(
            'INSERT INTO case_files
                (case_id, filename, original_name, rel_path, description, file_type, mime, size, uploader_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $st->execute([
            $caseId,
            '.keep-empty-folder',
            '.keep-empty-folder',
            $folder . '/.keep',
            'پوشهٔ خالی — نگهدارنده',
            'other',
            'application/x-empty-folder',
            0,
            (int) $user['id'],
        ]);

        log_case_activity($caseId, 'folder_create', 'ساخت پوشه: ' . $folder);

        echo json_encode([
            'success' => true,
            'folder'  => $folder,
            'name'    => $name,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ══════════════ تغییر نام پوشه ══════════════
    case 'rename': {
        $folder = sanitizeRelPath((string) ($_POST['folder'] ?? ''));
        if ($folder === null || $folder === '') folderError('invalid_folder');

        $newSeg = cleanFolderSegment((string) ($_POST['new_name'] ?? ''));
        if ($newSeg === null) folderError('bad_name');

        // والدِ پوشه حفظ می‌شود: a/b/c  →  a/b/<new>
        $slash      = strrpos($folder, '/');
        $parentPath = ($slash === false) ? '' : substr($folder, 0, $slash);
        $oldName    = ($slash === false) ? $folder : substr($folder, $slash + 1);

        if ($newSeg === $oldName) {
            echo json_encode(['success' => true, 'folder' => $folder, 'unchanged' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $newFolder = ($parentPath === '') ? $newSeg : ($parentPath . '/' . $newSeg);

        if (!folderExists($caseId, $folder)) folderError('not_found', 404);
        if (folderExists($caseId, $newFolder)) folderError('duplicate');

        $st = $pdo->prepare(
            'SELECT id, rel_path FROM case_files
             WHERE case_id = ? AND (rel_path = ? OR rel_path LIKE ?)'
        );
        $st->execute([$caseId, $folder, $folder . '/%']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) folderError('not_found', 404);

        $upd = $pdo->prepare('UPDATE case_files SET rel_path = ? WHERE id = ?');
        $moved = 0;
        $pdo->beginTransaction();
        try {
            foreach ($rows as $r) {
                $rel  = (string) $r['rel_path'];
                $rest = ($rel === $folder) ? '' : substr($rel, strlen($folder) + 1);
                $newRel = ($rest === '') ? $newFolder : ($newFolder . '/' . $rest);
                $upd->execute([$newRel, (int) $r['id']]);
                $moved++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            folderError('rename_failed', 500);
        }

        log_case_activity($caseId, 'folder_rename', 'تغییر نام پوشه: ' . $folder . ' → ' . $newFolder);

        echo json_encode([
            'success' => true,
            'folder'  => $newFolder,
            'moved'   => $moved,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ══════════════ حذف پوشه ══════════════
    case 'delete': {
        $folder = sanitizeRelPath((string) ($_POST['folder'] ?? ''));
        if ($folder === null || $folder === '') folderError('invalid_folder');
        if (!folderExists($caseId, $folder)) folderError('not_found', 404);

        $st = $pdo->prepare(
            'SELECT id, filename, rel_path FROM case_files
             WHERE case_id = ? AND (rel_path = ? OR rel_path LIKE ?)'
        );
        $st->execute([$caseId, $folder, $folder . '/%']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $deletedRows = 0;
        $deletedFiles = 0;
        $failedFiles = 0;

        $caseDir = function_exists('uploads_path')
            ? uploads_path('cases/' . $caseId)
            : (__DIR__ . '/../uploads/cases/' . $caseId);

        $del = $pdo->prepare('DELETE FROM case_files WHERE id = ?');
        $pdo->beginTransaction();
        try {
            foreach ($rows as $r) {
                $rel    = (string) $r['rel_path'];
                $isKeep = (substr($rel, -5) === '.keep');

                if (!$isKeep) {
                    $abs = rtrim((string) $caseDir, '/\\') . DIRECTORY_SEPARATOR . basename((string) $r['filename']);
                    if (is_file($abs)) {
                        if (@unlink($abs)) { $deletedFiles++; } else { $failedFiles++; }
                    }
                }
                $del->execute([(int) $r['id']]);
                $deletedRows++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            folderError('delete_failed', 500);
        }

        // پیوندهای فایل‌های حذف‌شده (جدول اختیاری است)
        try {
            $pdo->prepare('DELETE FROM case_file_case_links WHERE file_id NOT IN (SELECT id FROM case_files)')->execute();
        } catch (Throwable $e) { /* اختیاری */ }

        log_case_activity($caseId, 'folder_delete', 'حذف پوشه: ' . $folder . ' (' . $deletedRows . ' ردیف)');

        echo json_encode([
            'success'       => true,
            'deleted_rows'  => $deletedRows,
            'deleted_files' => $deletedFiles,
            'failed_files'  => $failedFiles,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    default:
        folderError('unknown_action');
}
