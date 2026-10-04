<?php
// panel/save_my_profile.php
// ذخیرهٔ پروفایلِ خودِ کاربر (نام، نام کاربری، ایمیل، تلفن، رمز عبور).
// دسترسی: کاربر فقط پروفایل خودش؛ مدیر می‌تواند پروفایل پزشکان را هم ویرایش کند.

require_once __DIR__ . '/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_profile.php');
    exit;
}

$user = current_user();
$canManageOthers = has_role('admin') || is_admin();
$targetId = !empty($_POST['id']) ? (int) $_POST['id'] : (int) $user['id'];

if ($targetId !== (int) $user['id'] && !$canManageOthers) {
    http_response_code(403);
    die('دسترسی غیرمجاز — فقط می‌توانید پروفایل خودتان را ویرایش کنید.');
}

// CSRF (به صفحهٔ پروفایل برمی‌گردانیم تا پیام خطا دیده شود)
$sessionToken = $_SESSION['_csrf_token'] ?? '';
$token = $_POST['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($sessionToken) || !hash_equals($sessionToken, (string) $token)) {
    header('Location: my_profile.php?id=' . $targetId . '&error=csrf');
    exit;
}

$stmt = db()->prepare('SELECT id, username, password_hash, role FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$targetId]);
$profile = $stmt->fetch();
if (!$profile) {
    header('Location: my_profile.php?error=notfound');
    exit;
}

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$username = trim((string) ($_POST['username'] ?? ''));
$email    = trim((string) ($_POST['email'] ?? ''));
$phone    = trim((string) ($_POST['phone'] ?? ''));
$pass1    = (string) ($_POST['new_password'] ?? '');
$pass2    = (string) ($_POST['confirm_password'] ?? '');

$back = 'my_profile.php?id=' . $targetId . '&error=';

if ($fullName === '') { header('Location: ' . $back . 'missing_name'); exit; }
if ($username === '') { header('Location: ' . $back . 'missing_login'); exit; }

// یگانگی: فقط «نام کاربری» باید یکتا باشد (کلید ورود است).
// ایمیل/تلفن عمداً یکتا اجباری نیست: پزشک و کلینیکِ خودش (دو حساب جدا) می‌توانند
// یک شماره/ایمیل مشترک داشته باشند و نباید ذخیره را ببندد.
$dupe = function (string $column, string $value) use ($targetId) {
    if ($value === '') return false;
    $st = db()->prepare("SELECT 1 FROM users WHERE {$column} = ? AND id <> ? LIMIT 1");
    $st->execute([$value, $targetId]);
    return (bool) $st->fetchColumn();
};
if ($dupe('username', $username)) { header('Location: ' . $back . 'dup_username'); exit; }

// رمز عبور (اختیاری)
$newHash = null;
if ($pass1 !== '' || $pass2 !== '') {
    if (mb_strlen($pass1) < 6) { header('Location: ' . $back . 'short_password'); exit; }
    if ($pass1 !== $pass2) { header('Location: ' . $back . 'password_mismatch'); exit; }
    $newHash = password_hash($pass1, PASSWORD_DEFAULT);
}

if ($newHash !== null) {
    $upd = db()->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, phone = ?, password_hash = ?, updated_at = NOW() WHERE id = ?');
    $upd->execute([$fullName, $username, $email !== '' ? $email : null, $phone !== '' ? $phone : null, $newHash, $targetId]);
} else {
    $upd = db()->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, phone = ?, updated_at = NOW() WHERE id = ?');
    $upd->execute([$fullName, $username, $email !== '' ? $email : null, $phone !== '' ? $phone : null, $targetId]);
}
audit_log_save('user', $targetId, 'ویرایش پروفایل');

// اگر خود کاربر نام/تلفنش را عوض کرده، current_user() کش‌شده در همین درخواست مهم نیست
// (در درخواست بعدی از دیتابیس خوانده می‌شود). همین‌طور شهودِ رمز: session معتبر می‌ماند.
header('Location: my_profile.php?id=' . $targetId . '&ok=' . ($newHash !== null ? 'password' : 'saved'));
exit;
