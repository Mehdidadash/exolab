<?php
// panel/delete_aligner_delivery.php — حذف یک تحویلِ مرحلهٔ الاینر (تا وقتی فاکتور نشده)
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('روش درخواست مجاز نیست.');
}

$sessionToken = $_SESSION['_csrf_token'] ?? '';
$token = $_POST['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
    http_response_code(403);
    die('توکن امنیتی نامعتبر است.');
}

$id = !empty($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) { http_response_code(400); die('شناسه مشخص نشده.'); }

$st = db()->prepare('SELECT * FROM case_aligner_deliveries WHERE id = ? LIMIT 1');
$st->execute([$id]);
$d = $st->fetch();
if (!$d) { http_response_code(404); die('تحویل یافت نشد.'); }

$caseId = (int) $d['case_id'];
$canManage = is_admin() || has_permission('edit_cases') || has_permission('update_case_status');
if (!$canManage || !userCanViewCaseId($caseId, $user)) {
    http_response_code(403);
    die('دسترسی غیرمجاز.');
}

$back = 'view_case.php?id=' . $caseId . '#aligner';
$sep = (strpos($back, '?') !== false) ? '&' : '?';

if (!empty($d['invoice_id'])) {
    // تحویلِ فاکتورشده نباید بی‌سر‌و‌صدا حذف شود
    header('Location: ' . $back . $sep . 'error=' . rawurlencode('این تحویل در فاکتور #' . (int) $d['invoice_id'] . ' آمده است؛ اول باید از فاکتور خارج شود.'));
    exit;
}

try {
    db()->prepare('DELETE FROM case_aligner_deliveries WHERE id = ?')->execute([$id]);
    log_case_activity($caseId, 'aligner_delivery_delete', json_encode(['id' => $id, 'date' => $d['delivered_date']], JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    header('Location: ' . $back . $sep . 'error=' . rawurlencode('حذف انجام نشد.'));
    exit;
}

header('Location: ' . $back . $sep . 'msg=' . rawurlencode('تحویل حذف شد.'));
exit;
