<?php
// panel/append_doctor_note.php
// افزودن متن یک کامنت (یا توضیح فایل) به «یادداشت پزشک» همان کیس.
// مجاز: مدیر کل/مدیر شعبه، طراح و تکنسین.
require_once __DIR__ . '/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'method']);
    exit;
}

$sessionToken = $_SESSION['_csrf_token'] ?? '';
$token = $_POST['_csrf_token'] ?? '';
if ($token === '') $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($token === '' || empty($sessionToken) || !hash_equals($sessionToken, $token)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'csrf_invalid']);
    exit;
}

$user = current_user();
$role = $user['role'] ?? '';
$allowed = is_admin() || in_array($role, ['designer', 'technician'], true);
if (!$allowed) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'forbidden']);
    exit;
}

$caseId = (int) ($_POST['case_id'] ?? 0);
$text = trim((string) ($_POST['text'] ?? ''));
if ($caseId <= 0 || $text === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'invalid']);
    exit;
}
if (!userCanViewCaseId($caseId, $user)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'case_forbidden']);
    exit;
}

$st = db()->prepare('SELECT doctor_id FROM cases WHERE id = ? LIMIT 1');
$st->execute([$caseId]);
$doctorId = (int) $st->fetchColumn();
if ($doctorId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'no_doctor']);
    exit;
}

$st2 = db()->prepare('SELECT notes FROM users WHERE id = ? LIMIT 1');
$st2->execute([$doctorId]);
$notes = (string) $st2->fetchColumn();

$author = trim((string) ($user['full_name'] ?? 'کاربر'));
$stamp = toJalaliDateFormatted(date('Y-m-d'));
$line = '[' . $stamp . ' - ' . $author . '] ' . $text;
$newNotes = $notes !== '' ? ($notes . "\n" . $line) : $line;

db()->prepare('UPDATE users SET notes = ? WHERE id = ?')->execute([$newNotes, $doctorId]);
log_case_activity($caseId, 'note_append', mb_substr($text, 0, 150));

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'case_id' => $caseId], JSON_UNESCAPED_UNICODE);
